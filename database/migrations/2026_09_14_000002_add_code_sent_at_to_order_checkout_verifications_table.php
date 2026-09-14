<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_checkout_verifications', function (Blueprint $table) {
            $table->timestamp('code_sent_at')->nullable()->after('verification_code');
        });
    }

    public function down(): void
    {
        Schema::table('order_checkout_verifications', function (Blueprint $table) {
            $table->dropColumn('code_sent_at');
        });
    }
};
