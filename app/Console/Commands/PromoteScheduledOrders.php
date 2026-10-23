<?php

namespace App\Console\Commands;

use App\Models\Order\Order;
use App\Models\Order\OrderStatus;
use App\Models\Order\OrderStatusHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PromoteScheduledOrders extends Command
{
    protected $signature = 'orders:promote-scheduled';
    protected $description = 'Move paid scheduled online orders into preparation when their preparation window begins';

    public function handle(): int
    {
        $processingId = OrderStatus::query()->where('name', 'Processing')->value('id');
        $scheduledId = OrderStatus::query()->where('name', 'Scheduled')->value('id');
        $completedPaymentId = DB::table('payment_statuses')->where('name', 'Completed')->value('id');
        if (!$processingId || !$scheduledId || !$completedPaymentId) return self::SUCCESS;

        Order::query()
            ->where('order_status_id', $scheduledId)
            ->where('payment_status_id', $completedPaymentId)
            ->whereNotNull('preparation_starts_at')
            ->where('preparation_starts_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($processingId): void {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, $processingId): void {
                        $locked = Order::query()->lockForUpdate()->find($order->id);
                        if (!$locked || $locked->order_status_id !== OrderStatus::query()->where('name', 'Scheduled')->value('id')) return;
                        if (!$locked->preparation_starts_at || $locked->preparation_starts_at->isFuture()) return;
                        $from = $locked->order_status_id;
                        $locked->update(['order_status_id' => $processingId]);
                        OrderStatusHistory::query()->create([
                            'order_id' => $locked->id,
                            'from_status_id' => $from,
                            'to_status_id' => $processingId,
                            'changed_by_user_id' => null,
                            'note' => 'Scheduled preparation window started.',
                        ]);
                    });
                }
            });

        return self::SUCCESS;
    }
}
