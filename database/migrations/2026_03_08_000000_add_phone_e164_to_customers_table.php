<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Keep the original column for API/backfill compatibility. New
            // lookups and writes use this canonical value.
            $table->string('phone_e164', 16)->nullable()->unique()->after('phone');
        });

        // This is an additive backfill: the original phone value is retained.
        // Only values with a supported calling code are populated; the
        // application still supports unresolved legacy rows.
        $supportedCodes = DB::table('countries')
            ->pluck('phone_code')
            ->map(fn ($code): string => (string) $code)
            ->filter(fn (string $code): bool => preg_match('/^[1-9][0-9]{0,2}$/', $code) === 1)
            ->values();

        DB::table('customers')
            ->select(['id', 'phone'])
            ->orderBy('id')
            ->get()
            ->each(function (object $customer) use ($supportedCodes): void {
                $digits = preg_replace('/\D+/', '', (string) $customer->phone);
                if ($digits === null || $digits === '') {
                    return;
                }

                if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
                    $digits = '255' . substr($digits, 1);
                } elseif (strlen($digits) === 9) {
                    $digits = '255' . $digits;
                } elseif (!preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
                    return;
                }

                if ($supportedCodes->every(
                    fn (string $code): bool => !str_starts_with($digits, $code)
                )) {
                    return;
                }

                $canonical = $digits;
                $alreadyUsed = DB::table('customers')
                    ->where('phone_e164', $canonical)
                    ->where('id', '!=', $customer->id)
                    ->exists();

                if (!$alreadyUsed) {
                    DB::table('customers')
                        ->where('id', $customer->id)
                        ->update(['phone_e164' => $canonical]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone_e164']);
            $table->dropColumn('phone_e164');
        });
    }
};
