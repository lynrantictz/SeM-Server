<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_billing_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('business_id')->unique();
            $table->boolean('automatic_invoicing')->default(false);
            $table->boolean('enforce_overdue')->default(false);
            $table->string('billing_email')->nullable();
            $table->string('frequency')->default('weekly');
            $table->unsignedInteger('interval_days')->default(7);
            $table->unsignedInteger('billing_day')->default(1);
            $table->unsignedInteger('trial_days')->default(0);
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('fee_starts_at')->nullable();
            $table->timestamp('next_invoice_at')->nullable();
            $table->timestamp('last_invoice_at')->nullable();
            $table->unsignedInteger('grace_hours')->default(72);
            $table->text('payment_instructions')->nullable();
            $table->timestamps();
        });
        Schema::create('order_platform_fees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('business_id')->index();
            $table->decimal('rate', 5, 2);
            $table->decimal('base_amount', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 10);
            $table->string('reason');
            $table->string('status')->default('estimated');
            $table->timestamp('billable_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'billable_at']);
        });
        Schema::create('business_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->unique();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('currency', 10);
            $table->decimal('total', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('credit_amount', 15, 2)->default(0);
            $table->string('status')->default('draft');
            $table->string('billing_email');
            $table->text('payment_instructions')->nullable();
            $table->timestamp('period_from');
            $table->timestamp('period_to');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('delivery_error')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'period_to', 'currency']);
        });
        Schema::create('business_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('business_invoice_id')->index();
            $table->unsignedBigInteger('order_platform_fee_id')->unique();
            $table->string('order_number');
            $table->decimal('base_amount', 15, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
        Schema::create('business_invoice_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('business_invoice_id')->index();
            $table->unsignedBigInteger('recorded_by');
            $table->string('kind')->default('payment');
            $table->decimal('amount', 15, 2);
            $table->string('reference');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_invoice_id', 'kind', 'reference']);
        });
    }

    public function down(): void
    {
        foreach (['business_invoice_receipts', 'business_invoice_items', 'business_invoices', 'order_platform_fees', 'business_billing_policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
