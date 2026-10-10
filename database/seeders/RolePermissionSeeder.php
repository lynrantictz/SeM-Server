<?php

namespace Database\Seeders;

use App\Models\Auth\User;
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
            'business.payment_methods.view',
            'business.payment_methods.update',
            'business.payment_methods.submit',

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

            'business.payment_methods.view',
            'business.payment_methods.update',
            'business.payment_methods.submit',

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

            'business.payment_methods.view',
            'business.payment_methods.update',
            'business.payment_methods.submit',

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

        /*
        |--------------------------------------------------------------------------
        | Operations portal permissions and roles
        |--------------------------------------------------------------------------
        */

        $operationsPermissions = [
            'operations.dashboard.view',
            'operations.businesses.view',
            'operations.businesses.manage',
            'operations.businesses.approve',
            'operations.businesses.suspend',
            'operations.users.view',
            'operations.users.manage',
            'operations.users.assign-roles',
            'operations.kyc.view',
            'operations.kyc.review',
            'operations.kyc.approve',
            'operations.kyc.reject',
            'operations.kyc.return',
            'operations.payouts.view',
            'operations.payouts.review',
            'operations.payouts.approve',
            'operations.payouts.execute',
            'operations.payouts.reject',
            'operations.settlements.view',
            'operations.settlements.manage',
            'operations.commissions.view',
            'operations.commissions.manage',
            'operations.orders.view',
            'operations.orders.manage',
            'operations.support.view',
            'operations.support.manage',
            'operations.integrations.view',
            'operations.integrations.manage',
            'operations.webhooks.view',
            'operations.webhooks.retry',
            'operations.reports.view',
            'operations.reports.export',
            'operations.settings.view',
            'operations.settings.manage',
            'operations.audit.view',
        ];

        foreach ($operationsPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $operationsRoles = [
            'super-admin' => $operationsPermissions,
            'operations-admin' => [
                'operations.dashboard.view',
                'operations.businesses.view',
                'operations.businesses.manage',
                'operations.businesses.approve',
                'operations.businesses.suspend',
                'operations.users.view',
                'operations.users.manage',
                'operations.users.assign-roles',
                'operations.kyc.view',
                'operations.kyc.review',
                'operations.kyc.approve',
                'operations.kyc.reject',
                'operations.kyc.return',
                'operations.orders.view',
                'operations.orders.manage',
                'operations.support.view',
                'operations.support.manage',
                'operations.reports.view',
                'operations.reports.export',
                'operations.settings.view',
                'operations.settlements.manage',
                'operations.audit.view',
            ],
            'kyc-reviewer' => [
                'operations.dashboard.view',
                'operations.businesses.view',
                'operations.kyc.view',
                'operations.kyc.review',
                'operations.kyc.approve',
                'operations.kyc.reject',
                'operations.kyc.return',
                'operations.audit.view',
            ],
            'finance-officer' => [
                'operations.dashboard.view',
                'operations.payouts.view',
                'operations.payouts.review',
                'operations.payouts.approve',
                'operations.payouts.execute',
                'operations.payouts.reject',
                'operations.settlements.view',
                'operations.settlements.manage',
                'operations.commissions.view',
                'operations.commissions.manage',
                'operations.reports.view',
                'operations.reports.export',
                'operations.audit.view',
            ],
            'support-agent' => [
                'operations.dashboard.view',
                'operations.businesses.view',
                'operations.users.view',
                'operations.orders.view',
                'operations.support.view',
                'operations.support.manage',
                'operations.reports.view',
            ],
            'integration-manager' => [
                'operations.dashboard.view',
                'operations.integrations.view',
                'operations.integrations.manage',
                'operations.webhooks.view',
                'operations.webhooks.retry',
                'operations.settings.view',
                'operations.audit.view',
            ],
            'read-only-analyst' => [
                'operations.dashboard.view',
                'operations.businesses.view',
                'operations.payouts.view',
                'operations.settlements.view',
                'operations.commissions.view',
                'operations.orders.view',
                'operations.reports.view',
            ],
        ];

        foreach ($operationsRoles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'api',
            ]);

            $role->syncPermissions($roleName === 'super-admin'
                ? Permission::query()->where('guard_name', 'api')->get()
                : $rolePermissions);
        }

        // Keep the initial operations account fully provisioned when it exists.
        User::query()
            ->where('email', 'admin@paperstic.com')
            ->where('type', 'paperstic')
            ->get()
            ->each(function (User $user): void {
                $user->syncRoles(['super-admin']);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
