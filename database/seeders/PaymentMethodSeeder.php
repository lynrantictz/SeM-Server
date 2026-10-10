<?php

namespace Database\Seeders;

use App\Models\Payment\PaymentMethod;
use App\Models\Location\Country;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tanzaniaId = Country::query()->where('iso2', 'TZ')->value('id');
        $methods = [
            ['code' => 'mpesa_lipa_namba', 'name' => 'M-Pesa Lipa Namba', 'identifier_label' => 'Lipa Namba', 'instructions' => 'Open M-Pesa, choose Lipa kwa M-Pesa, enter the Lipa Namba and the exact order amount, then confirm.', 'logo_path' => '/payment-methods/mpesa.svg', 'requires_identifier' => true, 'is_active' => true, 'sort_order' => 10],
            ['code' => 'mixx_merchant', 'name' => 'Mixx merchant number', 'identifier_label' => 'Merchant number', 'instructions' => 'Open Mixx, choose the merchant payment option, enter the merchant number and the exact order amount, then confirm.', 'logo_path' => '/payment-methods/mixx.svg', 'requires_identifier' => true, 'is_active' => true, 'sort_order' => 20],
            ['code' => 'airtel_money', 'name' => 'Airtel Money', 'identifier_label' => 'Merchant number', 'instructions' => 'Open Airtel Money, choose merchant payment, enter the merchant number and the exact order amount, then confirm.', 'logo_path' => '/payment-methods/airtel-money.svg', 'requires_identifier' => true, 'is_active' => true, 'sort_order' => 30],
            ['code' => 'pos_card', 'name' => 'POS/Card', 'identifier_label' => 'Merchant or terminal number', 'instructions' => 'Pay by card at the business POS terminal and keep the receipt for reference.', 'logo_path' => '/payment-methods/pos.svg', 'requires_identifier' => false, 'is_active' => true, 'sort_order' => 40],
            ['code' => 'cash', 'name' => 'Cash', 'identifier_label' => null, 'instructions' => 'Pay cash to the waiter or cashier. The business will confirm receipt of the payment.', 'logo_path' => '/payment-methods/cash.svg', 'requires_identifier' => false, 'is_active' => true, 'sort_order' => 50],
            ['code' => 'bank_transfer', 'name' => 'Bank transfer', 'identifier_label' => 'Account number', 'instructions' => 'Use your banking app or visit your bank, then transfer the exact order amount to the account shown.', 'logo_path' => '/payment-methods/bank.svg', 'requires_identifier' => true, 'is_active' => true, 'sort_order' => 60],
            ['code' => 'paperstic_online', 'name' => 'Paperstic Online Payment', 'identifier_label' => null, 'instructions' => 'Pay securely online through Paperstic. This option will be available when online payment integration is enabled.', 'logo_path' => '/payment-methods/paperstic.svg', 'requires_identifier' => false, 'is_active' => false, 'sort_order' => 70],
        ];
        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(
                ['code' => $method['code']],
                [...$method, 'country_id' => $tanzaniaId],
            );
        }
    }
}
