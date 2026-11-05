<?php

use App\Casts\UtcDateTime;
use App\Models\Order\Order;
use App\Services\Order\BusinessLocalSchedule;

it('resolves a selected business wall time and stores it as a UTC instant', function () {
    $selected = app(BusinessLocalSchedule::class)->toCarbon('2026-11-05T20:30', 'Africa/Dar_es_Salaam');
    $cast = new UtcDateTime();
    $storedValue = $cast->set(new Order(), 'scheduled_for', $selected, []);
    $restored = $cast->get(new Order(), 'scheduled_for', $storedValue, []);

    expect($storedValue)->toBe('2026-11-05 17:30:00')
        ->and($restored?->toIso8601String())->toBe('2026-11-05T17:30:00+00:00')
        ->and($restored?->copy()->setTimezone('Africa/Dar_es_Salaam')->format('Y-m-d H:i'))->toBe('2026-11-05 20:30');
});

it('rejects invalid business-local date and time values', function () {
    expect(fn () => app(BusinessLocalSchedule::class)->toCarbon('2026-13-45T99:30', 'Africa/Dar_es_Salaam'))
        ->toThrow(InvalidArgumentException::class);
});
