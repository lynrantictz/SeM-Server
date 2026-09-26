<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('type', 24);
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamp('submitted_at');
            $table->uuid('uuid')->unique();
            $table->timestamps();

            $table->unique(['order_id', 'type']);
            $table->index(['business_id', 'type', 'submitted_at']);
            $table->index(['customer_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_feedback');
    }
};
