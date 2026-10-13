<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('compliance_document_types')
            ->where('key', 'vat_registration_certificate')
            ->update([
                'name' => 'Certificate of registration for VAT',
                'description' => 'Official evidence that the business is registered for VAT.',
            ]);

        DB::table('compliance_document_types')
            ->where('key', 'tax_exemption_certificate')
            ->update([
                'name' => 'Tax exemption certificate',
                'description' => 'Official certificate supporting the business tax-exempt status.',
            ]);

        DB::table('compliance_documents')
            ->where('document_type', 'vat_registration_certificate')
            ->update(['document_type_name' => 'Certificate of registration for VAT']);

        DB::table('compliance_documents')
            ->where('document_type', 'tax_exemption_certificate')
            ->update(['document_type_name' => 'Tax exemption certificate']);

        $tanzania = DB::table('countries')->where('iso2', 'TZ')->first();
        if ($tanzania) {
            DB::table('taxes')->updateOrInsert(
                ['country_id' => $tanzania->id],
                [
                    'percent' => 18,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('businesses')
            ->whereExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('compliance_documents as cd')
                    ->whereColumn('cd.business_id', 'businesses.id')
                    ->where('cd.document_type', 'vat_registration_certificate')
                    ->where('cd.status', 'approved')
                    ->where(function ($expiry): void {
                        $expiry->whereNull('cd.expires_at')
                            ->orWhere('cd.expires_at', '>', now());
                    });
            })
            ->update([
                'tax_allowed' => true,
                'tax_status' => 'vat_registered',
            ]);
    }

    public function down(): void
    {
        DB::table('compliance_document_types')
            ->where('key', 'vat_registration_certificate')
            ->update([
                'name' => 'VAT registration certificate',
                'description' => 'Official evidence that the business is registered for VAT.',
            ]);

        DB::table('compliance_document_types')
            ->where('key', 'tax_exemption_certificate')
            ->update([
                'name' => 'Tax exemption certificate or letter',
                'description' => 'Official evidence supporting the business tax-exempt status.',
            ]);
    }
};
