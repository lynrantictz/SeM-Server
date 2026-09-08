<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_point_ordering_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_point_id');
            $table->unsignedBigInteger('ordering_channel_id');
            $table->timestamps();
            $table->unique(['service_point_id', 'ordering_channel_id']);
        });

        $channelIds = DB::table('ordering_channels')->pluck('id', 'slug');
        $enabledByBusiness = DB::table('business_ordering_channels')
            ->where('is_enabled', true)
            ->get(['business_id', 'ordering_channel_id'])
            ->groupBy('business_id');
        $points = DB::table('service_points')->get(['id', 'business_id', 'type']);
        $rows = [];
        foreach ($points as $point) {
            $slug = in_array($point->type, ['pickup', 'counter'], true) ? 'pickup' : 'dine_in';
            $channelId = $channelIds[$slug] ?? null;
            if (!$channelId || !$enabledByBusiness->has($point->business_id) || !$enabledByBusiness[$point->business_id]->contains('ordering_channel_id', $channelId)) continue;
            $rows[] = ['service_point_id' => $point->id, 'ordering_channel_id' => $channelId, 'created_at' => now(), 'updated_at' => now()];
        }
        if ($rows) DB::table('service_point_ordering_channels')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_point_ordering_channels');
    }
};
