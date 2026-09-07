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

    public function inputManipulator($item, $business): array
    {
        $itemResults = (new ItemRepository())->query()
            ->where('uuid', $item['uuid'])
            ->whereHas('category', fn ($query) => $query->where('business_id', $business->id))
            ->with('availabilityRules.days')
            ->first();
        if (!$itemResults) {
            throw ValidationException::withMessages(['items' => ['One or more selected menu items are invalid for this business.']]);
        }
        $availability = app(MenuAvailabilityService::class)->itemStatus($itemResults, $business);
        if (!$availability['is_available_now']) {
            throw ValidationException::withMessages(['items' => [$itemResults->name . ': ' . $availability['reason']]]);
        }
        return [
            'item_id' => $itemResults->id,
            'quantity' => $item['quantity'],
            'unit_price' => $itemResults->price,
            'discount' => $itemResults->discount, //this should be percentage
            'final_price' => $itemResults->final_price,
            'total_amount' => $itemResults->final_price * $item['quantity'],
            'comment' => $item['comment']
        ];
    }

    public function store(Order $order, $items)
    {
        return DB::transaction(function () use ($order, $items) {
            foreach ($items as $item) {
                $order->items()->create($this->inputManipulator($item, $order->business));
            }
            return $order->items;
        });
    }
}
