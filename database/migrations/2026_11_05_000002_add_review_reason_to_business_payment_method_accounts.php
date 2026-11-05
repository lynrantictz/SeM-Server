<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('business_payment_method_accounts', 'rejection_reason')) {
            Schema::table('business_payment_method_accounts', function (Blueprint $table): void {
                $table->text('rejection_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_payment_method_accounts', 'rejection_reason')) {
            Schema::table('business_payment_method_accounts', function (Blueprint $table): void {
                $table->dropColumn('rejection_reason');
            });
        }
    }
};
