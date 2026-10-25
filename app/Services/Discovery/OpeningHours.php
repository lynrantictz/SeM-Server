<?php

namespace App\Services\Discovery;

use App\Models\Business\Business;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class OpeningHours
{
    public function status(Business $business, ?CarbonInterface $at = null): array
    {
        $business->loadMissing(['openingHours', 'timezoneDefinition']);
        $timezone = $business->timezoneDefinition?->identifier ?: ($business->timezone ?: config('app.timezone', 'UTC'));
        $now = CarbonImmutable::instance($at ?? now())->setTimezone($timezone);
        $base = ['timezone' => $timezone, 'closes_at' => null, 'next_opens_at' => null];
        if ($business->openingHours->isEmpty()) {
            return $base + ['open_now' => null, 'opening_hours' => 'Hours not provided'];
        }

        // Include yesterday so a late-night shift carries into the following day.
        $intervals = [];
        for ($offset = -1; $offset <= 7; $offset++) {
            $day = $now->startOfDay()->addDays($offset);
            foreach ($business->openingHours->where('day_of_week', $day->dayOfWeek) as $hour) {
                if ($hour->is_closed || ! $hour->opens_at || ! $hour->closes_at) continue;
                $start = $day->setTimeFromTimeString($hour->opens_at);
                $end = $day->setTimeFromTimeString($hour->closes_at);
                // Equal opening/closing times describe a full 24-hour shift.
                if ($end->lessThanOrEqualTo($start)) $end = $end->addDay();
                $intervals[] = [$start, $end];
            }
        }
        usort($intervals, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start->lessThanOrEqualTo($merged[$last][1])) {
                if ($end->greaterThan($merged[$last][1])) $merged[$last][1] = $end;
            } else {
                $merged[] = [$start, $end];
            }
        }
        foreach ($merged as [$start, $end]) {
            if ($now->greaterThanOrEqualTo($start) && $now->lessThan($end)) {
                $alwaysOpen = $start->addWeek()->lessThanOrEqualTo($end);
                return [...$base, 'open_now' => true,
                    'closes_at' => $alwaysOpen ? null : $end->toIso8601String(),
                    'opening_hours' => $alwaysOpen ? 'Open 24 hours' : 'Closes at '.$end->format('g:i a').($end->isSameDay($now) ? '' : ' tomorrow')];
            }
            if ($start->greaterThan($now)) {
                $when = $start->isSameDay($now) ? '' : ($start->isSameDay($now->addDay()) ? ' tomorrow' : ' '.$start->format('l'));
                return [...$base, 'open_now' => false, 'next_opens_at' => $start->toIso8601String(), 'opening_hours' => 'Opens'.$when.' at '.$start->format('g:i a')];
            }
        }

        return $base + ['open_now' => false, 'opening_hours' => 'No opening time scheduled'];
    }
}
