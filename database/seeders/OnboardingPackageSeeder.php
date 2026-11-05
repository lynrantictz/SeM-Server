<?php

namespace Database\Seeders;

use App\Models\Business\OnboardingPackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OnboardingPackageSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ([
                ['key' => 'start', 'name' => 'Paperstic Starter', 'price' => 750000, 'sort_order' => 10],
                ['key' => 'business', 'name' => 'Paperstic Business', 'price' => 1500000, 'sort_order' => 20],
                ['key' => 'premium', 'name' => 'Paperstic Premium', 'price' => 3000000, 'sort_order' => 30],
            ] as $package) {
                // Keep runtime price versions intact when the seeder is re-run.
                OnboardingPackage::query()->firstOrCreate(
                    ['key' => $package['key'], 'version' => 1],
                    [...$package, 'currency' => 'TZS', 'is_active' => true, 'activated_at' => now()],
                );
            }
        });
    }
}
