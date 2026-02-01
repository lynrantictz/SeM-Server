<?php

namespace Database\Seeders;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
        |--------------------------------------------------------------------------
        | Permissions (Bus Booking Domain)
        |--------------------------------------------------------------------------
        */

        $permissions = [
            // superAdmin
            // owner
            'vendor.create',
            'vendor.edit',
            'vendor.view',
            'vendor.delete',
            'vendors.list',
            'vendor-user.create',
            'vendor-user.edit',
            'vendor-user.view',
            'vendor-user.delete',
            'vendor-users.list',
            'vendor-user.assign',
            'business.create',
            'business.edit',
            'business.view',
            'business.delete',
            'businesses.list',
            'business-user.create',
            'business-user.edit',
            'business-user.view',
            'business-user.delete',
            'business-users.list',
            'business-user.assign',
            'business-user.revoke',

            // manager
            'section.create',
            'section.edit',
            'section.view',
            'section.delete',
            'sections.list',

            'sub-section.create',
            'sub-section.edit',
            'sub-section.view',
            'sub-section.delete',
            'sub-sections.list',

            'table.create',
            'table.edit',
            'table.view',
            'table.delete',
            'tables.list',
            'table.assignment',

            // counterClerk
            'menu.create',
            'menu.edit',
            'menu.view',
            'menu.delete',
            'menus.list',
            'menu.activate',

            'menu-item.create',
            'menu-item.edit',
            'menu-item.view',
            'menu-item.delete',
            'menu-items.list',
            'menu-item.activate',


            // waiter
            'order.create',
            'order.view',
            'orders.list',
            'order.delete',
            'order.approve',
            'order.cancel',
            'order.complete',
            'order.payment'

        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        */

        // $superAdmin    = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'api']);
        $owner   = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'api']);
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'api']);
        $counterClerk     = Role::firstOrCreate(['name' => 'counter-clerk', 'guard_name' => 'api']);
        $waiter        = Role::firstOrCreate(['name' => 'waiter', 'guard_name' => 'api']);

        /*
        |--------------------------------------------------------------------------
        | Assign Permissions to Roles
        |--------------------------------------------------------------------------
        */

        // Super Admin → all permissions
        // $superAdmin->syncPermissions(Permission::all());

        // Owner
        $owner->syncPermissions([
            'vendor.create',
            'vendor.edit',
            'vendor.view',
            'vendor.delete',
            'vendors.list',
            'vendor-user.create',
            'vendor-user.edit',
            'vendor-user.view',
            'vendor-user.delete',
            'vendor-users.list',
            'vendor-user.assign',
            'business.create',
            'business.edit',
            'business.view',
            'business.delete',
            'businesses.list',
            'business-user.create',
            'business-user.edit',
            'business-user.view',
            'business-user.delete',
            'business-users.list',
            'business-user.assign',

            'section.create',
            'section.edit',
            'section.view',
            'section.delete',
            'sections.list',

            'sub-section.create',
            'sub-section.edit',
            'sub-section.view',
            'sub-section.delete',
            'sub-sections.list',

            'menu.create',
            'menu.edit',
            'menu.view',
            'menu.delete',
            'menus.list',
            'menu.activate',

            'menu-item.create',
            'menu-item.edit',
            'menu-item.view',
            'menu-item.delete',
            'menu-items.list',
            'menu-item.activate',
        ]);

        // Manager
        $manager->syncPermissions([
            'business-user.create',
            'business-user.edit',
            'business-user.view',
            'business-user.delete',
            'business-users.list',
            'business-user.assign',
            'business-user.revoke',

            'section.create',
            'section.edit',
            'section.view',
            'section.delete',
            'sections.list',

            'sub-section.create',
            'sub-section.edit',
            'sub-section.view',
            'sub-section.delete',
            'sub-sections.list',

            'table.create',
            'table.edit',
            'table.view',
            'table.delete',
            'tables.list',
            'table.assignment',
        ]);

        // Counter Clerk
        $counterClerk->syncPermissions([
            'table.create',
            'table.edit',
            'table.view',
            'table.delete',
            'tables.list',
            'table.assignment',

            'menu.create',
            'menu.edit',
            'menu.view',
            'menu.delete',
            'menus.list',
            'menu.activate',

            'menu-item.create',
            'menu-item.edit',
            'menu-item.view',
            'menu-item.delete',
            'menu-items.list',
            'menu-item.activate',

            'order.create',
            'order.view',
            'orders.list',
            'order.delete',
            'order.approve',
            'order.cancel',
            'order.complete',
            'order.payment'
        ]);

        $waiter->syncPermissions([
            'order.create',
            'order.view',
            'orders.list',
            'order.delete',
            'order.approve',
            'order.cancel',
            'order.complete',
            'order.payment'
        ]);
    }
}
