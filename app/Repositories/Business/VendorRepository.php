<?php

namespace App\Repositories\Business;

use App\Models\Business\Vendor;
use App\Models\Business\Business;
use App\Repositories\Auth\UserRepository;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VendorRepository extends BaseRepository
{
    const MODEL = Vendor::class;

    public function getQuery(array $filters = [])
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return $this->query()
            ->select([
                'vendors.id',
                'vendors.uuid',
                'vendors.country_id',
                'vendors.name',
                'vendors.tin',
                'vendors.email',
                'vendors.phone',
                'vendors.address',
                'vendors.created_at',
                'vendor_user.is_primary',
            ])
            ->with('country:id,name,iso2,phone_code,flag')
            ->withCount('businesses')
            ->join('vendor_user', 'vendor_user.vendor_id', '=', 'vendors.id')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';

                $query->where(function ($searchQuery) use ($term) {
                    $searchQuery
                        ->whereRaw('LOWER(vendors.name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(vendors.email) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(vendors.tin) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(vendors.phone) LIKE ?', [$term]);
                });
            });
    }

    public function getAll()
    {
        return $this->getQuery();
    }

    public function getAllAccess(array $filters = [])
    {
        return $this->getQuery($filters)
            ->where('vendor_user.user_id', auth()->id());
    }

    public function getBusinessesQuery(Vendor $vendor, array $filters = [])
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return Business::query()
            ->select([
                'businesses.id',
                'businesses.uuid',
                'businesses.vendor_id',
                'businesses.district_id',
                'businesses.business_type_id',
                'businesses.name',
                'businesses.tin',
                'businesses.location',
                'businesses.order_prefix',
                'businesses.is_active',
                'businesses.tax_allowed',
                'businesses.created_at',
            ])
            ->with([
                'type:id,name',
                'contacts:id,business_id,contact,is_active',
                'district:id,city_id,name',
                'district.city:id,country_id,name',
                'district.city.country:id,name,iso2,phone_code,flag',
            ])
            ->where('businesses.vendor_id', $vendor->id)
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';

                $query->where(function ($searchQuery) use ($term) {
                    $searchQuery
                        ->whereRaw('LOWER(businesses.name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(businesses.tin) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(businesses.location) LIKE ?', [$term])
                        ->orWhereHas('type', fn ($typeQuery) => $typeQuery->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('district', fn ($districtQuery) => $districtQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereHas('city', fn ($cityQuery) => $cityQuery->whereRaw('LOWER(name) LIKE ?', [$term])));
                });
            });
    }

    /**
     * Store new Vendor
     * @param $inputs
     * @return Vendor
     */
    public function store($inputs): Vendor
    {
        return DB::transaction(function () use ($inputs) {
            $vendor = auth()->user()->vendors()->create($inputs);
            auth()->user()->vendors()->updateExistingPivot($vendor->id, [
                'is_primary' => true
            ]);
            return $vendor;
        });
    }

    public function update(Vendor $vendor, $inputs)
    {
        return DB::transaction(function () use ($vendor, $inputs) {
            return $vendor->update($inputs);
        });
    }
}
