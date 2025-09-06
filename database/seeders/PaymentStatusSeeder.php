<?php

namespace Database\Seeders;

use App\Models\Payment\PaymentStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            'Pending',
            'Completed',
            'Failed',
            'Refunded'
        ];
        foreach ($statuses as $status) {
            PaymentStatus::firstOrCreate(['name' => $status]);
        }
    }
}
