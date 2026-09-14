<?php

namespace Database\Seeders;

use App\Models\Order\OrderStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OrderStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            'Pending',
            'Processing',
            'Ready',
            'Completed',
            'Cancelled',
            'Refunded'
        ];
        foreach ($statuses as $status) {
            OrderStatus::firstOrCreate(['name' => $status]);
        }
    }
}
