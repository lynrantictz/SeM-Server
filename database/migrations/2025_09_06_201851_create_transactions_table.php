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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
//            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('payment_gateway_id');
            $table->string('gateway_transaction_id')->unique()->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency');
//            $table->enum('status', ['pending', 'processing', 'successful', 'failed', 'refunded'])->default('pending');
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
