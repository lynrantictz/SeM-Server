<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('business_payout_accounts')
                ->select(['id', 'wallet_id', 'account_number', 'phone_number'])
                ->orderBy('id')
                ->get()
                ->each(function (object $account): void {
                    $values = [];

                    foreach (['wallet_id', 'account_number', 'phone_number'] as $column) {
                        if ($account->{$column} === null || $account->{$column} === '') {
                            continue;
                        }

                        try {
                            $values[$column] = Crypt::decryptString($account->{$column});
                        } catch (DecryptException) {
                            $values[$column] = $account->{$column};
                        }
                    }

                    if ($values !== []) {
                        DB::table('business_payout_accounts')
                            ->where('id', $account->id)
                            ->update($values);
                    }
            });

            Schema::table('business_payout_accounts', function (Blueprint $table): void {
                $table->unique('account_number', 'business_payout_accounts_account_number_unique');
                $table->unique('wallet_id', 'business_payout_accounts_wallet_id_unique');
            });
        });
    }

    public function down(): void
    {
        Schema::table('business_payout_accounts', function (Blueprint $table): void {
            $table->dropUnique('business_payout_accounts_account_number_unique');
            $table->dropUnique('business_payout_accounts_wallet_id_unique');
        });
    }
};
