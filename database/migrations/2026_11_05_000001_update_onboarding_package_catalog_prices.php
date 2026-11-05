<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $packages = [
            'start' => ['name' => 'Paperstic Starter', 'price' => 750000],
            'business' => ['name' => 'Paperstic Business', 'price' => 1500000],
            'premium' => ['name' => 'Paperstic Premium', 'price' => 3000000],
        ];

        DB::transaction(function () use ($packages): void {
            foreach ($packages as $key => $package) {
                DB::table('onboarding_packages')
                    ->where('key', $key)
                    ->update([
                        'name' => $package['name'],
                        'price' => $package['price'],
                        'currency' => 'TZS',
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        $packages = [
            'start' => ['name' => 'Paperstic Start', 'price' => 600000],
            'business' => ['name' => 'Paperstic Business', 'price' => 750000],
            'premium' => ['name' => 'Paperstic Premium', 'price' => 1000000],
        ];

        DB::transaction(function () use ($packages): void {
            foreach ($packages as $key => $package) {
                DB::table('onboarding_packages')
                    ->where('key', $key)
                    ->update([
                        'name' => $package['name'],
                        'price' => $package['price'],
                        'currency' => 'TZS',
                        'updated_at' => now(),
                    ]);
            }
        });
    }
};
