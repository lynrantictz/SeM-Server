<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('item_option_id')->nullable();
            $table->string('name', 120);
            $table->decimal('price_adjustment', 15, 2)->default(0);
            $table->uuid('uuid')->unique();
            $table->timestamps();
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
    }
};
