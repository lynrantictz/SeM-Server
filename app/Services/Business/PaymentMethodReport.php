<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Support\ExcelWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PaymentMethodReport
{
    public function forBusiness(Business $business, array $filters, bool $export = false)
    {
        $business->loadMissing(['timezoneDefinition', 'paymentSetting', 'district.city.country']);
        $timezone = $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone);
        $from = $filters['date_from'] ?? $today->subDays(29)->toDateString();
        $to = $filters['date_to'] ?? $today->toDateString();
        $currency = $business->paymentSetting?->currency ?: $business->district?->city?->country?->currency ?: 'TZS';
        $latest = DB::table('payments')->whereIn('order_id', DB::table('orders')->select('id')->where('business_id', $business->id))->selectRaw('order_id, MAX(id) AS id')->groupBy('order_id');
        // Only the latest payment is joined; retries must never duplicate sales or fees.
        $base = DB::table('orders as o')->leftJoinSub($latest, 'latest_payment', 'latest_payment.order_id', '=', 'o.id')
            ->leftJoin('payments as p', 'p.id', '=', 'latest_payment.id')
            ->leftJoin('ordering_channels as channels', 'channels.slug', '=', 'o.channel')
            ->leftJoin('payment_methods as m', 'm.id', '=', 'o.payment_method_id')
            ->leftJoin('payment_statuses as ps', 'ps.id', '=', 'o.payment_status_id')
            ->leftJoin('order_statuses as os', 'os.id', '=', 'o.order_status_id')
            ->leftJoin('order_platform_fees as f', function ($join) use ($business) {
                $join->on('f.order_id', '=', 'o.id')->where('f.business_id', $business->id);
            })
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.business_id', $business->id)
            ->where('o.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc())
            ->where('o.created_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc());
        foreach (['section_id', 'sub_section_id'] as $column) {
            if (! empty($filters[$column])) {
                $base->whereExists(fn ($q) => $q->selectRaw('1')->from('service_points')->whereColumn('service_points.id', 'o.service_point_id')->where('service_points.business_id', $business->id)->where('service_points.'.$column, $filters[$column]));
            }
        }
        $method = "COALESCE(m.name, p.provider, 'Not selected')";
        $key = "COALESCE(CAST(m.id AS VARCHAR), p.provider, 'unselected')";
        $money = 'COALESCE(f.currency, p.currency, '.DB::connection()->getPdo()->quote($currency).')';
        $options = ['order_statuses' => (clone $base)->whereNotNull('os.name')->distinct()->orderBy('os.name')->pluck('os.name'), 'payment_statuses' => (clone $base)->whereNotNull('ps.name')->distinct()->orderBy('ps.name')->pluck('ps.name')];
        $filterOptions = [];
        foreach (['channels' => ['o.channel', 'channels.name'], 'methods' => ['o.payment_method_id', 'm.name'], 'order_statuses' => ['o.order_status_id', 'os.name'], 'payment_statuses' => ['o.payment_status_id', 'ps.name']] as $type => [$id, $name]) {
            $filterOptions[$type] = (clone $base)->whereNotNull($id)->select($id.' as value', $name.' as label')->distinct()->orderBy($name)->get();
        }
        foreach (['channel' => 'o.channel', 'payment_method' => 'o.payment_method_id', 'order_status_id' => 'o.order_status_id', 'payment_status_id' => 'o.payment_status_id'] as $field => $column) {
            if (! empty($filters[$field])) {
                $base->where($column, $filters[$field]);
            }
        }
        $detail = array_key_exists('payment_method_key', $filters);
        if ($detail) {
            $base->whereRaw($key.' = ?', [$filters['payment_method_key']]);
            if (! empty($filters['currency'])) {
                $base->whereRaw($money.' = ?', [$filters['currency']]);
            }
            if (! empty($filters['payment_status'])) {
                $base->where('ps.name', $filters['payment_status']);
            }
            if (! empty($filters['order_status'])) {
                $base->where('os.name', $filters['order_status']);
            }
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $term = '%'.strtolower($search).'%';
            $base->where(function (Builder $q) use ($detail, $method, $term) {
                if (! $detail) {
                    $q->whereRaw('LOWER('.$method.') LIKE ?', [$term]);

                    return;
                }
                foreach (['o.number', 'c.phone_e164', 'c.phone', 'p.external_id', 'p.transaction_id', 'p.mno_reference'] as $column) {
                    $q->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            });
        }
        $direction = $filters['direction'] ?? ($detail ? 'desc' : 'asc');
        if ($detail) {
            $sort = ['date' => 'o.created_at', 'number' => 'o.number', 'total' => 'o.total_amount', 'paid' => 'o.paid_amount', 'due' => 'o.due_amount', 'base' => 'f.base_amount', 'rate' => 'f.rate', 'fee' => 'f.amount'][$filters['sort'] ?? 'date'];
            $query = (clone $base)->select(['o.uuid', 'o.number', 'o.created_at', 'o.channel as channel_code', 'channels.name as channel', 'o.total_amount', 'o.paid_amount', 'o.due_amount', 'c.phone_e164 as customer_e164', 'c.phone as customer_phone', 'os.name as order_status', 'ps.name as payment_status', 'p.external_id as payment_reference', 'p.metadata', 'f.base_amount as commission_base', 'f.rate', 'f.amount as commission_amount', 'f.status as fee_status'])
                ->selectRaw($money.' AS currency')->orderBy($sort, $direction)->orderBy('o.id', $direction);
            $accounts = $business->paymentMethods()->with('accounts')->get()->flatMap->accounts->keyBy('uuid');
            $map = function ($row) use ($accounts) {
                $phone = $row->customer_e164 ?: $row->customer_phone;
                $digits = preg_replace('/\D+/', '', $phone ?? '');
                $row->customer = strlen($digits) > 6 ? '+'.substr($digits, 0, 3).' •••••• '.substr($digits, -3) : ($phone ? '••••••' : null);
                unset($row->customer_e164, $row->customer_phone);
                $metadata = json_decode($row->metadata ?? '{}', true);
                $account = $accounts->get($metadata['payment_account_uuid'] ?? '');
                $row->receiving_account = $account ? collect([$account->account_holder_name, $account->account_number])->filter()->join(' · ') : null;
                $row->account_name = $account?->account_holder_name;
                $row->account_number = $account?->account_number;
                $row->channel = $row->channel ?: $row->channel_code;
                unset($row->metadata, $row->channel_code);
                $row->created_at = CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String();

                return $row;
            };
            if ($export) {
                $rows = (function () use ($query, $map) {
                    foreach ($query->lazy(500) as $item) {
                        $row = $map($item);
                        yield [$row->number, $row->created_at, $row->channel, $row->account_name, $row->account_number, $row->order_status, $row->payment_status, $row->payment_reference, $row->currency, (float) $row->total_amount, (float) $row->paid_amount, (float) $row->due_amount, $row->commission_base === null ? null : (float) $row->commission_base, $row->rate === null ? null : (float) $row->rate, $row->commission_amount === null ? null : (float) $row->commission_amount, $row->fee_status];
                    }
                })();

                return ExcelWorkbook::download('Paperstic-payment-orders-'.$from.'-to-'.$to.'.xlsx', ['Order', 'Order date (UTC)', 'Channel', 'Registered account name (current)', 'Account number (current)', 'Order status', 'Payment status', 'Payment reference', 'Currency', 'Order total', 'Paid', 'Due', 'Commission base', 'Saved commission rate (%)', 'Paperstic fee', 'Fee status'], $rows);
            }
            $page = $query->paginate($filters['per_page'] ?? 10)->through($map);
        } else {
            $sort = ['method' => 'method', 'orders' => 'orders', 'total' => 'order_total', 'paid' => 'confirmed_amount', 'due' => 'pending_amount', 'fee' => 'commission_billable'][$filters['sort'] ?? 'method'];
            $query = (clone $base)->selectRaw($key.' AS method_key, '.$method.' AS method, '.$money.' AS currency')
                ->selectRaw("COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS order_total, COALESCE(SUM(o.paid_amount), 0) AS confirmed_amount, COALESCE(SUM(CASE WHEN os.name IN ('Cancelled', 'Refunded') THEN 0 ELSE COALESCE(o.due_amount, o.total_amount - COALESCE(o.paid_amount, 0)) END), 0) AS pending_amount")
                ->selectRaw("SUM(CASE WHEN f.status IN ('accrued', 'invoiced') THEN f.amount ELSE 0 END) AS commission_billable, SUM(CASE WHEN f.status = 'estimated' THEN f.amount ELSE 0 END) AS commission_estimated, SUM(CASE WHEN f.status = 'credited' THEN f.amount ELSE 0 END) AS commission_credited, SUM(CASE WHEN f.id IS NULL THEN 1 ELSE 0 END) AS missing_fees")
                ->groupByRaw($key.', '.$method.', '.$money)->orderBy($sort, $direction)->orderBy('method')->orderBy('currency');
            $page = $export ? null : $query->paginate($filters['per_page'] ?? 10);
            $summary = $export ? $query->get() : collect($page->items());
            $keys = $summary->pluck('method_key');
            $rates = (clone $base)->whereRaw($key.' IN ('.implode(',', array_fill(0, max(1, $keys->count()), '?')).')', $keys->isEmpty() ? [''] : $keys->all())
                ->selectRaw($key.' AS method_key, '.$money.' AS currency, f.rate, COUNT(*) AS orders')->whereNotNull('f.id')->groupByRaw($key.', '.$money.', f.rate')->get();
            $map = function ($row) use ($rates) {
                $row->rates = $rates->filter(fn ($rate) => $rate->method_key === $row->method_key && $rate->currency === $row->currency)->map(fn ($rate) => ['rate' => (float) $rate->rate, 'orders' => (int) $rate->orders])->values();

                return $row;
            };
            if ($export) {
                $rows = $summary->map(function ($item) use ($map, $from, $to) {
                    $row = $map($item);

                    return [$from, $to, $row->method, $row->currency, (int) $row->orders, (float) $row->order_total, (float) $row->confirmed_amount, (float) $row->pending_amount, $row->rates->map(fn ($rate) => $rate['rate'].'% ('.$rate['orders'].')')->join(', '), (float) $row->commission_billable, (float) $row->commission_estimated, (float) $row->commission_credited, (int) $row->missing_fees];
                });

                return ExcelWorkbook::download('Paperstic-payments-'.$from.'-to-'.$to.'.xlsx', ['From (local)', 'To (local)', 'Payment method', 'Currency', 'Orders', 'Order total', 'Confirmed', 'Pending', 'Saved rates (order count)', 'Billable / invoiced fees', 'Estimated fees', 'Credited fees', 'Orders with no recorded fee'], $rows);
            }
            $page->through($map);
        }

        return ['date_from' => $from, 'date_to' => $to, 'timezone' => $timezone, ...$options, 'options' => $filterOptions, 'rows' => ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]];
    }
}
