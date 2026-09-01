<?php

namespace Database\Seeders;

use App\Models\Business\BusinessStaffRole;
use App\Models\Business\BusinessUser;
use Illuminate\Database\Seeder;

class BusinessStaffRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Business Manager', 'slug' => 'business_manager', 'description' => 'Manages daily operations for a business.', 'sort_order' => 10],
            ['name' => 'Counter Staff', 'slug' => 'counter', 'description' => 'Receives and manages guest orders at the counter.', 'sort_order' => 20],
            ['name' => 'Waiter', 'slug' => 'waiter', 'description' => 'Serves guests and manages table orders.', 'sort_order' => 30],
            ['name' => 'Kitchen Staff', 'slug' => 'kitchen', 'description' => 'Prepares and updates kitchen orders.', 'sort_order' => 40],
            ['name' => 'Chef', 'slug' => 'chef', 'description' => 'Leads food preparation and kitchen operations.', 'sort_order' => 50],
        ];

        foreach ($roles as $role) {
            BusinessStaffRole::updateOrCreate(['slug' => $role['slug']], $role);
        }

        $roleIds = BusinessStaffRole::pluck('id', 'slug');
        BusinessUser::query()
            ->whereNull('business_staff_role_id')
            ->whereIn('business_role', $roleIds->keys())
            ->each(function (BusinessUser $membership) use ($roleIds) {
                $membership->update(['business_staff_role_id' => $roleIds[$membership->business_role]]);
            });
    }
}
