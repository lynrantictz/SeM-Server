<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Repositories\BaseRepository;
use App\Repositories\Menu\ItemRepository;
use Illuminate\Support\Facades\DB;

class OrderItemRepository extends BaseRepository
{
    const MODEL = OrderItem::class;

    public function inputManipulator($item): array
    {
        $itemResults = (new ItemRepository())->findByUuid($item['uuid']);
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
                $order->items()->create($this->inputManipulator($item));
            }
            return $order->items;
        });
    }
}
