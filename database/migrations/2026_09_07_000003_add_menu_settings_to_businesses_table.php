<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('tax_allowed');
            $table->boolean('dine_in_enabled')->default(true)->after('timezone');
            $table->boolean('online_ordering_enabled')->default(false)->after('dine_in_enabled');
            $table->boolean('pickup_enabled')->default(false)->after('online_ordering_enabled');
            $table->boolean('delivery_enabled')->default(false)->after('pickup_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'dine_in_enabled', 'online_ordering_enabled', 'pickup_enabled', 'delivery_enabled']);
        });
    }
};
