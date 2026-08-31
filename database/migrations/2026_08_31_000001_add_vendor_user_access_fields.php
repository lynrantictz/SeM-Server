<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_user', function (Blueprint $table) {
            $table->string('role')->default('manager')->after('is_primary');
            $table->string('access_scope')->default('all_businesses')->after('role');
            $table->boolean('is_active')->default(false)->after('access_scope');
            $table->timestamp('invited_at')->nullable()->after('is_active');
            $table->timestamp('accepted_at')->nullable()->after('invited_at');
            $table->timestamp('revoked_at')->nullable()->after('accepted_at');
            $table->unique(['vendor_id', 'user_id'], 'vendor_user_vendor_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_user', function (Blueprint $table) {
            $table->dropUnique('vendor_user_vendor_user_unique');
            $table->dropColumn(['role', 'access_scope', 'is_active', 'invited_at', 'accepted_at', 'revoked_at']);
        });
    }
};
