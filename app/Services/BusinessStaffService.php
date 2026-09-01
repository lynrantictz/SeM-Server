<?php

namespace App\Services;

use App\Enums\User\UserType;
use App\Models\Auth\User;
use App\Models\Business\Business;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class BusinessStaffService
{
    /**
     * Provision a business-operated account. Its initial password is its code
     * and must be replaced after the first sign-in.
     */
    public function create(Business $business, array $attributes): User
    {
        return DB::transaction(function () use ($business, $attributes) {
            $lockedBusiness = Business::query()->lockForUpdate()->findOrFail($business->id);
            $nextNumber = $lockedBusiness->next_staff_number;

            do {
                $code = sprintf('%s-%03d', $lockedBusiness->code_prefix, $nextNumber++);
            } while (User::where('code', $code)->exists());

            $lockedBusiness->update(['next_staff_number' => $nextNumber]);

            $user = User::create([
                'name' => $attributes['name'],
                'phone' => $attributes['phone'] ?? null,
                'code' => $code,
                'password' => $code,
                'must_change_password' => true,
                'type' => UserType::BUSINESS->value,
                'is_active' => true,
            ]);

            $user->businesses()->attach($lockedBusiness->id, [
                'title' => $attributes['title'] ?? strtolower(str_replace(' ', '_', $attributes['business_role'])),
                'business_role' => $attributes['business_role'],
                'business_staff_role_id' => $attributes['business_staff_role_id'],
                'is_active' => true,
                'activated_at' => now(),
                'deactivated_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionRole = match ($attributes['business_role']) {
                'business_manager' => 'manager',
                'counter' => 'counter-clerk',
                'waiter' => 'waiter',
                default => null,
            };

            if ($permissionRole && Role::query()
                ->where('name', $permissionRole)
                ->where('guard_name', 'api')
                ->exists()) {
                $user->syncRoles([$permissionRole]);
            }

            return $user;
        });
    }
}
