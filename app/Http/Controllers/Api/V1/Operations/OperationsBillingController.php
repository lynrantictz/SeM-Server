<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\BusinessInvoice;
use App\Services\Business\BusinessBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsBillingController extends BaseController
{
    private function liveRestricted()
    {
        return \App\Models\Business\Business::query()->whereIn('id', function ($query) {
            $query->select('business_id')->from('business_billing_policies')->where('automatic_invoicing', true)->where('enforce_overdue', true);
        })->whereExists(function ($query) {
            $query->selectRaw('1')->from('business_invoices')->whereColumn('business_invoices.business_id', 'businesses.id')->whereNotNull('sent_at')->where('due_at', '<=', now())->whereRaw('total > paid_amount + credit_amount');
        });
    }

    public function restrictions(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->is_active && $user->type !== 'agent' && $user->can('operations.businesses.view') && ($user->can('operations.settlements.view') || $user->can('operations.settlements.manage')), 403);
        $service = app(\App\Services\Business\BusinessOrderingRestrictionService::class);
        abort_unless($service->installed(), 503, 'Run the pending ordering restriction history migration.');
        $data = $request->validate(['status' => ['nullable', 'in:restricted,restored'], 'search' => ['nullable', 'string', 'max:160'], 'business_uuid' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', \Illuminate\Validation\Rule::when($request->filled('from'), 'after_or_equal:from')], 'per_page' => ['nullable', 'integer', 'in:10,15,25,50,100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $restored = ($data['status'] ?? 'restricted') === 'restored';
        $query = $restored ? \App\Models\Business\BusinessOrderingRestriction::whereNotNull('restored_at')->with('business:id,uuid,name') : $this->liveRestricted();
        $filter = function ($business) use ($data) {
            if (! empty($data['business_uuid'])) {
                $business->where('uuid', $data['business_uuid']);
            }
            if (! empty($data['search'])) {
                $business->where('name', 'like', '%'.trim($data['search']).'%');
            }
        };
        if ($restored) {
            $query->whereHas('business', $filter);
        } else {
            $filter($query);
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (! empty($data[$field])) {
                if ($restored) {
                    $query->whereDate('restricted_at', $operator, $data[$field]);
                } else {
                    $query->whereIn('id', function ($history) use ($field, $operator, $data) {
                        $history->select('active_business_id')->from('business_ordering_restrictions')->whereNotNull('active_business_id')->whereDate('restricted_at', $operator, $data[$field]);
                    });
                }
            }
        }
        $page = $query->orderByDesc($restored ? 'restricted_at' : 'id')->paginate($data['per_page'] ?? 10);
        $active = collect();
        $overdue = collect();
        if (! $restored) {
            $ids = $page->getCollection()->pluck('id');
            $active = \App\Models\Business\BusinessOrderingRestriction::whereIn('active_business_id', $ids)->get()->keyBy('business_id');
            $overdue = BusinessInvoice::whereIn('business_id', $ids)->whereNotNull('sent_at')->where('due_at', '<=', now())->whereRaw('total > paid_amount + credit_amount')->orderBy('due_at')->get()->groupBy('business_id');
        }
        $page->through(function ($row) use ($restored, $active, $overdue, $service) {
            $business = $restored ? $row->business : $row;
            $episode = $restored ? $row : $active->get($business->id);
            $invoices = $restored ? $episode->invoice_snapshot : $service->snapshot($overdue->get($business->id, collect()));
            $balances = collect($invoices)->groupBy('currency')->map(fn ($entries, $currency) => ['currency' => $currency, 'amount' => round($entries->sum('outstanding'), 2)])->values();

            return ['uuid' => $episode?->uuid, 'business_uuid' => $business->uuid, 'business_name' => $business->name, 'status' => $restored ? 'restored' : 'restricted',
                'restricted_at' => $episode?->restricted_at?->toIso8601String(), 'observed_at' => $episode?->observed_at?->toIso8601String(), 'date_basis' => $episode?->date_basis ?? 'pending_sync',
                'restored_at' => $episode?->restored_at?->toIso8601String(), 'restoration_reason' => $episode?->restoration_reason,
                'reason' => 'Overdue Paperstic invoice', 'balances' => $balances, 'invoices' => $invoices];
        });

        return $this->sendResponse(['restrictions' => $page], 'Ordering restrictions retrieved.');
    }

    public function export(Request $request)
    {
        return $this->index($request, true);
    }

    public function index(Request $request, bool $export = false)
    {
        $user = $request->user();
        abort_unless($user?->is_active && $user->type !== 'agent' && $user->can('operations.businesses.view') && ($user->can('operations.settlements.view') || $user->can('operations.settlements.manage')), 403);
        abort_unless(app(BusinessBillingService::class)->installed(), 503, 'Run the pending billing migrations.');
        $data = $request->validate(['view' => ['nullable', 'in:overview,invoices,payments,schedules'], 'search' => ['nullable', 'string', 'max:160'], 'business_uuid' => ['nullable', 'uuid'], 'status' => ['nullable', 'in:all,outstanding,unpaid,overdue,paid,draft'], 'date_field' => ['nullable', 'in:issued,due,period'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', \Illuminate\Validation\Rule::when($request->filled('from'), 'after_or_equal:from')], 'per_page' => ['nullable', 'integer', 'in:10,15,25,50,100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $search = trim($data['search'] ?? '');
        $business = $data['business_uuid'] ?? null;
        $invoices = BusinessInvoice::query()->whereHas('business', function ($query) use ($business) {
            if ($business) {
                $query->where('uuid', $business);
            }
        });
        if ($search !== '') {
            $invoices->where(fn ($q) => $q->whereLike('number', '%'.$search.'%')->orWhereHas('business', fn ($b) => $b->whereLike('name', '%'.$search.'%')));
        }
        // Invoice periods are half-open [from, to); filter dates are inclusive UTC days.
        if ($export || in_array($data['view'] ?? 'overview', ['overview', 'invoices'], true)) {
            $field = $data['date_field'] ?? 'issued';
            $column = $field === 'due' ? 'due_at' : 'created_at';
            if (! empty($data['from'])) {
                $start = \Carbon\CarbonImmutable::parse($data['from'], 'UTC')->startOfDay();
                $invoices->where($field === 'period' ? 'period_to' : $column, $field === 'period' ? '>' : '>=', $start);
            }
            if (! empty($data['to'])) {
                $end = \Carbon\CarbonImmutable::parse($data['to'], 'UTC')->addDay()->startOfDay();
                $invoices->where($field === 'period' ? 'period_from' : $column, '<', $end);
            }
        }
        $outstanding = fn ($q) => $q->whereRaw('total > paid_amount + credit_amount');
        $counts = ['restricted_businesses' => $this->liveRestricted()->count(), 'invoices' => (clone $invoices)->count(), 'unpaid' => $outstanding(clone $invoices)->count(), 'overdue' => $outstanding(clone $invoices)->whereNotNull('sent_at')->where('due_at', '<=', now())->count()];
        $balances = (clone $invoices)->selectRaw('currency, SUM(CASE WHEN total > paid_amount + credit_amount THEN total - paid_amount - credit_amount ELSE 0 END) AS outstanding, SUM(paid_amount) AS paid, SUM(CASE WHEN paid_amount + credit_amount > total THEN paid_amount + credit_amount - total ELSE 0 END) AS credit')->groupBy('currency')->get();
        $view = $export ? 'invoices' : ($data['view'] ?? 'overview');
        $size = $data['per_page'] ?? 10;
        if ($view === 'payments') {
            $rows = DB::table('business_invoice_receipts as receipts')->join('business_invoices as invoices', 'invoices.id', '=', 'receipts.business_invoice_id')->join('businesses', 'businesses.id', '=', 'invoices.business_id');
            if ($business) {
                $rows->where('businesses.uuid', $business);
            }
            if ($search !== '') {
                $rows->where(fn ($q) => $q->whereLike('businesses.name', '%'.$search.'%')->orWhereLike('invoices.number', '%'.$search.'%')->orWhereLike('receipts.reference', '%'.$search.'%'));
            }
            $rows = $rows->orderByDesc('receipts.id')->select(['receipts.uuid', 'receipts.kind', 'receipts.amount', 'receipts.reference', 'receipts.notes', 'receipts.created_at', 'invoices.uuid as invoice_uuid', 'invoices.number as invoice_number', 'invoices.currency', 'businesses.uuid as business_uuid', 'businesses.name as business_name'])->paginate($size)->through(function ($row) {
                if (isset($row->created_at)) {
                    $row->created_at = \Carbon\Carbon::parse($row->created_at, 'UTC')->toIso8601String();
                }
                if (isset($row->next_invoice_at)) {
                    $row->next_invoice_at = \Carbon\Carbon::parse($row->next_invoice_at, 'UTC')->toIso8601String();
                }

                return $row;
            });
        } elseif ($view === 'schedules') {
            $rows = DB::table('businesses')->leftJoin('business_billing_policies as policies', 'policies.business_id', '=', 'businesses.id');
            if ($business) {
                $rows->where('businesses.uuid', $business);
            }
            if ($search !== '') {
                $rows->whereLike('businesses.name', '%'.$search.'%');
            }
            $rows = $rows->orderBy('businesses.name')->select(['businesses.uuid as business_uuid', 'businesses.name as business_name', 'policies.frequency', 'policies.interval_days', 'policies.next_invoice_at', 'policies.automatic_invoicing', 'policies.enforce_overdue', 'policies.grace_hours', 'policies.billing_email'])->paginate($size)->through(function ($row) {
                if (isset($row->created_at)) {
                    $row->created_at = \Carbon\Carbon::parse($row->created_at, 'UTC')->toIso8601String();
                }
                if (isset($row->next_invoice_at)) {
                    $row->next_invoice_at = \Carbon\Carbon::parse($row->next_invoice_at, 'UTC')->toIso8601String();
                }

                return $row;
            });
        } else {
            $status = $data['status'] ?? 'all';
            if (in_array($status, ['unpaid', 'outstanding'], true)) {
                $outstanding($invoices);
            } elseif ($status === 'overdue') {
                $outstanding($invoices)->whereNotNull('sent_at')->where('due_at', '<=', now());
            } elseif ($status === 'paid') {
                $invoices->whereRaw('total <= paid_amount + credit_amount');
            } elseif ($status === 'draft') {
                $invoices->whereNull('sent_at')->whereRaw('total > paid_amount + credit_amount');
            }
            if ($export) {
                $rows = (function () use ($invoices) {
                    foreach ($invoices->with('business:id,uuid,name')->withExists(['receipts as deadline_extended' => fn ($q) => $q->where('kind', 'extension')])->latest('id')->lazy(500) as $invoice) {
                        $summary = $invoice->summary();
                        yield [$invoice->business->name, $invoice->number, $invoice->period_from?->toIso8601String(), $invoice->period_to?->toIso8601String(), $invoice->created_at?->toIso8601String(), $invoice->due_at?->toIso8601String(), $invoice->deadline_extended ? 'Extended' : 'Original', $invoice->sent_at && $invoice->due_at?->lte(now()) && $invoice->outstanding() > 0 ? 'overdue' : $invoice->status, $invoice->currency, $summary['order_count'], $summary['gross_sales'] === null ? null : (float) $summary['gross_sales'], (float) $invoice->total, (float) $invoice->paid_amount, (float) $invoice->credit_amount, $invoice->outstanding()];
                    }
                })();

                return \App\Support\ExcelWorkbook::download('Paperstic-invoices.xlsx', ['Business', 'Invoice number', 'Billing period start (UTC)', 'Billing period end (exclusive, UTC)', 'Issued on (UTC)', 'Payment due (UTC)', 'Deadline', 'Status', 'Currency', 'Orders', 'Gross sales', 'Paperstic fee', 'Amount paid', 'Credits', 'Outstanding'], $rows);
            }
            $rows = $invoices->with('business:id,uuid,name')->withExists(['receipts as deadline_extended' => fn ($q) => $q->where('kind', 'extension')])->latest('id')->paginate($size)->through(fn ($invoice) => [
                'uuid' => $invoice->uuid, 'number' => $invoice->number, 'currency' => $invoice->currency, 'total' => $invoice->total, 'outstanding' => $invoice->outstanding(), 'due_at' => $invoice->due_at, 'period_from' => $invoice->period_from, 'period_to' => $invoice->period_to, 'created_at' => $invoice->created_at, 'deadline_extended' => (bool) $invoice->deadline_extended,
                'status' => $invoice->sent_at && $invoice->due_at?->lte(now()) && $invoice->outstanding() > 0 ? 'overdue' : $invoice->status,
                'business_uuid' => $invoice->business->uuid, 'business_name' => $invoice->business->name,
            ]);
        }

        return $this->sendResponse(['counts' => $counts, 'balances' => $balances, 'rows' => $rows], 'Business billing retrieved.');
    }
}
