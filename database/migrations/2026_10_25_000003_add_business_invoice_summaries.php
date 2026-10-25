<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_invoices', function (Blueprint $table) {
            $table->unsignedInteger('order_count')->nullable();
            $table->decimal('gross_sales', 15, 2)->nullable();
            $table->decimal('order_tax_total', 15, 2)->nullable();
            $table->decimal('commission_base_total', 15, 2)->nullable();
            $table->json('rate_breakdown')->nullable();
            $table->json('issuer_snapshot')->nullable();
            $table->json('recipient_snapshot')->nullable();
            $table->string('business_timezone')->nullable();
        });
        // Historical gross sales and identities are not inferred from mutable orders.
    }

    public function down(): void
    {
        Schema::table('business_invoices', function (Blueprint $table) {
            $table->dropColumn(['order_count', 'gross_sales', 'order_tax_total', 'commission_base_total', 'rate_breakdown', 'issuer_snapshot', 'recipient_snapshot', 'business_timezone']);
        });
    }
};
