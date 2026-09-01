<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('businesses', 'code_prefix')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('code_prefix')->nullable()->after('order_prefix');
        });

        DB::table('businesses')->update([
            'code_prefix' => DB::raw('order_prefix'),
        ]);

        Schema::table('businesses', function (Blueprint $table) {
            $table->unique('code_prefix');
        });
    }

    public function down(): void
    {
        // This compatibility migration may be a no-op on fresh installations,
        // where the original businesses migration already defines code_prefix.
    }
};
