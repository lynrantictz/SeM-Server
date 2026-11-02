<?php

use App\Models\Auth\User;
use Database\Seeders\OperationsSuperAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

it('seeds the configured operations super admin with all current api permissions on SQLite', function () {
    expect(config('database.default'))->toBe('sqlite');

    $previousEmail = config('services.paperstic.operations_super_admin_email');
    $previousName = config('services.paperstic.operations_super_admin_name');
    $previousPassword = config('services.paperstic.operations_super_admin_password');
    $previousType = config('services.paperstic.operations_super_admin_type');
    config([
        'services.paperstic.operations_super_admin_email' => 'h.hamis@lynrantic.co.tz',
        'services.paperstic.operations_super_admin_name' => 'Hamis Hamis',
        'services.paperstic.operations_super_admin_password' => 'Local-Test-Only-Strong-Password-2026!',
        'services.paperstic.operations_super_admin_type' => 'paperstic',
    ]);

    try {
        app(RolePermissionSeeder::class)->run();
        app(OperationsSuperAdminSeeder::class)->run();

        $user = User::query()->where('email', config('services.paperstic.operations_super_admin_email'))->firstOrFail();

        expect($user->name)->toBe('Hamis Hamis')
            ->and($user->type)->toBe('paperstic')
            ->and((bool) $user->is_active)->toBeTrue()
            ->and(Hash::check('Local-Test-Only-Strong-Password-2026!', $user->password))->toBeTrue()
            ->and($user->hasRole('super-admin'))->toBeTrue()
            ->and($user->getAllPermissions()->pluck('name')->sort()->values()->all())
            ->toBe(Permission::query()->where('guard_name', 'api')->pluck('name')->sort()->values()->all());

        app(OperationsSuperAdminSeeder::class)->run();

        expect(User::query()->where('email', config('services.paperstic.operations_super_admin_email'))->count())->toBe(1);
    } finally {
        config([
            'services.paperstic.operations_super_admin_email' => $previousEmail,
            'services.paperstic.operations_super_admin_name' => $previousName,
            'services.paperstic.operations_super_admin_password' => $previousPassword,
            'services.paperstic.operations_super_admin_type' => $previousType,
        ]);
    }
});
