<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->string('code')->nullable()->unique()->after('name');
            $table->foreignId('country_id')->nullable()->after('code')->constrained('countries')->nullOnDelete();
            $table->string('identifier_label')->nullable()->after('country_id');
            $table->text('instructions')->nullable()->after('identifier_label');
            $table->string('logo_path')->nullable()->after('instructions');
            $table->boolean('requires_identifier')->default(true)->after('logo_path');
            $table->boolean('is_active')->default(true)->after('requires_identifier');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        DB::table('payment_methods')->where('name', 'Cash')->whereNull('code')->update(['code' => 'cash']);
        DB::table('payment_methods')->where('name', 'Online')->whereNull('code')->update(['code' => 'paperstic_online']);

        Schema::table('business_payment_settings', function (Blueprint $table): void {
            $table->string('payment_timing')->default('after_approval')->after('settlement_mode');
        });

        Schema::create('business_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->string('identifier')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('uuid')->unique();
            $table->timestamps();

            $table->unique(['business_id', 'payment_method_id']);
            $table->index(['business_id', 'is_enabled', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_methods');

        Schema::table('business_payment_settings', function (Blueprint $table): void {
            $table->dropColumn('payment_timing');
        });

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code', 'country_id', 'identifier_label', 'instructions', 'logo_path',
                'requires_identifier', 'is_active', 'sort_order',
            ]);
        });
    }
};
