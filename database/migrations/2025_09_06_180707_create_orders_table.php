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
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('code_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_status_id')->default(1);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->unsignedBigInteger('payment_status_id')->nullable();
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('number')->unique();
            $table->decimal('total_items_amount', 15, 2)->nullable();
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->decimal('due_amount', 15, 2)->nullable();
            $table->dateTime('phone_verified_at')->nullable();
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
