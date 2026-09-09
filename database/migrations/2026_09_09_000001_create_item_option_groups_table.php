<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_option_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('name', 120);
            $table->string('selection_type', 16)->default('single');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('min_selections')->default(0);
            $table->unsignedSmallInteger('max_selections')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('uuid')->unique();
            $table->timestamps();
            $table->index(['item_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_option_groups');
    }
};
