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
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('external_id');      // your reference
            $table->string('transaction_id')->nullable(); // AzamPay ID
            $table->string('account_number');
            $table->string('provider');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 10)->default('TZS');

            $table->enum('status', [
                'PENDING',
                'PROCESSING',
                'SUCCESS',
                'FAILED'
            ])->default('PENDING');

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
