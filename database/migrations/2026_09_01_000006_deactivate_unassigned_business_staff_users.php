<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Align existing staff account status with their business assignments.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('type', 'business')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('business_user')
                    ->whereColumn('business_user.user_id', 'users.id')
                    ->where('business_user.is_active', true);
            })
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Account activation is an explicit business decision and cannot be
        // safely inferred when this migration is rolled back.
    }
};
