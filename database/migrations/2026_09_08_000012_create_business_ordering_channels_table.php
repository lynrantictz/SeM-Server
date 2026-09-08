<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_ordering_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('ordering_channel_id');
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['business_id', 'ordering_channel_id']);
            $table->index(['business_id', 'is_enabled']);
        });

        $channels = DB::table('ordering_channels')->pluck('id', 'slug');
        $businesses = DB::table('businesses')->get(['id', 'dine_in_enabled', 'online_ordering_enabled', 'pickup_enabled', 'delivery_enabled']);
        $rows = [];
        foreach ($businesses as $business) {
            foreach ([
                'dine_in' => 'dine_in_enabled',
                'online' => 'online_ordering_enabled',
                'pickup' => 'pickup_enabled',
                'delivery' => 'delivery_enabled',
            ] as $slug => $legacyColumn) {
                if (!isset($channels[$slug])) continue;
                $rows[] = [
                    'business_id' => $business->id,
                    'ordering_channel_id' => $channels[$slug],
                    'is_enabled' => (bool) $business->{$legacyColumn},
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        if ($rows) DB::table('business_ordering_channels')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_ordering_channels');
    }
};
