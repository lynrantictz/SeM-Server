<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_onboarding_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('package', 80);
            $table->decimal('amount_due', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->string('currency', 10)->default('TZS');
            $table->string('status', 30)->default('pending');
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_reference', 160)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_filename')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'paid_at']);
        });

        DB::table('compliance_document_types')
            ->where('key', 'payment_settlement_details')
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_onboarding_payments');
    }
};
