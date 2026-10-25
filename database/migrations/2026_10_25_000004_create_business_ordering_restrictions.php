<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_billing_policies', function (Blueprint $table) {
            $table->timestamp('restriction_enabled_at')->nullable();
        });
        Schema::create('business_ordering_restrictions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('active_business_id')->nullable()->unique();
            $table->timestamp('restricted_at');
            $table->timestamp('observed_at');
            $table->string('date_basis');
            $table->string('reason')->default('overdue_invoice');
            $table->json('invoice_snapshot');
            $table->timestamp('restored_at')->nullable();
            $table->string('restoration_reason')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('restored_by')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'restricted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_ordering_restrictions');
        Schema::table('business_billing_policies', fn (Blueprint $table) => $table->dropColumn('restriction_enabled_at'));
    }
};
