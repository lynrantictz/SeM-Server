<?php

namespace App\Repositories\Business;

use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Repositories\BaseRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BusinessRepository extends BaseRepository
{
    const MODEL = Business::class;

    public function store(Vendor $vendor, array $inputs)
    {
        return DB::transaction(function () use ($vendor, $inputs) {
            $inputs['user_id'] = auth()->id();
            $business = $vendor->businesses()->create(Arr::except($inputs,['contacts']));
            $business->contacts()->createMany(Arr::only($inputs,['contacts']));
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
