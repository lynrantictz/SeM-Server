<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table): void {
            $table->boolean('is_system_managed')->default(false)->after('is_active');
        });

        Schema::table('sub_sections', function (Blueprint $table): void {
            $table->boolean('is_system_managed')->default(false)->after('is_active');
        });

        Schema::table('service_points', function (Blueprint $table): void {
            $table->boolean('is_system_managed')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('service_points', fn (Blueprint $table) => $table->dropColumn('is_system_managed'));
        Schema::table('sub_sections', fn (Blueprint $table) => $table->dropColumn('is_system_managed'));
        Schema::table('sections', fn (Blueprint $table) => $table->dropColumn('is_system_managed'));
    }
};
