<?php

namespace Database\Seeders;

use App\Models\Business\OnboardingPackage;
use Illuminate\Database\Seeder;

class OnboardingPackageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['key' => 'start', 'name' => 'Paperstic Start', 'price' => 600000, 'sort_order' => 10],
            ['key' => 'business', 'name' => 'Paperstic Business', 'price' => 750000, 'sort_order' => 20],
            ['key' => 'premium', 'name' => 'Paperstic Premium', 'price' => 1000000, 'sort_order' => 30],
        ] as $package) {
            OnboardingPackage::query()->updateOrCreate(
                ['key' => $package['key']],
                [...$package, 'currency' => 'TZS', 'is_active' => true],
            );
        }
    }
}
