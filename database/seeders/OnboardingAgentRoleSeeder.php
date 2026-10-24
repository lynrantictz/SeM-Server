<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OnboardingAgentRoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = [];
        foreach (['view', 'create', 'update', 'assign', 'disable'] as $action) {
            $permissions[] = Permission::firstOrCreate(['name' => "operations.agents.$action", 'guard_name' => 'api']);
        }
        foreach (Role::where('guard_name', 'api')->whereIn('name', ['super-admin', 'operations-admin'])->get() as $role) {
            $role->givePermissionTo($permissions);
        }
        $agent = Role::firstOrCreate(['name' => 'onboarding-agent', 'guard_name' => 'api']);
        foreach (['onboarding.manage', 'business.payment_methods.update', 'business.payment_methods.submit'] as $name) {
            $agent->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
