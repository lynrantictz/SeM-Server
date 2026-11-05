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
        $email = trim((string) config('services.paperstic.operations_super_admin_email', ''));
        $name = trim((string) config('services.paperstic.operations_super_admin_name', ''));
        $password = (string) config('services.paperstic.operations_super_admin_password', '');
        $type = trim((string) config('services.paperstic.operations_super_admin_type', ''));

        $existingSuperAdmin = User::query()
            ->where('type', 'paperstic')
            ->whereHas('roles', fn ($query) => $query
                ->where('name', 'super-admin')
                ->where('guard_name', 'api'))
            ->exists();

        if ($existingSuperAdmin) {
            $this->command?->info('Operations super admin already exists; leaving the existing account unchanged.');

            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'Set OPERATIONS_SUPER_ADMIN_EMAIL to a valid email before seeding the Operations super admin.'
            );
        }

        if ($name === '') {
            throw new RuntimeException(
                'Set OPERATIONS_SUPER_ADMIN_NAME before seeding the Operations super admin.'
            );
        }

        if ($type !== 'paperstic') {
            throw new RuntimeException(
                'OPERATIONS_SUPER_ADMIN_TYPE must be paperstic for an Operations super admin.'
            );
        }

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
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'type' => $type,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $user->syncRoles([$role]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
