<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('whatsapp_status')->nullable();
            $table->string('whatsapp_message_id')->nullable();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->string('whatsapp_failure_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_links');
    }
};
