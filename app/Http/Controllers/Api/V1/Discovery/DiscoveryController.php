<?php

namespace App\Http\Controllers\Api\V1\Discovery;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\OrderingChannel;
use App\Models\Business\Business;
use App\Models\Discovery\DiscoveryInteraction;
use App\Services\MenuAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DiscoveryController extends BaseController
{
    public function menu(Request $request, Business $business)
    {
        abort_unless($business->is_active, HTTP_NOT_FOUND, 'This business is not available for discovery.');

        $channel = $request->query('channel', 'dine_in');
        abort_unless(in_array($channel, OrderingChannel::activeSlugs(), true), HTTP_UNPROCESSABLE_ENTITY, 'The ordering channel is invalid.');

        $business->loadMissing('district.city.country');
        $availability = app(MenuAvailabilityService::class);
        $menu = $business->categories()->with([
            'availabilityRules.days',
            'items' => function ($query): void {
                $query->where('is_active', true)->where('is_sold_out', false)
                    ->with([
                        'availabilityRules.days',
                        'optionGroups' => fn ($groups) => $groups->where('is_active', true)->with(['options' => fn ($options) => $options->where('is_active', true)]),
                    ]);
            },
        ])
            ->where('is_active', true)
            ->orderBy('categories.name')
            ->get()
            ->map(function ($category) use ($availability, $business, $channel) {
                $categoryStatus = $availability->categoryStatus($category, $business, $channel);
                $category->setRelation('items', $category->items->filter(function ($item) use ($availability, $business, $channel) {
                    $status = $availability->itemStatus($item, $business, $channel);
                    $pricing = $availability->itemPricing($item, $business, $channel);
                    $item->setAttribute('discount', $pricing['discount_percentage']);
                    $item->setAttribute('discount_percentage', $pricing['discount_percentage']);
                    $item->setAttribute('final_price', $pricing['final_price']);
                    $item->setAttribute('discount_amount', $pricing['discount_amount']);
                    $item->setAttribute('availability', $status);

                    return $status['is_available_now'];
                })->values());
                $category->setAttribute('availability', $categoryStatus);

                return $category;
            })
            ->filter(fn ($category) => $category->getAttribute('availability')['is_available_now'] && $category->items->isNotEmpty())
            ->values();

        $country = $business->district?->city?->country;
        $vat = $business->tax_allowed && $business->tax_status === 'vat_registered'
            ? $country?->taxes()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->orderByDesc('effective_from')
                ->first()
            : null;

        return $this->sendResponse([
            'business' => [
                'uuid' => $business->uuid,
                'name' => $business->name,
                'logo' => $business->logo_url,
            ],
            'menu' => $menu,
            'pricing' => [
                'currency' => $country?->currency,
                'vat_applicable' => $vat !== null,
                'vat_rate' => $vat?->percent !== null ? (float) $vat->percent : null,
            ],
        ], 'Discovery menu retrieved successfully.');
    }

    public function trackInteraction(Request $request)
    {
        $validated = $request->validate([
            'business_uuid' => ['required', 'uuid'],
            'event_type' => ['required', 'string', 'in:tap,search,copy_location'],
        ]);

        $business = Business::query()
            ->where('uuid', $validated['business_uuid'])
            ->where('is_active', true)
            ->firstOrFail();

        DB::transaction(function () use ($request, $business, $validated): void {
            DiscoveryInteraction::query()->create([
                'business_id' => $business->id,
                'event_type' => $validated['event_type'],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);
        });

        return $this->sendResponse([], 'Discovery interaction recorded successfully.');
    }

    public function regions(Request $request)
    {
        $validated = $request->validate([
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
        ]);

        $regions = Business::query()
            ->join('districts', 'districts.id', '=', 'businesses.district_id')
            ->join('cities', 'cities.id', '=', 'districts.city_id')
            ->selectRaw('cities.id, cities.name, AVG(businesses.latitude) as latitude, AVG(businesses.longitude) as longitude')
            ->where('businesses.is_active', true)
            ->whereNotNull('businesses.latitude')
            ->whereNotNull('businesses.longitude')
            ->when($validated['country_id'] ?? null, fn ($query, $countryId) => $query->where('cities.country_id', $countryId))
            ->groupBy('cities.id', 'cities.name')
            ->orderBy('cities.name')
            ->get()
            ->map(fn ($region) => [
                'id' => (int) $region->id,
                'name' => $region->name,
                'latitude' => (float) $region->latitude,
                'longitude' => (float) $region->longitude,
            ]);

        return $this->sendResponse(['regions' => $regions], 'Discovery regions retrieved successfully.');
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'between:1,100'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Business::query()
            ->select('businesses.*')
            ->with([
                'type:id,name',
                'district:id,name,city_id',
                'district.city:id,name,country_id',
                'district.city.country:id,name',
                'categories:id,business_id,name,is_active',
                'categories.items:id,category_id,name,final_price,is_active',
            ])
            ->where('businesses.is_active', true)
            ->whereNotNull('businesses.latitude')
            ->whereNotNull('businesses.longitude')
            ->when($validated['q'] ?? null, function ($businesses, $search) {
                $pattern = '%' . mb_strtolower($search) . '%';
                $compactPattern = '%' . preg_replace('/\s+/', '', mb_strtolower($search)) . '%';

                $businesses->where(function ($match) use ($pattern, $compactPattern) {
                    $match->whereRaw('LOWER(businesses.name) LIKE ?', [$pattern])
                        ->orWhereRaw("REPLACE(LOWER(businesses.name), ' ', '') LIKE ?", [$compactPattern])
                        ->orWhereRaw('LOWER(businesses.location) LIKE ?', [$pattern])
                        ->orWhereHas('type', fn ($types) => $types->whereRaw('LOWER(name) LIKE ?', [$pattern]))
                        ->orWhereHas('categories', function ($categories) use ($pattern) {
                            $categories->whereRaw('LOWER(name) LIKE ?', [$pattern])
                                ->orWhereHas('items', fn ($items) => $items->whereRaw('LOWER(name) LIKE ?', [$pattern]));
                        });
                });
            })
            ->when($validated['type'] ?? null, function ($businesses, $type) {
                $businesses->whereHas('type', fn ($types) => $types->where('name', $type));
            })
            ->when($validated['country_id'] ?? null, function ($businesses, $countryId) {
                $businesses->whereHas('district.city', fn ($cities) => $cities->where('country_id', $countryId));
            })
            ->when($validated['city'] ?? null, function ($businesses, $city) {
                $businesses->whereHas('district.city', fn ($cities) => $cities->where('name', $city));
            })
            ->when($validated['district_id'] ?? null, fn ($businesses, $districtId) => $businesses->where('businesses.district_id', $districtId));

        if (isset($validated['lat'], $validated['lng'])) {
            $latitude = (float) $validated['lat'];
            $longitude = (float) $validated['lng'];
            $distance = '(6371 * acos(cos(radians(?)) * cos(radians(businesses.latitude)) * cos(radians(businesses.longitude) - radians(?)) + sin(radians(?)) * sin(radians(businesses.latitude))))';
            $query->selectRaw("{$distance} as distance_km", [$latitude, $longitude, $latitude]);
            if (isset($validated['radius'])) {
                $query->whereRaw("{$distance} <= ?", [$latitude, $longitude, $latitude, (float) $validated['radius']]);
            }
            $query->orderBy('distance_km');
        } else {
            $query->latest('businesses.created_at');
        }

        $businesses = $query->limit($validated['limit'] ?? 60)->get()->map(function (Business $business) use ($validated) {
            $categories = $business->categories->where('is_active', true)->values();
            $items = $categories->flatMap(fn ($category) => $category->items->where('is_active', true))->values();
            $searchTerm = isset($validated['q']) ? mb_strtolower($validated['q']) : null;
            $searchMatches = collect();

            if ($searchTerm) {
                $searchMatches = $categories
                    ->filter(fn ($category) => str_contains(mb_strtolower($category->name), $searchTerm))
                    ->map(fn ($category) => ['type' => 'category', 'name' => $category->name])
                    ->concat(
                        $items
                            ->filter(fn ($item) => str_contains(mb_strtolower($item->name), $searchTerm))
                            ->map(fn ($item) => ['type' => 'item', 'name' => $item->name])
                    )
                    ->values();
            }

            return [
                'uuid' => $business->uuid,
                'name' => $business->name,
                'type' => $business->type?->name,
                'location' => $business->location,
                'latitude' => (float) $business->latitude,
                'longitude' => (float) $business->longitude,
                'district' => $business->district?->name,
                'city' => $business->district?->city?->name,
                'country' => $business->district?->city?->country?->name,
                'image' => $business->image_url,
                'logo' => $business->logo_url,
                'rating' => $business->rating ? (float) $business->rating : null,
                'review_count' => $business->review_count,
                'opening_hours' => $business->opening_hours,
                'price_range' => $business->price_range,
                'short_description' => $business->discovery_description,
                'open_now' => true,
                'menu_categories' => $categories
                    ->pluck('name')
                    ->values(),
                'menu_preview' => $items
                    ->take(3)
                    ->map(function ($item) use ($categories) {
                        $category = $categories->first(fn ($candidate) => $candidate->items->contains('id', $item->id));

                        return [
                            'name' => $item->name,
                            'price' => (float) $item->final_price,
                            'category' => $category?->name,
                        ];
                    })
                    ->values(),
                'search_matches' => $searchMatches,
                'distance_km' => $business->distance_km ? round((float) $business->distance_km, 1) : null,
            ];
        });

        return $this->sendResponse(['businesses' => $businesses], 'Discoverable businesses retrieved successfully.');
    }
}
