<?php

namespace App\Http\Controllers\Api\V1\Discovery;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use Illuminate\Http\Request;

class DiscoveryController extends BaseController
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
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
                    ->map(fn ($item) => ['name' => $item->name, 'price' => (float) $item->final_price])
                    ->values(),
                'search_matches' => $searchMatches,
                'distance_km' => $business->distance_km ? round((float) $business->distance_km, 1) : null,
            ];
        });

        return $this->sendResponse(['businesses' => $businesses], 'Discoverable businesses retrieved successfully.');
    }
}
