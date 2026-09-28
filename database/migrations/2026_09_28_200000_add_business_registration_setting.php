<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'business.registration_enabled'],
                [
                    'value' => 'false',
                    'value_type' => 'boolean',
                    'description' => 'Controls whether new business owners may self-register through the public business portal.',
                    'uuid' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('system_settings')->where('key', 'business.registration_enabled')->delete();
        });
    }
};
