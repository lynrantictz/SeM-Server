<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('payment_allocation_id')->nullable()->constrained('payment_allocations')->nullOnDelete();
            $table->foreignId('payout_account_id')->nullable()->constrained('business_payout_accounts')->nullOnDelete();
            $table->string('gateway', 40)->default('azampay');
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('gateway_fee_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->string('currency', 10)->default('TZS');
            $table->string('status', 30)->default('pending_review');
            $table->string('idempotency_key')->unique();
            $table->string('external_reference')->nullable()->index();
            $table->string('provider_reference')->nullable()->index();
            $table->text('hold_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'created_at']);
            $table->unique(['payment_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payouts');
    }
};
