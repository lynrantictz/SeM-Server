<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessInvoice;
use App\Services\Business\BusinessBillingService;
use App\Services\Business\BusinessProfileAccess;
use App\Support\ExcelWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessInvoiceTableController extends BaseController
{
    private function displayStatus(BusinessInvoice $invoice): string
    {
        if ($invoice->outstanding() <= 0) {
            return 'paid';
        }
        if (! $invoice->sent_at) {
            return 'draft';
        }
        if ($invoice->due_at?->lte(now())) {
            return 'overdue';
        }
        if ($invoice->due_at?->lte(now()->addHours(72))) {
            return 'due_soon';
        }

        return 'payment_due';
    }

    private function access(Business $business): void
    {
        abort_unless(app(BusinessBillingService::class)->installed(), 503, 'Billing migrations are required.');
        abort_unless(auth()->user()?->is_active && auth()->user()->type !== 'agent' && app(BusinessProfileAccess::class)->permissions($business)['can_manage_operations'], 403);
    }

    private function filters(Request $request, bool $orders = false): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'per_page' => ['nullable', 'integer', 'in:10,15,25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'direction' => ['nullable', 'in:asc,desc'],
            'sort' => ['nullable', $orders ? 'in:date,number,base,rate,fee' : 'in:number,issued,due,period,fee,outstanding'],
            ...($orders ? [
                'channel' => ['nullable', 'string', 'max:80'],
                'payment_method' => ['nullable', 'integer', 'min:1'],
                'order_status' => ['nullable', 'integer', 'min:1'],
                'payment_status' => ['nullable', 'integer', 'min:1'],
            ] : [
                'status' => ['nullable', 'in:all,outstanding,paid,overdue,draft'],
                'date_field' => ['nullable', 'in:issued,due,period'],
            ]),
        ]);
    }

    private function invoiceQuery(Business $business, array $data)
    {
        $query = BusinessInvoice::where('business_id', $business->id);
        if ($search = trim($data['search'] ?? '')) {
            $query->whereLike('number', '%'.$search.'%');
        }
        $status = $data['status'] ?? 'all';
        if (in_array($status, ['outstanding', 'overdue', 'draft'])) {
            $query->whereRaw('total > paid_amount + credit_amount');
        }
        if ($status === 'paid') {
            $query->whereRaw('total <= paid_amount + credit_amount');
        }
        if ($status === 'overdue') {
            $query->whereNotNull('sent_at')->where('due_at', '<=', now());
        }
        if ($status === 'draft') {
            $query->whereNull('sent_at');
        }
        $field = $data['date_field'] ?? 'issued';
        $column = $field === 'due' ? 'due_at' : 'created_at';
        if (! empty($data['from'])) {
            $query->where($field === 'period' ? 'period_to' : $column, $field === 'period' ? '>' : '>=', CarbonImmutable::parse($data['from'], 'UTC')->startOfDay());
        }
        if (! empty($data['to'])) {
            $query->where($field === 'period' ? 'period_from' : $column, '<', CarbonImmutable::parse($data['to'], 'UTC')->addDay()->startOfDay());
        }
        $sort = $data['sort'] ?? 'issued';
        $direction = $data['direction'] ?? 'desc';
        if ($sort === 'outstanding') {
            $query->orderByRaw('CASE WHEN total > paid_amount + credit_amount THEN total - paid_amount - credit_amount ELSE 0 END '.$direction);
        } else {
            $query->orderBy(['number' => 'number', 'issued' => 'created_at', 'due' => 'due_at', 'period' => 'period_from', 'fee' => 'total'][$sort], $direction);
        }

        return $query->orderBy('id', $direction);
    }

    public function invoices(Request $request, Business $business)
    {
        $this->access($business);
        $data = $this->filters($request);
        $rows = $this->invoiceQuery($business, $data)->paginate($data['per_page'] ?? 10)->through(fn ($invoice) => [
            'uuid' => $invoice->uuid, 'number' => $invoice->number, 'period_from' => $invoice->period_from, 'period_to' => $invoice->period_to,
            'created_at' => $invoice->created_at, 'due_at' => $invoice->due_at, 'currency' => $invoice->currency, 'total' => $invoice->total,
            'outstanding' => $invoice->outstanding(), 'status' => $this->displayStatus($invoice),
        ]);

        return $this->sendResponse(['invoices' => $rows], 'Invoices retrieved.');
    }

    public function export(Request $request, Business $business)
    {
        $this->access($business);
        $data = $this->filters($request);
        $rows = (function () use ($business, $data) {
            foreach ($this->invoiceQuery($business, $data)->lazy(500) as $invoice) {
                $summary = $invoice->summary();
                yield [$invoice->number, $invoice->period_from?->toIso8601String(), $invoice->period_to?->toIso8601String(), $invoice->created_at?->toIso8601String(), $invoice->due_at?->toIso8601String(), $this->displayStatus($invoice), $invoice->currency, $summary['order_count'], $summary['gross_sales'] === null ? null : (float) $summary['gross_sales'], (float) $invoice->total, (float) $invoice->paid_amount, (float) $invoice->credit_amount, $invoice->outstanding()];
            }
        })();

        return ExcelWorkbook::download('Paperstic-invoices.xlsx', ['Invoice', 'Billing period start (UTC)', 'Billing period end (exclusive, UTC)', 'Issued on (UTC)', 'Payment due (UTC)', 'Status', 'Currency', 'Orders', 'Gross sales', 'Paperstic fee', 'Paid', 'Credits', 'Outstanding'], $rows);
    }

    private function ordersQuery(BusinessInvoice $invoice)
    {
        return DB::table('business_invoice_items as items')
            ->leftJoin('order_platform_fees as fees', 'fees.id', '=', 'items.order_platform_fee_id')
            ->leftJoin('orders', function ($join) use ($invoice) {
                $join->on('orders.id', '=', 'fees.order_id')->where('orders.business_id', $invoice->business_id);
            })
            ->leftJoin('ordering_channels as channels', 'channels.slug', '=', 'orders.channel')
            ->leftJoin('payment_methods as methods', 'methods.id', '=', 'orders.payment_method_id')
            ->leftJoin('order_statuses as statuses', 'statuses.id', '=', 'orders.order_status_id')
            ->leftJoin('payment_statuses as payment_statuses', 'payment_statuses.id', '=', 'orders.payment_status_id')
            ->where('items.business_invoice_id', $invoice->id);
    }

    private function filterOrders($query, array $data)
    {
        if ($search = trim($data['search'] ?? '')) {
            $query->whereLike('items.order_number', '%'.$search.'%');
        }
        foreach (['channel' => 'orders.channel', 'payment_method' => 'orders.payment_method_id', 'order_status' => 'orders.order_status_id', 'payment_status' => 'orders.payment_status_id'] as $key => $column) {
            if (! empty($data[$key])) {
                $query->where($column, $data[$key]);
            }
        }
        if (! empty($data['from'])) {
            $query->where('orders.created_at', '>=', CarbonImmutable::parse($data['from'], 'UTC')->startOfDay());
        }
        if (! empty($data['to'])) {
            $query->where('orders.created_at', '<', CarbonImmutable::parse($data['to'], 'UTC')->addDay()->startOfDay());
        }

        return $query->orderBy(['date' => 'orders.created_at', 'number' => 'items.order_number', 'base' => 'items.base_amount', 'rate' => 'items.rate', 'fee' => 'items.amount'][$data['sort'] ?? 'date'], $data['direction'] ?? 'desc')->orderBy('items.id', $data['direction'] ?? 'desc');
    }

    private function selectedOrders($query)
    {
        return $query->select(['items.uuid', 'items.order_number', 'items.base_amount', 'items.rate', 'items.amount', 'orders.id as order_id', 'orders.created_at as order_date', 'channels.name as channel', 'methods.name as payment_method', 'statuses.name as order_status', 'payment_statuses.name as payment_status']);
    }

    /** Use the same latest-payment account association as the payment report. */
    private function addReceivingAccounts($rows, $accounts): void
    {
        $ids = $rows->pluck('order_id')->filter()->unique();
        $latest = DB::table('payments')->whereIn('order_id', $ids)->selectRaw('MAX(id)')->groupBy('order_id');
        $payments = DB::table('payments')->whereIn('id', $latest)->get(['order_id', 'metadata'])->keyBy('order_id');
        foreach ($rows as $row) {
            $metadata = json_decode($payments->get($row->order_id)?->metadata ?? '{}', true);
            $account = $accounts->get($metadata['payment_account_uuid'] ?? '');
            $row->receiving_account_name = $account?->account_holder_name;
            $row->receiving_account_number = $account?->account_number;
            unset($row->order_id);
        }
    }

    private function accounts(Business $business)
    {
        return $business->paymentMethods()->with('accounts')->get()->flatMap->accounts->keyBy('uuid');
    }

    private function checkInvoice(Business $business, BusinessInvoice $invoice): void
    {
        $this->access($business);
        abort_unless($invoice->business_id === $business->id, 404);
    }

    public function orders(Request $request, Business $business, BusinessInvoice $invoice)
    {
        $this->checkInvoice($business, $invoice);
        $data = $this->filters($request, true);
        $query = $this->ordersQuery($invoice);
        $options = [];
        foreach (['channels' => ['orders.channel', 'channels.name'], 'methods' => ['orders.payment_method_id', 'methods.name'], 'order_statuses' => ['orders.order_status_id', 'statuses.name'], 'payment_statuses' => ['orders.payment_status_id', 'payment_statuses.name']] as $key => [$id, $name]) {
            $options[$key] = (clone $query)->whereNotNull($id)->select($id.' as value', $name.' as label')->distinct()->orderBy($name)->get();
        }
        $rows = $this->selectedOrders($this->filterOrders($query, $data))->paginate($data['per_page'] ?? 10);
        $this->addReceivingAccounts($rows->getCollection(), $this->accounts($business));
        $rows->through(function ($row) {
            $row->order_date = $row->order_date ? CarbonImmutable::parse($row->order_date, 'UTC')->toIso8601String() : null;

            return $row;
        });

        return $this->sendResponse(['orders' => $rows, 'options' => $options], 'Invoice order fees retrieved.');
    }

    public function exportOrders(Request $request, Business $business, BusinessInvoice $invoice)
    {
        $this->checkInvoice($business, $invoice);
        $data = $this->filters($request, true);
        $rows = (function () use ($invoice, $data, $business) {
            $accounts = $this->accounts($business);
            foreach ($this->selectedOrders($this->filterOrders($this->ordersQuery($invoice), $data))->lazy(500)->chunk(500) as $items) {
                $this->addReceivingAccounts($items, $accounts);
                foreach ($items as $item) {
                    yield [$invoice->number, $item->order_number, $item->order_date ? CarbonImmutable::parse($item->order_date, 'UTC')->toIso8601String() : null, $item->channel, $item->payment_method, $item->receiving_account_name, $item->receiving_account_number, $item->order_status, $item->payment_status, $invoice->currency, (float) $item->base_amount, (float) $item->rate, (float) $item->amount];
                }
            }
        })();

        return ExcelWorkbook::download('Paperstic-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $invoice->number).'-orders.xlsx', ['Invoice', 'Order', 'Order date (UTC)', 'Ordering channel (current)', 'Payment method (current)', 'Registered account name (current)', 'Receiving account number (current)', 'Order status (current)', 'Payment status (current)', 'Currency', 'Invoiced commission base', 'Invoiced commission rate (%)', 'Invoiced Paperstic fee'], $rows);
    }
}
