<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX business_payout_accounts_one_active_per_business
             ON business_payout_accounts (business_id)
             WHERE status = 'active'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS business_payout_accounts_one_active_per_business');
    }
};
