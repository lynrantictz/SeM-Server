<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table): void {
            $table->timestamp('effective_from')->nullable()->after('percent');
            $table->timestamp('effective_until')->nullable()->after('effective_from');
            $table->foreign('country_id')->references('id')->on('countries')->cascadeOnDelete();
            $table->index(['country_id', 'is_active', 'effective_from'], 'taxes_country_active_effective_index');
        });

        DB::table('taxes')
            ->whereNull('effective_from')
            ->update(['effective_from' => DB::raw('created_at')]);

        DB::statement('CREATE UNIQUE INDEX taxes_one_active_per_country ON taxes (country_id) WHERE is_active = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS taxes_one_active_per_country');

        Schema::table('taxes', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
            $table->dropIndex('taxes_country_active_effective_index');
            $table->dropColumn(['effective_from', 'effective_until']);
        });
    }
};
