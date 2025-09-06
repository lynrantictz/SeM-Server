<?php

namespace Database\Seeders;

use App\Models\Payment\PaymentMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $methods = [
            'Cash',
            'Online'
        ];
        foreach ($methods as $method) {
            PaymentMethod::firstOrCreate(['name' => $method]);
        }
    }
}
