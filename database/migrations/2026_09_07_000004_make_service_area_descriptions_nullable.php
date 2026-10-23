<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['sections', 'sub_sections'] as $name) {
                Schema::table($name, fn (Blueprint $table) => $table->text('description')->nullable()->change());
            }
            return;
        }
        DB::statement('ALTER TABLE sections ALTER COLUMN description DROP NOT NULL');
        DB::statement('ALTER TABLE sub_sections ALTER COLUMN description DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE sections SET description = '' WHERE description IS NULL");
        DB::statement("UPDATE sub_sections SET description = '' WHERE description IS NULL");
        if (DB::getDriverName() === 'sqlite') {
            foreach (['sections', 'sub_sections'] as $name) {
                Schema::table($name, fn (Blueprint $table) => $table->text('description')->nullable(false)->change());
            }
            return;
        }
        DB::statement('ALTER TABLE sections ALTER COLUMN description SET NOT NULL');
        DB::statement('ALTER TABLE sub_sections ALTER COLUMN description SET NOT NULL');
    }
};
