<?php

namespace App\Services;

use App\Models\Business\Business;
use App\Models\Menu\Item;
use App\Models\Menu\Category;
use Carbon\Carbon;

class MenuAvailabilityService
{
    private function timezone(Business $business): string
    {
        return $business->timezoneDefinition?->identifier ?: ($business->timezone ?: config('app.timezone', 'UTC'));
    }

    public function businessStatus(Business $business, ?Carbon $now = null): array
    {
        $timezone = $this->timezone($business);
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

        $timezone = $this->timezone($business);
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

    public function categoryStatus(Category $category, Business $business, string $channel = 'dine_in', ?Carbon $now = null): array
    {
        if (!$category->is_active) return ['is_available_now' => false, 'reason' => 'This category is unavailable.'];
        $timezone = $this->timezone($business);
        $now = ($now ?: now())->setTimezone($timezone);
        $rules = $category->availabilityRules->where('is_active', true)->where('channel', $channel);
        if ($rules->isEmpty()) return ['is_available_now' => true, 'reason' => null];

        foreach ($rules as $rule) {
            if ($rule->available_from_date && $now->toDateString() < $rule->available_from_date->toDateString()) continue;
            if ($rule->available_to_date && $now->toDateString() > $rule->available_to_date->toDateString()) continue;
            if ($rule->days->isNotEmpty() && !$rule->days->contains('day_of_week', $now->dayOfWeek)) continue;
            if ($rule->starts_at && $rule->ends_at && !$this->isWithinTimeRange($now, $rule->starts_at, $rule->ends_at)) continue;
            return ['is_available_now' => true, 'reason' => null];
        }
        return ['is_available_now' => false, 'reason' => 'This category is not available at this time.'];
    }

    public function itemPricing(Item $item, Business $business, string $channel = 'dine_in', ?Carbon $now = null): array
    {
        $price = (float) $item->price;
        $timezone = $this->timezone($business);
        $now = ($now ?: now())->setTimezone($timezone);
        $channelRule = $item->relationLoaded('discountRules')
            ? $item->discountRules->first(fn ($rule) => $rule->channel === $channel
                && $rule->is_active
                && (!$rule->starts_at || $rule->starts_at <= $now)
                && (!$rule->ends_at || $rule->ends_at >= $now))
            : $item->discountRules()->where('channel', $channel)->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->first();
        $itemPercentage = (float) ($channelRule?->discount_percentage ?? $item->discount_percentage ?? 0);
        if ($itemPercentage <= 0) $itemPercentage = (float) ($item->discount ?? 0);
        $percentage = $itemPercentage;
        $source = $itemPercentage > 0 ? 'item' : null;

        if ($percentage <= 0) {
            $promotions = $business->relationLoaded('promotions')
                ? $business->promotions
                : $business->promotions()->get();
            $promotion = $promotions
                ->where('is_active', true)
                ->filter(fn ($promotion) => is_null($promotion->channel) || $promotion->channel === $channel)
                ->filter(fn ($promotion) => is_null($promotion->starts_at) || $promotion->starts_at <= $now)
                ->filter(fn ($promotion) => is_null($promotion->ends_at) || $promotion->ends_at >= $now)
                ->sortByDesc('priority')
                ->first();
            if ($promotion) { $percentage = (float) $promotion->discount_percentage; $source = 'business'; }
        }

        $finalPrice = round(max(0, $price - ($price * $percentage / 100)), 2);
        return ['original_price' => $price, 'discount_percentage' => $percentage, 'discount_amount' => round($price - $finalPrice, 2), 'final_price' => $finalPrice, 'source' => $source];
    }

    private function isWithinTimeRange(Carbon $now, string $startsAt, string $endsAt): bool
    {
        $current = $now->format('H:i:s');
        if ($startsAt <= $endsAt) return $current >= $startsAt && $current <= $endsAt;
        return $current >= $startsAt || $current <= $endsAt;
    }
}
