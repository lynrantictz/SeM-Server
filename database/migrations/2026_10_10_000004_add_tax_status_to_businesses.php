<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('tax_status', 32)
                ->default('not_registered')
                ->after('tax_allowed');
            $table->index('tax_status');
        });

        // Existing businesses that previously enabled tax need an operations
        // review because the old boolean did not identify the supporting tax
        // status or document.
        DB::table('businesses')
            ->where('tax_allowed', true)
            ->update(['tax_status' => 'under_review']);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropIndex(['tax_status']);
            $table->dropColumn('tax_status');
        });
    }
};
