<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Models\Order\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardSummary
{
    public function forBusiness(Business $business): array
    {
        $business->loadMissing(['timezoneDefinition', 'paymentSetting', 'district.city.country']);
        $timezone = $business->timezoneDefinition?->identifier ?: $business->timezone ?: config('app.timezone', 'UTC');
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }
        $now = CarbonImmutable::now($timezone);
        $from = $now->startOfDay()->utc()->format('Y-m-d H:i:s');
        $to = $now->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s');
        $currency = $business->paymentSetting?->currency ?: $business->district?->city?->country?->currency ?: 'TZS';
        $counts = DB::table('orders')->join('order_statuses', 'order_statuses.id', '=', 'orders.order_status_id')
            ->where('orders.business_id', $business->id)->groupBy('order_statuses.name')
            ->selectRaw('order_statuses.name, COUNT(*) AS total')->pluck('total', 'name')->map(fn ($value) => (int) $value)->all();

        // Use the last completion event; creation time is not completion time.
        $completion = DB::table('order_status_histories')->join('order_statuses', 'order_statuses.id', '=', 'order_status_histories.to_status_id')
            ->where('order_statuses.name', 'Completed')->groupBy('order_id')
            ->selectRaw('order_id, MAX(order_status_histories.created_at) AS completed_at');
        $completed = DB::table('orders')->join('order_statuses', 'order_statuses.id', '=', 'orders.order_status_id')
            ->leftJoinSub($completion, 'completion', 'completion.order_id', '=', 'orders.id')
            ->where('orders.business_id', $business->id)->where('order_statuses.name', 'Completed')
            ->whereRaw('COALESCE(completion.completed_at, orders.updated_at) >= ?', [$from])
            ->whereRaw('COALESCE(completion.completed_at, orders.updated_at) < ?', [$to]);
        $completedToday = (clone $completed)->count();
        $legacyCount = (clone $completed)->whereNull('completion.completed_at')->count();

        $receipts = DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.business_id', $business->id)->where('payments.status', 'SUCCESS')
            ->whereRaw('COALESCE(payments.confirmed_at, payments.paid_at) >= ?', [$from])
            ->whereRaw('COALESCE(payments.confirmed_at, payments.paid_at) < ?', [$to])
            ->groupBy('payments.currency')->orderBy('payments.currency')
            ->selectRaw('payments.currency, SUM(payments.amount) AS amount, COUNT(*) AS count')
            ->get()->map(fn ($row) => ['currency' => $row->currency, 'amount' => (float) $row->amount, 'count' => (int) $row->count])->all();
        $awaitingPayment = Order::query()->where('business_id', $business->id)
            ->whereHas('status', fn ($query) => $query->whereIn('name', ['Awaiting payment', 'Served']))
            ->whereHas('paymentStatus', fn ($query) => $query->where('name', 'Pending'))->count();
        $recent = Order::query()->where('business_id', $business->id)->with(['status', 'paymentStatus', 'payment'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get()->map(fn ($order) => [
                'uuid' => $order->uuid, 'number' => $order->number, 'channel' => $order->channel,
                'status' => $order->status?->name, 'payment_status' => $order->paymentStatus?->name,
                'total_amount' => (float) $order->total_amount, 'currency' => $order->payment?->currency ?: $currency,
                'created_at' => $order->created_at->toIso8601String(),
            ])->all();

        return [
            'business' => ['uuid' => $business->uuid, 'name' => $business->name, 'is_active' => (bool) $business->is_active, 'timezone' => $timezone],
            'date' => $now->toDateString(), 'generated_at' => $now->toIso8601String(),
            'active_orders' => array_sum(array_intersect_key($counts, array_flip(['Pending', 'Awaiting payment', 'Scheduled', 'Processing', 'Ready', 'Served']))),
            'pending_approval' => $counts['Pending'] ?? 0,
            'completed_today' => $completedToday, 'completed_today_legacy_count' => $legacyCount,
            'verified_payments_today' => array_sum(array_column($receipts, 'count')),
            'awaiting_payment' => $awaitingPayment, 'status_counts' => $counts, 'receipts_today' => $receipts, 'recent_orders' => $recent,
        ];
    }
}
