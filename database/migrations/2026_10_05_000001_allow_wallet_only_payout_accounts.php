<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_payout_accounts', function (Blueprint $table): void {
            $table->string('provider', 80)->nullable()->change();
            $table->text('account_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('business_payout_accounts', function (Blueprint $table): void {
            $table->string('provider', 80)->nullable(false)->change();
            $table->text('account_number')->nullable(false)->change();
        });
    }
};
