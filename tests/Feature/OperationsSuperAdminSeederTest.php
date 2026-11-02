<?php

use App\Models\Auth\User;
use Database\Seeders\OperationsSuperAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

it('seeds the configured operations super admin with all current api permissions on SQLite', function () {
    expect(config('database.default'))->toBe('sqlite');

    $key = 'services.paperstic.operations_super_admin_password';
    $previousPassword = config($key);
    config([$key => 'Local-Test-Only-Strong-Password-2026!']);

    try {
        app(RolePermissionSeeder::class)->run();
        app(OperationsSuperAdminSeeder::class)->run();

        $user = User::query()->where('email', 'h.hamis@lynrantic.co.tz')->firstOrFail();

        expect($user->type)->toBe('paperstic')
            ->and((bool) $user->is_active)->toBeTrue()
            ->and(Hash::check('Local-Test-Only-Strong-Password-2026!', $user->password))->toBeTrue()
            ->and($user->hasRole('super-admin'))->toBeTrue()
            ->and($user->getAllPermissions()->pluck('name')->sort()->values()->all())
            ->toBe(Permission::query()->where('guard_name', 'api')->pluck('name')->sort()->values()->all());

        app(OperationsSuperAdminSeeder::class)->run();

        expect(User::query()->where('email', 'h.hamis@lynrantic.co.tz')->count())->toBe(1);
    } finally {
        config([$key => $previousPassword]);
    }
});
