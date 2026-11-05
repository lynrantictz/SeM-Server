<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 12)->nullable();
            $table->unsignedTinyInteger('minor_unit')->default(2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('country_currencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['country_id', 'currency_id']);
            $table->index(['country_id', 'is_active', 'is_default']);
        });

        $names = [
            'TZS' => ['Tanzanian Shilling', 'TSh'],
            'USD' => ['US Dollar', '$'],
            'KES' => ['Kenyan Shilling', 'KSh'],
            'UGX' => ['Ugandan Shilling', 'USh'],
            'RWF' => ['Rwandan Franc', 'FRw'],
            'BIF' => ['Burundian Franc', 'FBu'],
            'CDF' => ['Congolese Franc', 'FC'],
            'ZMW' => ['Zambian Kwacha', 'ZK'],
            'MWK' => ['Malawian Kwacha', 'MK'],
            'ZAR' => ['South African Rand', 'R'],
            'MZN' => ['Mozambican Metical', 'MT'],
            'EUR' => ['Euro', '€'],
            'GBP' => ['Pound Sterling', '£'],
        ];

        $now = now();
        $countries = DB::table('countries')->get(['id', 'iso2', 'currency']);

        foreach ($countries as $country) {
            $defaultCode = strtoupper(trim((string) $country->currency));
            $codes = array_filter(array_unique([$defaultCode, 'USD']));

            // Preserve previously configured account denominations while moving
            // currency eligibility out of payments.php and into reference data.
            if (Schema::hasTable('business_payment_method_accounts')) {
                $existingCodes = DB::table('business_payment_method_accounts as accounts')
                    ->join('business_payment_methods as methods', 'methods.id', '=', 'accounts.business_payment_method_id')
                    ->join('businesses', 'businesses.id', '=', 'methods.business_id')
                    ->join('districts', 'districts.id', '=', 'businesses.district_id')
                    ->join('cities', 'cities.id', '=', 'districts.city_id')
                    ->where('cities.country_id', $country->id)
                    ->pluck('accounts.currency')
                    ->map(fn ($code) => strtoupper(trim((string) $code)))
                    ->all();
                $codes = array_filter(array_unique([...$codes, ...$existingCodes]));
            }

            foreach ($codes as $index => $code) {
                if (! preg_match('/^[A-Z]{3}$/', $code)) {
                    continue;
                }

                [$name, $symbol] = $names[$code] ?? [$code, null];
                DB::table('currencies')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'symbol' => $symbol,
                        'minor_unit' => 2,
                        'is_active' => true,
                        'sort_order' => $index === 0 ? 10 : 50 + $index,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                $currencyId = DB::table('currencies')->where('code', $code)->value('id');
                DB::table('country_currencies')->updateOrInsert(
                    ['country_id' => $country->id, 'currency_id' => $currencyId],
                    [
                        'is_default' => $code === $defaultCode,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('country_currencies');
        Schema::dropIfExists('currencies');
    }
};
