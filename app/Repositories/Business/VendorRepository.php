<?php

namespace App\Repositories\Business;

use App\Models\Business\Vendor;
use App\Repositories\Auth\UserRepository;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VendorRepository extends BaseRepository
{
    const MODEL = Vendor::class;

    public function getQuery()
    {
        return $this->query()->select([
            'vendors.*'
        ])
            ->with('country', 'businesses')
            ->leftJoin('vendor_user', 'vendor_user.vendor_id', 'vendors.id');
    }

    public function getAll()
    {
        return $this->getQuery();
    }

    public function getAllAccess()
    {
        return $this->getAll()
            ->where('vendor_user.user_id', auth()->id());
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
