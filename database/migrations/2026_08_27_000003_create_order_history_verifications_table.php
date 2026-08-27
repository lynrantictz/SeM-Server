<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_history_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 16)->unique();
            $table->string('verification_code');
            $table->timestamp('verified_at')->nullable();
            $table->string('access_token')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_history_verifications');
    }
};
