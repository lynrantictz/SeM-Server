<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('google_location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('location_verified_at')->nullable()->after('longitude');
            $table->index(['is_active', 'latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'latitude', 'longitude']);
            $table->dropColumn(['latitude', 'longitude', 'location_verified_at']);
        });
    }
};
