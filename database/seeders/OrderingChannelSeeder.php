<?php

namespace Database\Seeders;

use App\Models\Business\OrderingChannel;
use Illuminate\Database\Seeder;

class OrderingChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['slug' => 'dine_in', 'name' => 'Dine-in / QR menu', 'description' => 'Guests order from the table, room, or service point.', 'sort_order' => 10],
            ['slug' => 'online', 'name' => 'Online ordering', 'description' => 'Guests place an online order.', 'sort_order' => 20],
            ['slug' => 'pickup', 'name' => 'Pickup', 'description' => 'Guests order ahead and collect it.', 'sort_order' => 30],
            ['slug' => 'delivery', 'name' => 'Delivery', 'description' => 'Guests order for delivery.', 'sort_order' => 40],
        ];

        foreach ($channels as $channel) {
            OrderingChannel::updateOrCreate(['slug' => $channel['slug']], $channel);
        }
    }
}
