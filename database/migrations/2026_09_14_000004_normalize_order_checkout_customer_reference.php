<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_checkout_verifications', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('phone')->constrained('customers')->nullOnDelete();
            $table->string('phone', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_checkout_verifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
