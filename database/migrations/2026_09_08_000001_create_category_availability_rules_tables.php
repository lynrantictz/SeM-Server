<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_availability_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('channel', 24)->default('dine_in');
            $table->date('available_from_date')->nullable();
            $table->date('available_to_date')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('uuid')->unique();
            $table->timestamps();
            $table->index(['category_id', 'channel', 'is_active']);
        });

        Schema::create('category_availability_rule_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_availability_rule_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->timestamps();
            $table->unique(['category_availability_rule_id', 'day_of_week'], 'category_availability_rule_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_availability_rule_days');
        Schema::dropIfExists('category_availability_rules');
    }
};
