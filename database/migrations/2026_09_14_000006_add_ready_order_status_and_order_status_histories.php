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
            ['name' => 'Ready'],
            ['created_at' => now(), 'updated_at' => now()],
        );

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('from_status_id')->nullable();
            $table->unsignedBigInteger('to_status_id');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        $readyId = DB::table('order_statuses')->where('name', 'Ready')->value('id');
        if ($readyId && !DB::table('orders')->where('order_status_id', $readyId)->exists()) {
            DB::table('order_statuses')->where('id', $readyId)->delete();
        }
    }
};
