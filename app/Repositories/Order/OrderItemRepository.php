<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Repositories\BaseRepository;
use App\Repositories\Menu\ItemRepository;
use App\Services\MenuAvailabilityService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class OrderItemRepository extends BaseRepository
{
    const MODEL = OrderItem::class;

    public function inputManipulator($item, $business, string $channel = 'dine_in'): array
    {
        $itemResults = (new ItemRepository())->query()
            ->where('uuid', $item['uuid'])
            ->whereHas('category', fn($query) => $query->where('business_id', $business->id))
            ->with(['availabilityRules.days', 'discountRules', 'optionGroups.options'])
            ->first();
        if (!$itemResults) {
            throw ValidationException::withMessages(['items' => ['One or more selected menu items are invalid for this business.']]);
        }
        $availability = app(MenuAvailabilityService::class)->itemStatus($itemResults, $business, $channel);
        if (!$availability['is_available_now']) {
            throw ValidationException::withMessages(['items' => [$itemResults->name . ': ' . $availability['reason']]]);
        }
        $pricing = app(MenuAvailabilityService::class)->itemPricing($itemResults, $business, $channel);
        $selectedOptions = $this->resolveOptions($item, $itemResults);
        $optionAdjustment = collect($selectedOptions)->sum('price_adjustment');
        $discountPercentage = $pricing['discount_percentage'];
        $unitPrice = $pricing['original_price'];
        $discountAmount = round($pricing['discount_amount'] * (int) $item['quantity'], 2);
        $finalPrice = round($pricing['final_price'] + $optionAdjustment, 2);
        return [
            'item_id' => $itemResults->id,
            'quantity' => $item['quantity'],
            'unit_price' => $unitPrice,
            'discount' => $discountPercentage,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
            'total_amount' => $finalPrice * $item['quantity'],
            'comment' => $item['comment'] ?? null,
            '_selected_options' => $selectedOptions,
        ];
    }

    public function store(Order $order, $items, string $channel = 'dine_in')
    {
        return DB::transaction(function () use ($order, $items, $channel) {
            $order->loadMissing('business.promotions', 'business.timezoneDefinition');
            foreach ($items as $item) {
                $line = $this->inputManipulator($item, $order->business, $channel);
                $selectedOptions = $line['_selected_options'] ?? [];
                unset($line['_selected_options']);
                $orderItem = $order->items()->create($line);
                if ($selectedOptions) $orderItem->options()->createMany($selectedOptions);
            }
            return $order->items;
        });
    }

    private function resolveOptions(array $item, $itemResults): array
    {
        $groups = $itemResults->optionGroups->where('is_active', true)->values();
        $requested = collect($item['options'] ?? [])->pluck('uuid')->filter()->unique()->values();
        $available = $groups->flatMap(fn ($group) => $group->options->where('is_active', true));

        if ($requested->diff($available->pluck('uuid'))->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => [$itemResults->name . ': one or more selected options are unavailable.']]);
        }

        foreach ($groups as $group) {
            $selected = $available->whereIn('uuid', $requested)->where('item_option_group_id', $group->id);
            $count = $selected->count();
            $minimum = $group->is_required ? max(1, $group->min_selections) : $group->min_selections;
            if ($count < $minimum || ($group->max_selections !== null && $count > $group->max_selections) || ($group->selection_type === 'single' && $count > 1)) {
                throw ValidationException::withMessages(['items' => [$itemResults->name . ': please choose valid options for ' . $group->name . '.']]);
            }
        }

        return $available->whereIn('uuid', $requested)->map(fn ($option) => [
            'item_option_id' => $option->id,
            'name' => $option->name,
            'price_adjustment' => $option->price_adjustment,
        ])->values()->all();
    }
}
