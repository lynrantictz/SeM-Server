<?php

namespace App\Repositories\Business;

use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Repositories\BaseRepository;
use App\Services\OrderPrefixService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BusinessRepository extends BaseRepository
{
    const MODEL = Business::class;

    public function __construct(protected OrderPrefixService $prefixService) {}

    public function getQuery(array $filters = [])
    {
        return $this->query()
            ->with('type', 'contacts', 'vendor', 'district.region.country')
            ->when((is_owner() || is_vendor()), function ($query) {
                $query->join('vendors', 'vendors.id', '=', 'businesses.vendor_id')
                    ->join('vendor_user', 'vendor_user.vendor_id', '=', 'vendors.id')
                    ->where('vendor_user.user_id', auth()->id());
            })
            ->when($filters['search'] ?? null, function ($query, $search) {
                $keyword = ucwords(trim($search));
                $query->where('businesses.name', 'like', '%' . $keyword . '%')
                    ->orWhere('businesses.location', 'like', '%' . $keyword . '%')
                    ->orWhere('businesses.tin', 'like', '%' . $keyword . '%');
            });
    }

    public function store(Vendor $vendor, array $inputs)
    {
        return DB::transaction(function () use ($vendor, $inputs) {
            // Generate unique order prefix from business name
            $inputs['order_prefix'] = $this->prefixService->generate($inputs['name']);

            $business = $vendor->businesses()->create(Arr::except($inputs, ['contacts']));

            // contacts is an array of ['contact' => '...'] objects
            if (!empty($inputs['contacts'])) {
                $business->contacts()->createMany($inputs['contacts']);
            }

            return $business->load('contacts');
        });
    }

    public function update(Business $business, array $inputs)
    {
        return DB::transaction(function () use ($business, $inputs) {
            return $business->update($inputs);
        });
    }
}
