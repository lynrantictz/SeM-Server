<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('location_verified_at');
            $table->text('discovery_description')->nullable()->after('image_url');
            $table->decimal('rating', 2, 1)->nullable()->after('discovery_description');
            $table->unsignedInteger('review_count')->default(0)->after('rating');
            $table->string('opening_hours')->nullable()->after('review_count');
            $table->string('price_range')->nullable()->after('opening_hours');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'discovery_description', 'rating', 'review_count', 'opening_hours', 'price_range']);
        });
    }
};
