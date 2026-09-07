<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_points', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_section_id')->nullable()->constrained('sub_sections')->nullOnDelete();
            $table->string('type', 30);
            $table->string('label', 80);
            $table->string('display_name', 140);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'type', 'is_active']);
        });

        // PostgreSQL treats NULL values as distinct in a regular unique key.
        // These partial indexes enforce unique labels both directly under a
        // section and within each subsection.
        DB::statement('CREATE UNIQUE INDEX service_points_section_label_unique ON service_points (section_id, label) WHERE sub_section_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX service_points_subsection_label_unique ON service_points (sub_section_id, label) WHERE sub_section_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_points');
    }
};
