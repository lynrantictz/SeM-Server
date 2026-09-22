<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('external_id');
            $table->unsignedBigInteger('initiated_by_user_id')->nullable()->after('order_id');
            $table->string('initiation_source')->nullable()->after('provider');
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->timestamp('paid_at')->nullable()->after('expires_at');
            $table->timestamp('failed_at')->nullable()->after('paid_at');
            $table->string('failure_reason')->nullable()->after('failed_at');
            $table->json('metadata')->nullable()->after('response_payload');
            $table->index(['order_id', 'status']);
            $table->index('transaction_id');
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->string('provider');
            $table->string('event_type');
            $table->string('event_reference');
            $table->boolean('signature_verified')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->uuid('uuid')->unique();
            $table->timestamps();

            $table->unique(['provider', 'event_reference']);
            $table->index(['payment_id', 'received_at']);
        });

        Schema::create('business_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->unique();
            $table->string('provider')->default('azampay');
            $table->string('currency', 10)->default('TZS');
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->string('commission_basis')->default('subtotal_excluding_tax');
            $table->string('fee_bearer')->default('business');
            $table->string('settlement_mode')->default('manual_hold');
            $table->boolean('is_checkout_enabled')->default(false);
            $table->boolean('is_settlement_enabled')->default(false);
            $table->string('payout_provider')->nullable();
            $table->string('payout_account_number')->nullable();
            $table->string('payout_recipient_reference')->nullable();
            $table->uuid('uuid')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_settings');
        Schema::dropIfExists('payment_events');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'status']);
            $table->dropIndex(['transaction_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn([
                'idempotency_key', 'initiated_by_user_id', 'initiation_source', 'expires_at',
                'paid_at', 'failed_at', 'failure_reason', 'metadata',
            ]);
        });
    }
};
