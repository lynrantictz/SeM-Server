<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_review_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 12);
            $table->unsignedBigInteger('entity_id');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'entity_id']);
            $table->index(['scope', 'status']);
        });
        Schema::create('operations_review_history', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 12);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('decision', 24);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['scope', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_review_history');
        Schema::dropIfExists('operations_review_profiles');
    }
};
