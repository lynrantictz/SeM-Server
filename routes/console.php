<?php

use Illuminate\Foundation\Inspiring;
use App\Exceptions\InvalidPhoneNumberException;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('customers:backfill-phone-e164', function () {
    $normalizer = new PhoneNumberNormalizer();
    $updated = 0;
    $skipped = 0;

    DB::table('customers')
        ->select(['id', 'phone'])
        ->whereNull('phone_e164')
        ->orderBy('id')
        ->chunkById(100, function ($customers) use ($normalizer, &$updated, &$skipped): void {
            foreach ($customers as $customer) {
                try {
                    $canonical = $normalizer->normalize((string) $customer->phone);
                } catch (InvalidPhoneNumberException) {
                    $skipped++;
                    continue;
                }

                $alreadyUsed = DB::table('customers')
                    ->where('phone_e164', $canonical)
                    ->exists();

                if ($alreadyUsed) {
                    $skipped++;
                    continue;
                }

                DB::table('customers')
                    ->where('id', $customer->id)
                    ->whereNull('phone_e164')
                    ->update(['phone_e164' => $canonical]);
                $updated++;
            }
        });

    $this->info("Backfilled {$updated} customer phone values; skipped {$skipped}.");
})->purpose('Backfill customer phone_e164 values after countries are seeded');

Schedule::command('compliance-documents:send-expiry-reminders')->dailyAt('08:00');
