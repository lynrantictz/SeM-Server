<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('business_payment_method_accounts', 'status')) {
            Schema::table('business_payment_method_accounts', function (Blueprint $table): void {
                $table->string('status')->default('pending')->after('currency');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_payment_method_accounts', 'status')) {
            Schema::table('business_payment_method_accounts', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }
    }
};
