<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('service_point_id')->nullable()->after('code_id')->constrained()->nullOnDelete();
            $table->string('service_point_label', 140)->nullable()->after('service_point_id');
            $table->index('service_point_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_point_id');
            $table->dropColumn('service_point_label');
        });
    }
};
