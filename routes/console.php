<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('compliance-documents:send-expiry-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command('orders:promote-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:cleanup-expired-checkouts')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('orders:cleanup-checkout-sessions')
    ->daily()
    ->withoutOverlapping();

Schedule::command('billing:process')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('billing:sync-restrictions')->everyMinute()->withoutOverlapping();
