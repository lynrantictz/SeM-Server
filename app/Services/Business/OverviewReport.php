<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OverviewReport
{
    public function forBusiness(Business $business, array $filters): array
    {
        $business->loadMissing(['timezoneDefinition', 'paymentSetting', 'district.city.country']);
        $timezone = $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC';
        if (! in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'UTC';
        $today = CarbonImmutable::now($timezone);
        $from = $filters['date_from'] ?? $today->subDays(29)->toDateString();
        $to = $filters['date_to'] ?? $today->toDateString();
        $currency = $business->paymentSetting?->currency ?: $business->district?->city?->country?->currency ?: 'TZS';
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc();
        $latestPayment = DB::table('payments')->whereIn('order_id', DB::table('orders')->select('id')->where('business_id', $business->id))
            ->selectRaw('order_id, MAX(id) AS id')->groupBy('order_id');
        $base = DB::table('orders as o')->leftJoin('order_statuses as os', 'os.id', '=', 'o.order_status_id')
            ->leftJoin('payment_statuses as ps', 'ps.id', '=', 'o.payment_status_id')
            ->leftJoin('order_platform_fees as f', function ($join) use ($business) { $join->on('f.order_id', '=', 'o.id')->where('f.business_id', $business->id); })
            ->leftJoinSub($latestPayment, 'lp', 'lp.order_id', '=', 'o.id')->leftJoin('payments as p', 'p.id', '=', 'lp.id')
            ->leftJoin('service_points as point', function ($join) use ($business) { $join->on('point.id', '=', 'o.service_point_id')->where('point.business_id', $business->id); })
            ->where('o.business_id', $business->id)->where('o.created_at', '>=', $start)->where('o.created_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('os.name')->orWhereNotIn('os.name', ['Cancelled', 'Canceled', 'Refunded']))
            ->where(fn ($q) => $q->whereNull('ps.name')->orWhereNotIn('ps.name', ['Refunded']));
        foreach (['section_id', 'sub_section_id'] as $field) if (! empty($filters[$field])) $base->where('point.'.$field, $filters[$field]);

        $groups = [];
        $statusCounts = $channelCounts = $daily = [];
        $methodCounts = [];
        $eligible = (clone $base)->leftJoin('payment_methods as pm', 'pm.id', '=', 'o.payment_method_id')
            ->select(['o.id', 'o.number', 'o.created_at', 'o.total_amount', 'o.tax_amount', 'o.paid_amount', 'o.due_amount', 'o.channel', 'os.name as order_status', 'ps.name as payment_status', 'f.currency as fee_currency', 'f.amount as fee_amount', 'f.rate as fee_rate', 'f.status as fee_status', 'p.currency as payment_currency', 'p.status as latest_payment_status', 'pm.name as method'])
            ->selectRaw('COALESCE(f.currency, p.currency, ?) AS report_currency', [$currency]);

        foreach ($eligible->cursor() as $order) {
            $code = $order->report_currency ?: $currency;
            $groups[$code] ??= ['currency' => $code, 'orders' => 0, 'paid_orders' => 0, 'unpaid_orders' => 0, 'completed_orders' => 0, 'confirmed_sales' => 0.0, 'unpaid_order_value' => 0.0, 'tax' => 0.0, 'billable_fees' => 0.0, 'estimated_fees' => 0.0, 'missing_fee_orders' => 0, 'payments_received' => 0.0, 'payment_count' => 0];
            $amount = (int) round((float) $order->total_amount * 100);
            $paid = (int) round((float) ($order->paid_amount ?? 0) * 100);
            $due = $order->due_amount === null ? max(0, $amount - $paid) : max(0, (int) round((float) $order->due_amount * 100));
            $paidStatus = in_array($order->payment_status, ['Completed', 'Paid', 'Successful'], true);
            $group =& $groups[$code];
            $group['orders']++;
            $group['tax'] += (int) round((float) $order->tax_amount * 100);
            if ($paidStatus) { $group['paid_orders']++; $group['confirmed_sales'] += $amount; }
            if ($due > 0) { $group['unpaid_orders']++; $group['unpaid_order_value'] += $due; }
            if ($order->order_status === 'Completed') $group['completed_orders']++;
            if ($order->fee_status === 'accrued' || $order->fee_status === 'invoiced') $group['billable_fees'] += (int) round((float) $order->fee_amount * 100);
            elseif ($order->fee_status === 'estimated') $group['estimated_fees'] += (int) round((float) $order->fee_amount * 100);
            elseif ($order->fee_status === null && $paidStatus) $group['missing_fee_orders']++;
            unset($group);

            $day = CarbonImmutable::parse($order->created_at, 'UTC')->setTimezone($timezone)->toDateString();
            $daily[$code][$day] ??= ['day' => $day, 'orders' => 0, 'confirmed_sales' => 0.0];
            $daily[$code][$day]['orders']++;
            if ($paidStatus) $daily[$code][$day]['confirmed_sales'] += $amount;
            $statusCounts[$order->order_status ?: 'Not recorded'] = ($statusCounts[$order->order_status ?: 'Not recorded'] ?? 0) + 1;
            $channel = $order->channel ?: 'Not recorded';
            $channelCounts[$code][$channel] ??= ['name' => $channel, 'orders' => 0, 'confirmed_sales' => 0.0];
            $channelCounts[$code][$channel]['orders']++;
            if ($paidStatus) $channelCounts[$code][$channel]['confirmed_sales'] += $amount;
            if ($paidStatus) {
                $method = $order->method ?: 'Not recorded';
                $methodCounts[$code][$method] ??= ['name' => $method, 'orders' => 0, 'confirmed_sales' => 0.0];
                $methodCounts[$code][$method]['orders']++;
                $methodCounts[$code][$method]['confirmed_sales'] += $amount;
            }
        }

        $latestSuccessfulPayment = DB::table('payments')->where('status', 'SUCCESS')->whereIn('order_id', DB::table('orders')->select('id')->where('business_id', $business->id))
            ->selectRaw('order_id, MAX(id) AS id')->groupBy('order_id');
        $receiptLatest = DB::table('payments as p')->joinSub($latestSuccessfulPayment, 'lp', 'lp.id', '=', 'p.id')
            ->join('orders as o', 'o.id', '=', 'p.order_id')->where('o.business_id', $business->id)->where('p.status', 'SUCCESS')
            ->whereRaw('COALESCE(p.confirmed_at, p.paid_at) >= ?', [$start])->whereRaw('COALESCE(p.confirmed_at, p.paid_at) < ?', [$end]);
        foreach (['section_id', 'sub_section_id'] as $field) if (! empty($filters[$field])) $receiptLatest->whereExists(fn ($q) => $q->selectRaw('1')->from('service_points')->whereColumn('service_points.id', 'o.service_point_id')->where('service_points.business_id', $business->id)->where('service_points.'.$field, $filters[$field]));
        foreach ($receiptLatest->selectRaw('p.currency, SUM(p.amount) AS amount, COUNT(*) AS count')->groupBy('p.currency')->get() as $receipt) {
            $code = $receipt->currency ?: $currency;
            $groups[$code] ??= ['currency' => $code, 'orders' => 0, 'paid_orders' => 0, 'unpaid_orders' => 0, 'completed_orders' => 0, 'confirmed_sales' => 0.0, 'unpaid_order_value' => 0.0, 'tax' => 0.0, 'billable_fees' => 0.0, 'estimated_fees' => 0.0, 'missing_fee_orders' => 0, 'payments_received' => 0.0, 'payment_count' => 0];
            $groups[$code]['payments_received'] = (int) round((float) $receipt->amount * 100);
            $groups[$code]['payment_count'] = (int) $receipt->count;
        }

        $topItems = DB::table('order_items as l')->join('orders as o', 'o.id', '=', 'l.order_id')->leftJoin('order_statuses as os', 'os.id', '=', 'o.order_status_id')->leftJoin('payment_statuses as ps', 'ps.id', '=', 'o.payment_status_id')->leftJoin('items as i', 'i.id', '=', 'l.item_id')->leftJoin('order_platform_fees as f', function ($join) use ($business) { $join->on('f.order_id', '=', 'o.id')->where('f.business_id', $business->id); })
            ->where('o.business_id', $business->id)->where('o.created_at', '>=', $start)->where('o.created_at', '<', $end)->whereIn('os.name', ['Completed', 'Served'])->whereIn('ps.name', ['Completed', 'Paid', 'Successful'])
            ->where(fn ($q) => $q->whereNull('os.name')->orWhereNotIn('os.name', ['Cancelled', 'Canceled', 'Refunded']))
            ->where(fn ($q) => $q->whereNull('ps.name')->orWhereNotIn('ps.name', ['Refunded']));
        foreach (['section_id', 'sub_section_id'] as $field) if (! empty($filters[$field])) $topItems->whereExists(fn ($q) => $q->selectRaw('1')->from('service_points')->whereColumn('service_points.id', 'o.service_point_id')->where('service_points.business_id', $business->id)->where('service_points.'.$field, $filters[$field]));
        $topItems = $topItems->selectRaw('COALESCE(i.name, ?) AS name, COALESCE(f.currency, ?) AS currency, SUM(l.quantity) AS quantity, SUM(l.total_amount) AS revenue, COUNT(DISTINCT o.id) AS orders', ['Menu item', $currency])->groupBy('i.name', 'f.currency')->orderByDesc('revenue')->get()
            ->groupBy('currency')->flatMap(fn ($items) => $items->take(10))->values();

        $toMoney = fn ($group) => collect($group)->map(fn ($value, $key) => in_array($key, ['confirmed_sales', 'unpaid_order_value', 'tax', 'billable_fees', 'estimated_fees', 'payments_received'], true) ? round($value / 100, 2) : $value)->all();
        return ['view' => 'overview', 'date_from' => $from, 'date_to' => $to, 'timezone' => $timezone,
            'summary' => ['currencies' => array_values(array_map($toMoney, $groups)), 'status_counts' => $statusCounts],
            'daily_by_currency' => array_map(fn ($rows) => array_values(array_map($toMoney, $rows)), $daily),
            'channels_by_currency' => array_map(fn ($rows) => array_values(array_map($toMoney, $rows)), $channelCounts),
            'payment_methods_by_currency' => array_map(fn ($rows) => array_values(array_map($toMoney, $rows)), $methodCounts),
            'top_items' => $topItems];
    }
}
