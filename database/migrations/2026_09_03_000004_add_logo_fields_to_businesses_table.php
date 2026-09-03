<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('logo_disk', 50)->nullable()->after('google_location');
            $table->string('logo_path')->nullable()->after('logo_disk');
            $table->string('logo_mime_type', 100)->nullable()->after('logo_path');
            $table->timestamp('logo_updated_at')->nullable()->after('logo_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['logo_disk', 'logo_path', 'logo_mime_type', 'logo_updated_at']);
        });
    }
};
