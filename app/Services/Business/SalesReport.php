<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Support\ExcelWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SalesReport
{
    public function forBusiness(Business $business, array $filters, bool $export = false)
    {
        $overview = app(OverviewReport::class);
        $current = $overview->forBusiness($business, $filters);
        $from = CarbonImmutable::parse($current['date_from'], $current['timezone']);
        $to = CarbonImmutable::parse($current['date_to'], $current['timezone']);
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $previous = $overview->forBusiness($business, [
            ...$filters,
            'date_from' => $previousFrom->toDateString(),
            'date_to' => $previousTo->toDateString(),
        ]);

        $currentGroups = collect($current['summary']['currencies'] ?? [])->keyBy('currency');
        $previousGroups = collect($previous['summary']['currencies'] ?? [])->keyBy('currency');
        $business->loadMissing(['paymentSetting', 'district.city.country']);
        $defaultCurrency = $business->paymentSetting?->currency ?: $business->district?->city?->country?->currency ?: 'TZS';
        $latestPayment = DB::table('payments')->whereIn('order_id', DB::table('orders')->select('id')->where('business_id', $business->id))
            ->selectRaw('order_id, MAX(id) AS id')->groupBy('order_id');
        $orderCounts = DB::table('orders as o')->leftJoin('order_statuses as os', 'os.id', '=', 'o.order_status_id')
            ->leftJoin('payment_statuses as ps', 'ps.id', '=', 'o.payment_status_id')
            ->leftJoin('order_platform_fees as f', function ($join) use ($business) { $join->on('f.order_id', '=', 'o.id')->where('f.business_id', $business->id); })
            ->leftJoinSub($latestPayment, 'lp', 'lp.order_id', '=', 'o.id')->leftJoin('payments as p', 'p.id', '=', 'lp.id')
            ->where('o.business_id', $business->id)
            ->where('o.created_at', '>=', $from->startOfDay()->utc())->where('o.created_at', '<', $to->addDay()->startOfDay()->utc());
        foreach (['section_id', 'sub_section_id'] as $field) {
            if (! empty($filters[$field])) {
                $orderCounts->whereExists(fn ($q) => $q->selectRaw('1')->from('service_points')->whereColumn('service_points.id', 'o.service_point_id')
                    ->where('service_points.business_id', $business->id)->where('service_points.'.$field, $filters[$field]));
            }
        }
        $orderCounts = $orderCounts->selectRaw('COALESCE(f.currency, p.currency, ?) AS currency, COUNT(*) AS all_orders, SUM(CASE WHEN os.name IN (\'Cancelled\', \'Canceled\', \'Refunded\') OR ps.name = \'Refunded\' THEN 1 ELSE 0 END) AS excluded_orders', [$defaultCurrency])
            ->groupBy('f.currency', 'p.currency')->get()
            ->groupBy(fn ($row) => $row->currency ?: $defaultCurrency)
            ->map(fn ($rows, $currency) => (object) ['currency' => $currency, 'all_orders' => $rows->sum('all_orders'), 'excluded_orders' => $rows->sum('excluded_orders')]);

        $currencyCodes = $currentGroups->keys()->merge($previousGroups->keys())->merge($orderCounts->keys())->unique()->values();
        if ($currencyCodes->isEmpty()) {
            $currencyCodes->push($defaultCurrency);
        }

        $currencies = $currencyCodes->map(function ($currency) use ($currentGroups, $previousGroups, $orderCounts) {
            $now = $currentGroups->get($currency, []);
            $before = $previousGroups->get($currency, []);
            $counts = $orderCounts->get($currency, []);
            $sales = (float) ($now['confirmed_sales'] ?? 0);
            $previousSales = (float) ($before['confirmed_sales'] ?? 0);
            $paidOrders = (int) ($now['paid_orders'] ?? 0);

            return [
                'currency' => $currency,
                'orders' => (int) ($now['orders'] ?? 0),
                'all_orders' => (int) ($counts->all_orders ?? 0),
                'excluded_orders' => (int) ($counts->excluded_orders ?? 0),
                'paid_orders' => $paidOrders,
                'unpaid_orders' => (int) ($now['unpaid_orders'] ?? 0),
                'confirmed_sales' => $sales,
                'outstanding_order_value' => (float) ($now['unpaid_order_value'] ?? 0),
                'average_confirmed_order' => $paidOrders > 0 ? round($sales / $paidOrders, 2) : 0,
                'payments_received' => (float) ($now['payments_received'] ?? 0),
                'payment_count' => (int) ($now['payment_count'] ?? 0),
                'previous_confirmed_sales' => $previousSales,
                'sales_change_percent' => $previousSales > 0 ? round((($sales - $previousSales) / $previousSales) * 100, 1) : null,
            ];
        })->all();

        $daily = [];
        foreach ($currencyCodes as $currency) {
            $existing = collect($current['daily_by_currency'][$currency] ?? [])->keyBy('day');
            $rows = [];
            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                $row = $existing->get($date->toDateString(), []);
                $rows[] = ['day' => $date->toDateString(), 'orders' => (int) ($row['orders'] ?? 0), 'confirmed_sales' => (float) ($row['confirmed_sales'] ?? 0)];
            }
            $daily[$currency] = $rows;
        }

        $result = [
            'view' => 'sales', 'date_from' => $current['date_from'], 'date_to' => $current['date_to'], 'timezone' => $current['timezone'],
            'comparison' => ['date_from' => $previousFrom->toDateString(), 'date_to' => $previousTo->toDateString()],
            'currencies' => $currencies,
            'daily_by_currency' => $daily,
            'channels_by_currency' => $current['channels_by_currency'] ?? [],
        ];
        if (! $export) return $result;

        $rows = (function () use ($result) {
            foreach ($result['currencies'] as $summary) {
                yield ['Period summary', $result['date_from'], $result['date_to'], $result['timezone'], $summary['currency'], $summary['orders'], $summary['all_orders'], $summary['excluded_orders'], $summary['paid_orders'], $summary['confirmed_sales'], $summary['outstanding_order_value'], $summary['average_confirmed_order'], $summary['payments_received'], $summary['payment_count'], $summary['previous_confirmed_sales'], $summary['sales_change_percent'], null];
            }
            foreach ($result['daily_by_currency'] as $currency => $days) {
                foreach ($days as $day) yield ['Daily sales', $day['day'], $day['day'], $result['timezone'], $currency, $day['orders'], null, null, null, $day['confirmed_sales'], null, null, null, null, null, null, null];
            }
            foreach ($result['channels_by_currency'] as $currency => $channels) {
                foreach ($channels as $channel) yield ['Channel', $result['date_from'], $result['date_to'], $result['timezone'], $currency, $channel['orders'], null, null, null, $channel['confirmed_sales'], null, null, null, null, null, null, $channel['name']];
            }
        })();

        return ExcelWorkbook::download('Paperstic-sales-'.$result['date_from'].'-to-'.$result['date_to'].'.xlsx', ['Row type', 'From (local)', 'To (local)', 'Business timezone', 'Currency', 'Eligible orders', 'All orders', 'Cancelled/refunded orders excluded', 'Paid orders', 'Confirmed sales', 'Outstanding order value', 'Average confirmed order', 'Payments received', 'Successful payments', 'Previous period sales', 'Sales change %', 'Channel'], $rows);
    }
}
