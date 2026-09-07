<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique('sections_description_unique');
            $table->unique(['business_id', 'name'], 'sections_business_name_unique');
            $table->index(['business_id', 'is_active']);
        });

        Schema::table('sub_sections', function (Blueprint $table) {
            $table->dropUnique('sub_sections_description_unique');
            $table->unique(['section_id', 'name'], 'sub_sections_section_name_unique');
            $table->index(['business_id', 'section_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('sub_sections', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'section_id', 'is_active']);
            $table->dropUnique('sub_sections_section_name_unique');
            $table->unique('description');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_active']);
            $table->dropUnique('sections_business_name_unique');
            $table->unique('description');
        });
    }
};
