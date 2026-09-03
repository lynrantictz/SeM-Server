<?php

namespace Database\Seeders;

use App\Models\Business\ComplianceDocument;
use App\Models\Business\ComplianceDocumentType;
use Illuminate\Database\Seeder;

class ComplianceDocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'key' => 'business_registration_certificate',
                'name' => 'Business registration certificate',
                'scope' => 'vendor',
                'description' => 'Proof that the company or business name is legally registered.',
                'requires_expiry_date' => false,
                'reminder_days' => [],
                'sort_order' => 10,
                'required' => true,
            ],
            [
                'key' => 'tin_certificate',
                'name' => 'TIN certificate',
                'scope' => 'vendor',
                'description' => 'Taxpayer identification evidence for the vendor.',
                'requires_expiry_date' => false,
                'reminder_days' => [],
                'sort_order' => 20,
                'required' => true,
            ],
            [
                'key' => 'business_licence',
                'name' => 'Business licence',
                'scope' => 'business',
                'description' => 'Valid operating licence for this business location.',
                'requires_expiry_date' => true,
                'reminder_days' => [30, 7, 1],
                'sort_order' => 10,
                'required' => true,
            ],
            [
                'key' => 'proof_of_premises',
                'name' => 'Proof of premises',
                'scope' => 'business',
                'description' => 'Lease, title, landlord letter, or another proof of the operating location.',
                'requires_expiry_date' => false,
                'reminder_days' => [],
                'sort_order' => 20,
                'required' => true,
            ],
            [
                'key' => 'payment_settlement_details',
                'name' => 'Payment settlement details',
                'scope' => 'business',
                'description' => 'Merchant or settlement details required for mobile-money activation.',
                'requires_expiry_date' => false,
                'reminder_days' => [],
                'sort_order' => 30,
                'required' => true,
            ],
            [
                'key' => 'sector_permit',
                'name' => 'Sector permit',
                'scope' => 'business',
                'description' => 'A sector-specific permit such as tourism, food, or liquor approval where applicable.',
                'requires_expiry_date' => true,
                'reminder_days' => [30, 7, 1],
                'sort_order' => 40,
                'required' => false,
            ],
        ];

        foreach ($types as $definition) {
            $type = ComplianceDocumentType::query()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    'name' => $definition['name'],
                    'scope' => $definition['scope'],
                    'description' => $definition['description'],
                    'requires_expiry_date' => $definition['requires_expiry_date'],
                    'reminder_days' => $definition['reminder_days'],
                    'sort_order' => $definition['sort_order'],
                    'is_active' => true,
                ]
            );

            $type->rules()->updateOrCreate(
                ['country_id' => null, 'business_type_id' => null],
                [
                    'required_for_payment_activation' => $definition['required'],
                    'is_active' => true,
                ]
            );
        }

        ComplianceDocument::query()
            ->whereNull('compliance_document_type_id')
            ->orderBy('id')
            ->each(function (ComplianceDocument $document): void {
                $type = ComplianceDocumentType::query()->where('key', $document->document_type)->first();
                if ($type) {
                    $document->update([
                        'compliance_document_type_id' => $type->id,
                        'document_type_name' => $type->name,
                    ]);
                }
            });
    }
}
