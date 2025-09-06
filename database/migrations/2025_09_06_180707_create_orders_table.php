<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_status_id')->default(1);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->unsignedBigInteger('payment_status_id')->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->decimal('due_amount', 15, 2)->nullable();
            $table->uuid('uuid')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
