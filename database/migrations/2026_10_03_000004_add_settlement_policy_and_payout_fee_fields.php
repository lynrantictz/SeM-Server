<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_payment_settings', function (Blueprint $table): void {
            $table->string('settlement_requirement', 30)->default('required')->after('settlement_mode');
        });

        Schema::table('business_payouts', function (Blueprint $table): void {
            $table->decimal('collection_fee_amount', 15, 2)->default(0)->after('commission_amount');
            $table->decimal('disbursement_fee_amount', 15, 2)->default(0)->after('collection_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('business_payouts', function (Blueprint $table): void {
            $table->dropColumn(['collection_fee_amount', 'disbursement_fee_amount']);
        });
        Schema::table('business_payment_settings', function (Blueprint $table): void {
            $table->dropColumn('settlement_requirement');
        });
    }
};
