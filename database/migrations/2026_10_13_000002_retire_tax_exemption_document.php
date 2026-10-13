<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $type = DB::table('compliance_document_types')
            ->where('key', 'tax_exemption_certificate')
            ->first();

        if (! $type) {
            return;
        }

        DB::table('compliance_document_types')
            ->where('id', $type->id)
            ->update(['is_active' => false]);

        DB::table('compliance_document_type_rules')
            ->where('compliance_document_type_id', $type->id)
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        $type = DB::table('compliance_document_types')
            ->where('key', 'tax_exemption_certificate')
            ->first();

        if (! $type) {
            return;
        }

        DB::table('compliance_document_types')
            ->where('id', $type->id)
            ->update(['is_active' => true]);

        DB::table('compliance_document_type_rules')
            ->where('compliance_document_type_id', $type->id)
            ->update(['is_active' => true]);
    }
};
