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
}
