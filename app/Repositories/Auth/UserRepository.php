<?php

namespace App\Repositories\Auth;

use App\Enums\User\UserType;
use App\Models\Auth\User;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class UserRepository extends BaseRepository
{
    const MODEL = User::class;


    public function store(array $inputs)
    {
        return DB::transaction(function () use ($inputs) {
            return $this->query()->create($inputs);
        });
    }

    public function registerVendorUser(array $inputs)
    {
        return DB::transaction(function () use ($inputs) {
            $inputs['is_active'] = false;
            $inputs['type'] = UserType::VENDOR->value;
            $user = $this->query()->create($inputs);
            // assign owner role
            $user->assignRole('owner');
            // Get owner role permissions
            $role = Role::where('name', 'owner')->firstOrFail();
            // Assign all owner permissions to user
            $user->givePermissionTo($role->permissions);
            return $user;
        });
    }
}
