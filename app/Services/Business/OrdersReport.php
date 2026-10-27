<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Support\ExcelWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OrdersReport
{
    private function prepare(Business $business, array $filters): array
    {
        $business->loadMissing(['timezoneDefinition', 'paymentSetting', 'district.city.country']);
        $timezone = $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone);
        $from = $filters['date_from'] ?? $today->subDays(29)->toDateString();
        $to = $filters['date_to'] ?? $today->toDateString();
        $currency = $business->paymentSetting?->currency ?: $business->district?->city?->country?->currency ?: 'TZS';
        $latest = DB::table('payments')->whereIn('order_id', DB::table('orders')->select('id')->where('business_id', $business->id))->selectRaw('order_id, MAX(id) AS id')->groupBy('order_id');
        $query = DB::table('orders')
            ->leftJoinSub($latest, 'latest_payment', 'latest_payment.order_id', '=', 'orders.id')
            ->leftJoin('payments', 'payments.id', '=', 'latest_payment.id')
            ->leftJoin('ordering_channels', 'ordering_channels.slug', '=', 'orders.channel')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'orders.payment_method_id')
            ->leftJoin('order_statuses', 'order_statuses.id', '=', 'orders.order_status_id')
            ->leftJoin('payment_statuses', 'payment_statuses.id', '=', 'orders.payment_status_id')
            ->leftJoin('order_platform_fees as fees', function ($join) use ($business) {
                $join->on('fees.order_id', '=', 'orders.id')->where('fees.business_id', $business->id);
            })
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('orders.business_id', $business->id)
            ->where('orders.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc())
            ->where('orders.created_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc());
        if (! empty($filters['section_id']) || ! empty($filters['sub_section_id'])) {
            $query->whereExists(function ($point) use ($business, $filters) {
                $point->selectRaw('1')->from('service_points')->whereColumn('service_points.id', 'orders.service_point_id')->where('service_points.business_id', $business->id)
                    ->when(! empty($filters['section_id']), fn ($q) => $q->where('service_points.section_id', $filters['section_id']))
                    ->when(! empty($filters['sub_section_id']), fn ($q) => $q->where('service_points.sub_section_id', $filters['sub_section_id']));
            });
        }

        return [$query, $timezone, $from, $to, $currency];
    }

    private function options($query): array
    {
        $options = [];
        foreach (['channels' => ['orders.channel', 'ordering_channels.name'], 'methods' => ['orders.payment_method_id', 'payment_methods.name'], 'order_statuses' => ['orders.order_status_id', 'order_statuses.name'], 'payment_statuses' => ['orders.payment_status_id', 'payment_statuses.name']] as $key => [$id, $name]) {
            $options[$key] = (clone $query)->whereNotNull($id)->select($id.' as value', $name.' as label')->distinct()->orderBy($name)->get();
        }

        return $options;
    }

    private function filter($query, array $filters)
    {
        foreach (['channel' => 'orders.channel', 'payment_method' => 'orders.payment_method_id', 'order_status_id' => 'orders.order_status_id', 'payment_status_id' => 'orders.payment_status_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->whereLike('orders.number', '%'.$search.'%')->orWhereLike('customers.phone_e164', '%'.$search.'%')->orWhereLike('customers.phone', '%'.$search.'%'));
        }
        $sort = ['date' => 'orders.created_at', 'number' => 'orders.number', 'total' => 'orders.total_amount', 'paid' => 'orders.paid_amount', 'due' => 'orders.due_amount', 'base' => 'fees.base_amount', 'rate' => 'fees.rate', 'fee' => 'fees.amount'][$filters['sort'] ?? 'date'];
        $direction = $filters['direction'] ?? 'desc';

        return $query->orderBy($sort, $direction)->orderBy('orders.id', $direction);
    }

    private function select($query)
    {
        return $query->select(['orders.uuid', 'orders.number', 'orders.created_at', 'orders.channel as channel_code', 'ordering_channels.name as channel', 'order_statuses.name as status', 'payment_statuses.name as payment_status', 'payment_methods.name as payment_method', 'orders.total_amount', 'orders.tax_amount', 'orders.paid_amount', 'orders.due_amount', 'customers.phone_e164', 'customers.phone', 'fees.currency as fee_currency', 'fees.base_amount as commission_base', 'fees.rate as commission_rate', 'fees.amount as commission_amount', 'fees.status as fee_status', 'payments.currency as payment_currency', 'payments.metadata']);
    }

    private function accounts(Business $business)
    {
        return $business->paymentMethods()->with('accounts')->get()->flatMap->accounts->keyBy('uuid');
    }

    private function map($row, $accounts, string $currency): array
    {
        $metadata = json_decode($row->metadata ?? '{}', true);
        $account = $accounts->get($metadata['payment_account_uuid'] ?? '');
        $phone = $row->phone_e164 ?: $row->phone;
        $finished = in_array(strtolower((string) $row->payment_status), ['completed', 'paid', 'successful'], true) || in_array(strtolower((string) $row->status), ['served', 'completed'], true);
        if ($phone && $finished) {
            $digits = preg_replace('/\D+/', '', $phone) ?: '';
            $phone = strlen($digits) > 6 ? '+'.substr($digits, 0, 3).' '.str_repeat('•', strlen($digits) - 6).' '.substr($digits, -3) : '••••••';
        }

        return ['uuid' => $row->uuid, 'number' => $row->number, 'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
            'channel' => $row->channel ?: $row->channel_code, 'status' => $row->status, 'payment_status' => $row->payment_status, 'payment_method' => $row->payment_method,
            'receiving_account_name' => $account?->account_holder_name, 'receiving_account_number' => $account?->account_number,
            'customer_phone' => $phone, 'currency' => $row->fee_currency ?: ($row->payment_currency ?: $currency),
            'total_amount' => (float) $row->total_amount, 'tax_amount' => (float) $row->tax_amount, 'paid_amount' => (float) ($row->paid_amount ?? 0), 'due_amount' => (float) ($row->due_amount ?? 0),
            'commission_base' => $row->commission_base === null ? null : (float) $row->commission_base, 'commission_rate' => $row->commission_rate === null ? null : (float) $row->commission_rate,
            'commission_amount' => $row->commission_amount === null ? null : (float) $row->commission_amount, 'fee_status' => $row->fee_status];
    }

    public function forBusiness(Business $business, array $filters): array
    {
        [$query, $timezone, $from, $to, $currency] = $this->prepare($business, $filters);
        $options = $this->options($query);
        $page = $this->select($this->filter($query, $filters))->paginate($filters['per_page'] ?? 10);
        $accounts = $this->accounts($business);
        $page->through(fn ($row) => $this->map($row, $accounts, $currency));

        return ['view' => 'orders', 'date_from' => $from, 'date_to' => $to, 'timezone' => $timezone, 'options' => $options,
            'orders' => ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]];
    }

    public function export(Business $business, array $filters)
    {
        [$query, $timezone, $from, $to, $currency] = $this->prepare($business, $filters);
        $rows = (function () use ($business, $query, $filters, $currency) {
            $accounts = $this->accounts($business);
            foreach ($this->select($this->filter($query, $filters))->lazy(500) as $item) {
                $row = $this->map($item, $accounts, $currency);
                yield [$row['number'], $row['created_at'], $row['channel'], $row['payment_method'], $row['receiving_account_name'], $row['receiving_account_number'], $row['status'], $row['payment_status'], $row['currency'], $row['total_amount'], $row['paid_amount'], $row['due_amount'], $row['commission_base'], $row['commission_rate'], $row['commission_amount'], $row['fee_status']];
            }
        })();

        return ExcelWorkbook::download('Paperstic-orders-'.$from.'-to-'.$to.'.xlsx', ['Order', 'Order date (UTC)', 'Channel (current)', 'Payment method (current)', 'Registered account name (current)', 'Account number (current)', 'Order status (current)', 'Payment status (current)', 'Currency', 'Order total', 'Paid', 'Due', 'Commission base', 'Saved commission rate (%)', 'Paperstic fee', 'Fee status'], $rows);
    }
}
