<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sections ALTER COLUMN description DROP NOT NULL');
        DB::statement('ALTER TABLE sub_sections ALTER COLUMN description DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE sections SET description = '' WHERE description IS NULL");
        DB::statement("UPDATE sub_sections SET description = '' WHERE description IS NULL");
        DB::statement('ALTER TABLE sections ALTER COLUMN description SET NOT NULL');
        DB::statement('ALTER TABLE sub_sections ALTER COLUMN description SET NOT NULL');
    }
};
