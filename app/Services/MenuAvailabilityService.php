<?php

namespace App\Services;

use App\Models\Business\Business;
use App\Models\Menu\Item;
use Carbon\Carbon;

class MenuAvailabilityService
{
    public function businessStatus(Business $business, ?Carbon $now = null): array
    {
        $timezone = $business->timezone ?: config('app.timezone', 'UTC');
        $now = ($now ?: now())->setTimezone($timezone);
        $hours = $business->openingHours()->where('day_of_week', $now->dayOfWeek)->orderBy('sort_order')->get();

        // Existing businesses remain available until an owner configures hours.
        if ($hours->isEmpty()) {
            return ['is_open_now' => true, 'timezone' => $timezone, 'reason' => null];
        }

        foreach ($hours->where('is_closed', false) as $hour) {
            if (!$hour->opens_at || !$hour->closes_at) continue;
            if ($this->isWithinTimeRange($now, $hour->opens_at, $hour->closes_at)) {
                return ['is_open_now' => true, 'timezone' => $timezone, 'reason' => null];
            }
        }

        return ['is_open_now' => false, 'timezone' => $timezone, 'reason' => 'This business is currently closed.'];
    }

    public function itemStatus(Item $item, Business $business, string $channel = 'dine_in', ?Carbon $now = null): array
    {
        if (!$item->is_active) return ['is_available_now' => false, 'reason' => 'This item is unavailable.'];
        if ($item->is_sold_out) return ['is_available_now' => false, 'reason' => 'This item is sold out.'];

        $timezone = $business->timezone ?: config('app.timezone', 'UTC');
        $now = ($now ?: now())->setTimezone($timezone);
        $rules = $item->availabilityRules->where('is_active', true)->where('channel', $channel);
        if ($rules->isEmpty()) return ['is_available_now' => true, 'reason' => null];

        foreach ($rules as $rule) {
            if ($rule->available_from_date && $now->toDateString() < $rule->available_from_date->toDateString()) continue;
            if ($rule->available_to_date && $now->toDateString() > $rule->available_to_date->toDateString()) continue;
            if ($rule->days->isNotEmpty() && !$rule->days->contains('day_of_week', $now->dayOfWeek)) continue;
            if ($rule->starts_at && $rule->ends_at && !$this->isWithinTimeRange($now, $rule->starts_at, $rule->ends_at)) continue;
            return ['is_available_now' => true, 'reason' => null];
        }
        return ['is_available_now' => false, 'reason' => 'This item is not available at this time.'];
    }

    private function isWithinTimeRange(Carbon $now, string $startsAt, string $endsAt): bool
    {
        $current = $now->format('H:i:s');
        if ($startsAt <= $endsAt) return $current >= $startsAt && $current <= $endsAt;
        return $current >= $startsAt || $current <= $endsAt;
    }
}
