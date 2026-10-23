<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_statuses')->updateOrInsert(
            ['name' => 'Awaiting payment'],
            ['created_at' => now(), 'updated_at' => now()],
        );
        DB::table('order_statuses')->updateOrInsert(
            ['name' => 'Scheduled'],
            ['created_at' => now(), 'updated_at' => now()],
        );

        Schema::table('orders', function (Blueprint $table): void {
            $table->dateTime('scheduled_for')->nullable()->after('approved_at');
            $table->dateTime('preparation_starts_at')->nullable()->after('scheduled_for');
            $table->string('schedule_timezone', 80)->nullable()->after('preparation_starts_at');
            $table->index(['order_status_id', 'preparation_starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['order_status_id', 'preparation_starts_at']);
            $table->dropColumn(['scheduled_for', 'preparation_starts_at', 'schedule_timezone']);
        });
        DB::table('order_statuses')->whereIn('name', ['Awaiting payment', 'Scheduled'])->delete();
    }
};
