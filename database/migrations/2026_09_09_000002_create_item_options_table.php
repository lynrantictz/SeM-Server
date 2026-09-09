<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_option_group_id');
            $table->string('name', 120);
            $table->decimal('price_adjustment', 15, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('uuid')->unique();
            $table->timestamps();
            $table->index(['item_option_group_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_options');
    }
};
