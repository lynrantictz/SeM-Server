<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_promotion_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 32);
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
            $table->index(['promotion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_promotion_audits');
    }
};
