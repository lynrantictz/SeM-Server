<?php

namespace App\Services\Order;

use Carbon\Carbon;
use InvalidArgumentException;

class BusinessLocalSchedule
{
    /** Resolve a datetime-local value as wall time in the business timezone. */
    public function toCarbon(string $value, string $timezone): Carbon
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid business-local schedule.', previous: $exception);
        }

        if (! $date || $date->format('Y-m-d\\TH:i') !== $value) {
            throw new InvalidArgumentException('Invalid business-local schedule.');
        }

        return $date;
    }
}
