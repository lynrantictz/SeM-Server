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
            ->with(['availabilityRules.days', 'discountRules'])
            ->first();
        if (!$itemResults) {
            throw ValidationException::withMessages(['items' => ['One or more selected menu items are invalid for this business.']]);
        }
        $availability = app(MenuAvailabilityService::class)->itemStatus($itemResults, $business, $channel);
        if (!$availability['is_available_now']) {
            throw ValidationException::withMessages(['items' => [$itemResults->name . ': ' . $availability['reason']]]);
        }
        $pricing = app(MenuAvailabilityService::class)->itemPricing($itemResults, $business, $channel);
        $discountPercentage = $pricing['discount_percentage'];
        $unitPrice = $pricing['original_price'];
        $discountAmount = round($pricing['discount_amount'] * (int) $item['quantity'], 2);
        $finalPrice = $pricing['final_price'];
        return [
            'item_id' => $itemResults->id,
            'quantity' => $item['quantity'],
            'unit_price' => $unitPrice,
            'discount' => $discountPercentage,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
            'total_amount' => $finalPrice * $item['quantity'],
            'comment' => $item['comment']
        ];
    }

    public function store(Order $order, $items, string $channel = 'dine_in')
    {
        return DB::transaction(function () use ($order, $items, $channel) {
            $order->loadMissing('business.promotions', 'business.timezoneDefinition');
            foreach ($items as $item) {
                $order->items()->create($this->inputManipulator($item, $order->business, $channel));
            }
            return $order->items;
        });
    }
}
