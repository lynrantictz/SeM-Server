<?php

namespace Database\Seeders;

use App\Models\Business\BusinessType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BusinessTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'Restaurant',
            'Coffee Shop',
            'Retail Store',
            'Grocery Store',
            'Clothing Store',
            'Electronics Store',
            'Hotel'
        ];
        foreach ($types as $type) {
            BusinessType::firstOrCreate(['name' => $type]);
        }
    }
}
