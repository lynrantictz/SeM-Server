<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_assignment_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('previous_waiter_id')->nullable()->index();
            $table->unsignedBigInteger('waiter_id')->index();
            $table->unsignedBigInteger('changed_by_user_id')->nullable()->index();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_assignment_histories');
    }
};
