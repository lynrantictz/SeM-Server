<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A code-based operational staff account is dedicated to one business.
     * Vendor managers are not affected because they have no staff-role row.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX business_user_staff_user_unique
             ON business_user (user_id)
             WHERE business_staff_role_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS business_user_staff_user_unique');
    }
};
