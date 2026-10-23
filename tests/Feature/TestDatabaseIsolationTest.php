<?php

it('boots tests against isolated in-memory SQLite despite development caches', function () {
    expect(app()->environment())->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    expect(DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');
    expect(app()->getCachedConfigPath())->not->toBe(base_path('bootstrap/cache/config.php'));
});
