<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(LocationSeeder::class);
        $this->call(OrderStatusSeeder::class);
        $this->call(PaymentMethodSeeder::class);
        $this->call(PaymentStatusSeeder::class);
    }
}
