<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "UPDATE business_payout_accounts AS account
             SET is_default = TRUE
             WHERE account.status = 'active'
               AND account.verification_status = 'verified'
               AND NOT EXISTS (
                   SELECT 1
                   FROM business_payout_accounts AS current_default
                   WHERE current_default.business_id = account.business_id
                     AND current_default.is_default = TRUE
               )",
        );
    }

    public function down(): void
    {
        // Existing default assignments are retained.
    }
};
