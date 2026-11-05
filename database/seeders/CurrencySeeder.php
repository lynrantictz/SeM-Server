<?php

namespace Database\Seeders;

use App\Models\Location\Country;
use App\Models\Payment\Currency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'TZS' => ['Tanzanian Shilling', 'TSh', 2, 10],
            'USD' => ['US Dollar', '$', 2, 20],
            'KES' => ['Kenyan Shilling', 'KSh', 2, 30],
            'UGX' => ['Ugandan Shilling', 'USh', 0, 40],
            'RWF' => ['Rwandan Franc', 'FRw', 0, 50],
            'BIF' => ['Burundian Franc', 'FBu', 0, 60],
            'CDF' => ['Congolese Franc', 'FC', 2, 70],
            'ZMW' => ['Zambian Kwacha', 'ZK', 2, 80],
            'MWK' => ['Malawian Kwacha', 'MK', 2, 90],
            'ZAR' => ['South African Rand', 'R', 2, 100],
            'MZN' => ['Mozambican Metical', 'MT', 2, 110],
            'EUR' => ['Euro', '€', 2, 120],
            'GBP' => ['Pound Sterling', '£', 2, 130],
        ];

        foreach (Country::query()->get(['id', 'iso2', 'currency']) as $country) {
            $defaultCode = strtoupper(trim((string) $country->currency));
            $codes = array_filter(array_unique([$defaultCode, 'USD']));
            $codes = array_values(array_filter($codes, fn (string $code) => preg_match('/^[A-Z]{3}$/', $code) === 1));

            DB::transaction(function () use ($catalog, $country, $defaultCode, $codes): void {
                DB::table('country_currencies')
                    ->where('country_id', $country->id)
                    ->update(['is_default' => false, 'updated_at' => now()]);

                foreach ($codes as $code) {
                    [$name, $symbol, $minorUnit, $sortOrder] = $catalog[$code] ?? [$code, null, 2, 500];
                    $currency = Currency::query()->updateOrCreate(
                        ['code' => $code],
                        [
                            'name' => $name,
                            'symbol' => $symbol,
                            'minor_unit' => $minorUnit,
                            'is_active' => true,
                            'sort_order' => $sortOrder,
                        ],
                    );

                    DB::table('country_currencies')->updateOrInsert(
                        ['country_id' => $country->id, 'currency_id' => $currency->id],
                        [
                            'is_default' => $code === $defaultCode,
                            'is_active' => true,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );
                }
            });
        }
    }
}
