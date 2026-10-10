<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payment_method_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_payment_method_id')->constrained('business_payment_methods')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_holder_name');
            $table->string('branch_name')->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->uuid('uuid')->unique();
            $table->timestamps();

            $table->index(['business_payment_method_id', 'is_enabled']);
        });

        $bankMethodId = DB::table('payment_methods')->where('code', 'bank_transfer')->value('id');
        if ($bankMethodId) {
            DB::table('business_payment_methods')
                ->where('payment_method_id', $bankMethodId)
                ->whereNotNull('identifier')
                ->get()
                ->each(function (object $configuration): void {
                    DB::table('business_payment_method_accounts')->insert([
                        'business_payment_method_id' => $configuration->id,
                        'bank_name' => $configuration->bank_name ?: 'Bank account',
                        'account_number' => $configuration->identifier,
                        'account_holder_name' => $configuration->account_holder_name ?: 'Business account',
                        'currency' => 'TZS',
                        'is_default' => true,
                        'is_enabled' => true,
                        'sort_order' => 0,
                        'uuid' => (string) \Illuminate\Support\Str::uuid(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_method_accounts');
    }
};
