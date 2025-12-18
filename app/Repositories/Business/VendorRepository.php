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
        $userInputs = [
            'email' => $inputs['email'],
            'phone' => $inputs['phone'],
            'name' => $inputs['name'],
            'password' => Hash::make($inputs['password']),
        ];
        return DB::transaction(function () use ($inputs, $userInputs) {
            $user = (new UserRepository())->store($userInputs);
            return $user->vendor()->create($inputs);
        });
    }
}
