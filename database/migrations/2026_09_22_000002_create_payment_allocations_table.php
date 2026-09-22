<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->unique();
            $table->unsignedBigInteger('business_id');
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('commission_base_amount', 15, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 15, 2);
            $table->decimal('gateway_fee_amount', 15, 2)->default(0);
            $table->decimal('business_payable_amount', 15, 2);
            $table->string('currency', 10)->default('TZS');
            $table->json('calculation')->nullable();
            $table->uuid('uuid')->unique();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
