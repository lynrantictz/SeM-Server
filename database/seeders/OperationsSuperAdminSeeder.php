<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OperationsSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) config('services.paperstic.operations_super_admin_password', '');

        if ($password === '' || $password === 'CHANGE_ME_BEFORE_SEEDING') {
            throw new RuntimeException(
                'Set OPERATIONS_SUPER_ADMIN_PASSWORD to a strong secret before seeding the Operations super admin.'
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::query()
            ->where('name', 'super-admin')
            ->where('guard_name', 'api')
            ->firstOrFail();
        $role->syncPermissions(Permission::query()->where('guard_name', 'api')->get());

        $user = User::query()->updateOrCreate(
            ['email' => 'h.hamis@lynrantic.co.tz'],
            [
                'name' => 'Hamis Hamis',
                'password' => $password,
                'type' => 'paperstic',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $user->syncRoles([$role]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
