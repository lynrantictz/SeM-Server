<?php

namespace Database\Seeders;

use App\Models\Location\Country;
use App\Models\Payment\MobileMoneyProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MobileMoneyProviderSeeder extends Seeder
{
    public function run(): void
    {
        $tanzaniaId = Country::query()->where('iso2', 'TZ')->value('id');

        DB::transaction(function () use ($tanzaniaId): void {
            MobileMoneyProvider::query()
                ->where('country_id', $tanzaniaId)
                ->where('gateway', 'azampay')
                ->update(['is_active' => false]);

            foreach ([
                ['code' => '2', 'name' => 'Airtel Money', 'logo_url' => 'https://pg-vnext-banners.s3.eu-central-1.amazonaws.com/vnext-images/pgvnext-payment-images/airtel.svg', 'sort_order' => 10],
                ['code' => '3', 'name' => 'Tigo Pesa', 'logo_url' => 'https://pg-vnext-banners.s3.eu-central-1.amazonaws.com/vnext-images/pgvnext-payment-images/tigopesa.svg', 'sort_order' => 20],
                ['code' => '4', 'name' => 'HaloPesa', 'logo_url' => 'https://pg-vnext-banners.s3.eu-central-1.amazonaws.com/vnext-images/pgvnext-payment-images/halopesa.svg', 'sort_order' => 30],
                ['code' => '5', 'name' => 'AzamPesa', 'logo_url' => 'https://azampay-sarafutest.s3.eu-central-1.amazonaws.com/azampesa.png', 'sort_order' => 40],
            ] as $provider) {
                MobileMoneyProvider::query()->updateOrCreate(
                    ['country_id' => $tanzaniaId, 'gateway' => 'azampay', 'code' => $provider['code']],
                    [...$provider, 'is_active' => true],
                );
            }
        });
    }
}
