<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('business_license_number')->nullable()->unique()->after('tin');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropUnique(['business_license_number']);
            $table->dropColumn('business_license_number');
        });
    }
};
