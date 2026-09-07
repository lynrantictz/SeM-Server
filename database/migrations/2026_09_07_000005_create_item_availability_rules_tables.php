<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_sold_out')->default(false)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_sold_out');
        });

        Schema::create('item_availability_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('channel', 24)->default('dine_in');
            $table->date('available_from_date')->nullable();
            $table->date('available_to_date')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->uuid('uuid')->unique();
            $table->timestamps();

            $table->index(['item_id', 'channel', 'is_active']);
        });

        Schema::create('item_availability_rule_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_availability_rule_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->timestamps();

            $table->unique(['item_availability_rule_id', 'day_of_week'], 'item_availability_rule_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_availability_rule_days');
        Schema::dropIfExists('item_availability_rules');
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['is_sold_out', 'sort_order']);
        });
    }
};
