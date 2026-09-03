<?php

namespace App\Repositories\Business;

use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Models\Location\District;
use App\Repositories\BaseRepository;
use App\Services\OrderPrefixService;
use App\Services\PhoneNumberNormalizer;
use App\Services\StaffCodePrefixService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BusinessRepository extends BaseRepository
{
    const MODEL = Business::class;

    public function __construct(
        protected OrderPrefixService $prefixService,
        protected StaffCodePrefixService $staffCodePrefixService,
    ) {}

    public function getQuery(array $filters = [])
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return $this->query()
            ->select([
                'businesses.id',
                'businesses.uuid',
                'businesses.vendor_id',
                'businesses.district_id',
                'businesses.business_type_id',
                'businesses.tin',
                'businesses.name',
                'businesses.location',
                'businesses.google_location',
                'businesses.logo_disk',
                'businesses.logo_path',
                'businesses.logo_mime_type',
                'businesses.logo_updated_at',
                'businesses.latitude',
                'businesses.longitude',
                'businesses.order_prefix',
                'businesses.code_prefix',
                'businesses.current_order_number',
                'businesses.is_active',
                'businesses.tax_allowed',
                'businesses.created_at',
            ])
            ->with([
                'type:id,name',
                'contacts:id,business_id,contact,is_active',
                'vendor:id,uuid,name',
                'district:id,city_id,name',
                'district.city:id,country_id,name',
                'district.city.country:id,name,iso2,phone_code,flag',
            ])
            ->withCount('categories')
            ->when((is_owner() || is_vendor()), function ($query) {
                $query->join('vendors', 'vendors.id', '=', 'businesses.vendor_id')
                    ->join('vendor_user', 'vendor_user.vendor_id', '=', 'vendors.id')
                    ->where('vendor_user.user_id', auth()->id())
                    ->where(function ($membershipQuery) {
                        $membershipQuery
                            ->where('vendor_user.is_primary', true)
                            ->orWhere('vendor_user.is_active', true);
                    })
                    ->where(function ($scopeQuery) {
                        $scopeQuery
                            ->where('vendor_user.is_primary', true)
                            ->orWhere('vendor_user.access_scope', 'all_businesses')
                            ->orWhereIn('businesses.id', auth()->user()->businesses()
                                ->wherePivot('is_active', true)
                                ->select('businesses.id'));
                    });
            })
            ->when(is_business(), function ($query) {
                $query->whereIn('businesses.id', auth()->user()->businesses()
                    ->wherePivot('is_active', true)
                    ->select('businesses.id'));
            })
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';

                $query->where(function ($searchQuery) use ($term) {
                    $searchQuery
                        ->whereRaw('LOWER(businesses.name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(businesses.tin) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(businesses.location) LIKE ?', [$term])
                        ->orWhereHas('vendor', fn ($vendorQuery) => $vendorQuery->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('type', fn ($typeQuery) => $typeQuery->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('contacts', fn ($contactQuery) => $contactQuery->where('contact', 'like', $term))
                        ->orWhereHas('district', fn ($districtQuery) => $districtQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereHas('city', fn ($cityQuery) => $cityQuery
                                ->whereRaw('LOWER(name) LIKE ?', [$term])
                                ->orWhereHas('country', fn ($countryQuery) => $countryQuery->whereRaw('LOWER(name) LIKE ?', [$term]))));
                });
            });
    }

    public function store(Vendor $vendor, array $inputs)
    {
        return DB::transaction(function () use ($vendor, $inputs) {
            // Orders and staff login codes use deliberately separate namespaces.
            $inputs['order_prefix'] = $this->prefixService->generate($inputs['name']);
            $inputs['code_prefix'] = $this->staffCodePrefixService->generate($inputs['name']);

            $business = $vendor->businesses()->create(Arr::except($inputs, ['contacts']));

            // contacts is an array of ['contact' => '...'] objects
            if (!empty($inputs['contacts'])) {
                $business->contacts()->createMany($this->normalizeContacts($inputs['contacts'], $business->district_id));
            }

            return $business->load('contacts');
        });
    }

    public function update(Business $business, array $inputs)
    {
        return DB::transaction(function () use ($business, $inputs) {
            $business->update(Arr::except($inputs, ['contacts']));
            if (array_key_exists('contacts', $inputs)) {
                $business->contacts()->delete();
                $business->contacts()->createMany($this->normalizeContacts($inputs['contacts'], $business->district_id));
            }
            return $business;
        });
    }

    private function normalizeContacts(array $contacts, int $districtId): array
    {
        $district = District::query()->with('city.country')->findOrFail($districtId);
        $country = $district->city?->country?->iso2;
        if (!$country) {
            throw new \InvalidArgumentException('The selected district must belong to a country.');
        }
        $normalizer = new PhoneNumberNormalizer();

        return collect($contacts)
            ->map(function (array $contact) use ($normalizer, $country) {
                $canonical = $normalizer->normalize($contact['contact'] ?? null, $country);

                return ['contact' => $canonical];
            })
            ->values()
            ->all();
    }
}
