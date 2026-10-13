<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_payment_method_accounts', function (Blueprint $table): void {
            $table->string('bank_name')->nullable()->change();
        });

        $accountBasedCodes = ['mpesa_lipa_namba', 'mixx_merchant', 'airtel_money', 'halopesa', 'mobile_money', 'pos_card'];
        $mobileMethodIds = DB::table('payment_methods')->whereIn('code', $accountBasedCodes)->pluck('id');

        DB::table('business_payment_methods')
            ->whereIn('payment_method_id', $mobileMethodIds)
            ->whereNotNull('identifier')
            ->get()
            ->each(function (object $configuration): void {
                $alreadyMigrated = DB::table('business_payment_method_accounts')
                    ->where('business_payment_method_id', $configuration->id)
                    ->exists();

                if ($alreadyMigrated) {
                    return;
                }

                DB::table('business_payment_method_accounts')->insert([
                    'business_payment_method_id' => $configuration->id,
                    'account_number' => $configuration->identifier,
                    'account_holder_name' => $configuration->account_holder_name ?: 'Business account',
                    'currency' => 'TZS',
                    'status' => $configuration->status ?: 'pending',
                    'is_default' => true,
                    'is_enabled' => (bool) $configuration->is_enabled,
                    'sort_order' => 0,
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('business_payment_method_accounts', function (Blueprint $table): void {
            $table->string('bank_name')->nullable(false)->change();
        });
    }
};
