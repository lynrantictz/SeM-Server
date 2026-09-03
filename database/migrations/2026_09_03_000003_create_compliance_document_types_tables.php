<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_document_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->string('scope', 20);
            $table->text('description')->nullable();
            $table->boolean('requires_expiry_date')->default(false);
            $table->json('reminder_days')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('compliance_document_type_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('compliance_document_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('business_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('required_for_payment_activation')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country_id', 'business_type_id'], 'compliance_document_type_rule_context_index');
        });

        Schema::table('compliance_documents', function (Blueprint $table) {
            $table->foreignId('compliance_document_type_id')
                ->nullable()
                ->after('business_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('document_type_name')->nullable()->after('document_type');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_documents', function (Blueprint $table) {
            $table->dropForeign(['compliance_document_type_id']);
            $table->dropColumn(['compliance_document_type_id', 'document_type_name']);
        });

        Schema::dropIfExists('compliance_document_type_rules');
        Schema::dropIfExists('compliance_document_types');
    }
};
