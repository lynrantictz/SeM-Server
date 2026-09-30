<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 40)->default('azampay');
            $table->string('destination_type', 30)->default('mobile_money');
            $table->string('provider', 80);
            $table->text('account_number');
            $table->string('account_holder_name', 160);
            $table->string('currency', 10)->default('TZS');
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('verification_status', 30)->default('pending');
            $table->string('status', 30)->default('pending');
            $table->boolean('is_default')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'verification_status']);
            $table->index(['business_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payout_accounts');
    }
};
