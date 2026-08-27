<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_customer_verifications', 'expires_at')) {
            Schema::table('order_customer_verifications', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_customer_verifications', 'expires_at')) {
            Schema::table('order_customer_verifications', function (Blueprint $table) {
                $table->dropColumn('expires_at');
            });
        }
    }
};
