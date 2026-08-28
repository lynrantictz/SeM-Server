<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_user', function (Blueprint $table) {
            $table->index(['user_id', 'vendor_id'], 'vendor_user_user_vendor_index');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->index('created_at', 'vendors_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_user', function (Blueprint $table) {
            $table->dropIndex('vendor_user_user_vendor_index');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex('vendors_created_at_index');
        });
    }
};
