<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('order_items', 'comment')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->text('comment')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Keep this nullable field on rollback: the guard in up() also supports
        // databases where the column was added outside the migration history.
        // Dropping it here could erase existing guest instructions.
    }
};
