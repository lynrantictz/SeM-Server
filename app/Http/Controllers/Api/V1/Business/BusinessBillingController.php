<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessBillingPolicy;
use App\Models\Business\BusinessInvoice;
use App\Models\Business\OrderPlatformFee;
use App\Services\Business\BusinessBillingService;
use App\Services\Business\BusinessProfileAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessBillingController extends BaseController
{
    public function __construct(private BusinessBillingService $billing) {}

    private function access(Business $business, bool $operations = false, bool $manage = false): void
    {
        abort_unless($this->billing->installed(), 503, 'Billing is not installed yet. Run the pending database migration.');
        abort_unless(auth()->user()->is_active, 403);
        if ($operations) {
            abort_unless(auth()->user()->type !== 'agent' && auth()->user()->can('operations.businesses.view') && (auth()->user()->can('operations.settlements.manage') || (! $manage && auth()->user()->can('operations.settlements.view'))), 403);
        } else {
            abort_if(auth()->user()->type === 'agent', 403, 'Onboarding agents cannot access business billing.');
            abort_unless(app(BusinessProfileAccess::class)->permissions($business)['can_manage_operations'], 403, 'Only business management can view billing.');
        }
    }

    public function show(Request $request, Business $business)
    {
        $this->access($business);

        return $this->summary($request, $business);
    }

    public function payments(Request $request, Business $business)
    {
        $this->access($business);
        $request->validate(['per_page' => ['nullable', 'integer', 'in:10,15,25,50,100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $rows = DB::table('business_invoice_receipts as receipts')->join('business_invoices as invoices', 'invoices.id', '=', 'receipts.business_invoice_id')
            ->where('invoices.business_id', $business->id)->orderByDesc('receipts.id')
            ->select(['receipts.uuid', 'receipts.kind', 'receipts.amount', 'receipts.reference', 'receipts.notes', 'receipts.created_at', 'invoices.uuid as invoice_uuid', 'invoices.number as invoice_number', 'invoices.currency'])->paginate($request->integer('per_page', 10))->through(function ($row) {
                $row->created_at = Carbon::parse($row->created_at, 'UTC')->toIso8601String();

                return $row;
            });

        return $this->sendResponse(['payments' => $rows], 'Billing payment history retrieved.');
    }

    public function operationsShow(Request $request, Business $business)
    {
        $this->access($business, true);

        return $this->summary($request, $business, true);
    }

    private function summary(Request $request, Business $business, bool $operations = false)
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'in:10,15,25,50,100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $policy = BusinessBillingPolicy::where('business_id', $business->id)->first();
        $invoices = BusinessInvoice::where('business_id', $business->id)->latest('id')->paginate($data['per_page'] ?? 10);
        $accrued = OrderPlatformFee::where('business_id', $business->id)->where('status', 'accrued')->selectRaw('currency, SUM(amount) AS amount')->groupBy('currency')->get();
        $balance = BusinessInvoice::where('business_id', $business->id)->get()->groupBy('currency')->map(fn ($records, $currency) => ['currency' => $currency, 'outstanding' => round($records->sum(fn ($invoice) => $invoice->outstanding()), 2), 'credit' => round($records->sum(fn ($invoice) => max(0, (float) $invoice->paid_amount + (float) $invoice->credit_amount - (float) $invoice->total)), 2)])->values();

        return $this->sendResponse([
            'policy' => $policy ? [...$policy->toArray(), 'automatic_invoicing' => (bool) $policy->automatic_invoicing, 'enforce_overdue' => (bool) $policy->enforce_overdue] : ['automatic_invoicing' => false, 'enforce_overdue' => false, 'frequency' => 'weekly', 'interval_days' => 7, 'trial_days' => 0, 'grace_hours' => 72, 'billing_email' => null, 'next_invoice_at' => null, 'fee_starts_at' => null, 'payment_instructions' => null],
            'commission_rate' => app(\App\Services\Business\BusinessCommissionService::class)->current($business)?->rate ?? $business->paymentSetting?->commission_rate,
            'effective_rate' => app(\App\Services\Business\BusinessCommissionService::class)->rate($business),
            'effective_fee_starts_at' => $this->billing->feeStartsAt($policy)?->toIso8601String(),
            'timezone' => $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC',
            'ordering_restricted' => $this->billing->restricted($business), 'accrued' => $accrued, 'balances' => $balance,
            'unpaid_invoice_count' => BusinessInvoice::where('business_id', $business->id)->whereRaw('total > paid_amount + credit_amount')->count(),
            'invoices' => $invoices->through(fn ($invoice) => $this->invoiceData($invoice, $operations)),
        ], 'Business billing retrieved.');
    }

    private function invoiceData(BusinessInvoice $invoice, bool $operations): array
    {
        return [...$invoice->toArray(), 'summary' => $invoice->summary(), 'delivery_error' => $operations ? $invoice->delivery_error : ($invoice->delivery_error ? 'Email dispatch needs attention.' : null), 'outstanding' => $invoice->outstanding(), 'overdue' => (bool) ($invoice->sent_at && $invoice->due_at?->lte(now()) && $invoice->outstanding() > 0)];
    }

    public function invoice(Business $business, BusinessInvoice $invoice)
    {
        $this->access($business);
        abort_unless($invoice->business_id === $business->id, 404);

        return $this->sendResponse(['invoice' => $this->invoiceData($invoice->load(['items', 'receipts']), false)], 'Invoice retrieved.');
    }

    public function operationsInvoice(Business $business, BusinessInvoice $invoice)
    {
        $this->access($business, true);
        abort_unless($invoice->business_id === $business->id, 404);

        return $this->sendResponse(['invoice' => $this->invoiceData($invoice->load(['items', 'receipts']), true)], 'Invoice retrieved.');
    }

    public function pdf(Business $business, BusinessInvoice $invoice)
    {
        return $this->download($business, $invoice, false);
    }

    public function operationsPdf(Business $business, BusinessInvoice $invoice)
    {
        return $this->download($business, $invoice, true);
    }

    private function download(Business $business, BusinessInvoice $invoice, bool $operations)
    {
        $this->access($business, $operations);
        abort_unless($invoice->business_id === $business->id, 404);
        $pdf = app(\App\Services\Business\BusinessInvoicePdfService::class);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$pdf->filename($invoice).'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, Business $business)
    {
        $this->access($business, true, true);
        $data = $request->validate([
            'commission_rate' => [Rule::prohibitedIf(app(\App\Services\Business\BusinessCommissionService::class)->installed()), 'sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'automatic_invoicing' => ['required', 'boolean'], 'enforce_overdue' => ['required', 'boolean'],
            'billing_email' => ['nullable', 'required_if:automatic_invoicing,true', 'email', 'max:255'],
            'frequency' => ['required', Rule::in(['weekly', 'monthly', 'custom'])], 'interval_days' => ['required', 'integer', 'min:1', 'max:365'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'], 'fee_starts_at' => ['nullable', 'date'],
            'next_invoice_at' => ['nullable', 'required_if:automatic_invoicing,true', 'date'],
            'grace_hours' => ['required', 'integer', 'min:1', 'max:720'], 'payment_instructions' => ['nullable', 'required_if:automatic_invoicing,true', 'string', 'max:4000'],
        ]);
        abort_if($data['enforce_overdue'] && ! $data['automatic_invoicing'], 422, 'Enable invoice delivery before enabling overdue restrictions.');
        DB::transaction(function () use ($business, $data) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            app(\App\Services\Business\BusinessOrderingRestrictionService::class)->sync($business);
            $policy = BusinessBillingPolicy::firstOrNew(['business_id' => $business->id]);
            if (\Illuminate\Support\Facades\Schema::hasColumn('business_billing_policies', 'restriction_enabled_at')) {
                $enabled = $data['automatic_invoicing'] && $data['enforce_overdue'];
                $data['restriction_enabled_at'] = $enabled ? ($policy->automatic_invoicing && $policy->enforce_overdue ? $policy->restriction_enabled_at : now()) : null;
            }
            $hasRate = array_key_exists('commission_rate', $data);
            $rate = $data['commission_rate'] ?? null;
            unset($data['commission_rate']);
            if (! empty($data['next_invoice_at'])) {
                $date = Carbon::parse($data['next_invoice_at'])->utc();
                if (! $policy->next_invoice_at || ! $policy->next_invoice_at->equalTo($date)) {
                    $zone = $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC';
                    $data['billing_day'] = $date->copy()->setTimezone($zone)->day;
                }
                $data['next_invoice_at'] = $date;
            }
            if (! empty($data['fee_starts_at'])) {
                $data['fee_starts_at'] = Carbon::parse($data['fee_starts_at'])->utc();
            }
            if ($business->is_active && ! $policy->trial_started_at) {
                $data['trial_started_at'] = now();
            }
            $policy->fill($data)->save();
            app(\App\Services\Business\BusinessOrderingRestrictionService::class)->sync($business, 'billing_policy_updated', auth()->id());
            $setting = $business->paymentSetting()->firstOrCreate(['business_id' => $business->id], ['provider' => 'manual', 'currency' => $business->district?->city?->country?->currency ?: 'TZS', 'commission_basis' => 'subtotal_excluding_tax', 'fee_bearer' => 'business', 'settlement_mode' => 'manual_hold']);
            if ($hasRate && ! app(\App\Services\Business\BusinessCommissionService::class)->installed()) {
                $setting->update(['commission_rate' => $rate]);
            }
        });

        return $this->sendResponse([], 'Billing settings saved. Existing order fee snapshots and unpaid invoices are unchanged.');
    }

    public function receipt(Request $request, Business $business, BusinessInvoice $invoice)
    {
        $this->access($business, true, true);
        abort_unless($invoice->business_id === $business->id, 404);
        $data = $request->validate(['kind' => ['required', 'in:payment,credit'], 'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'], 'reference' => ['required', 'string', 'max:160'], 'notes' => ['required_if:kind,credit', 'nullable', 'string', 'max:2000']]);
        $updated = $this->billing->recordReceipt($invoice->id, $data['kind'], (float) $data['amount'], $data['reference'], $data['notes'] ?? null, $request->user()->id);

        return $this->sendResponse(['invoice' => $updated], 'Invoice '.$data['kind'].' recorded.');
    }

    public function extend(Request $request, Business $business, BusinessInvoice $invoice)
    {
        $this->access($business, true, true);
        abort_unless($invoice->business_id === $business->id && $invoice->sent_at, 404);
        $data = $request->validate(['due_at' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($business, $invoice, $data, $request) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            app(\App\Services\Business\BusinessOrderingRestrictionService::class)->sync($business);
            $locked = BusinessInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $locked->update(['due_at' => Carbon::parse($data['due_at'])->utc(), 'reminded_at' => null]);
            $locked->receipts()->create(['kind' => 'extension', 'amount' => 0, 'reference' => (string) \Illuminate\Support\Str::uuid(), 'recorded_by' => $request->user()->id, 'notes' => $data['reason']]);
            app(\App\Services\Business\BusinessOrderingRestrictionService::class)->sync($business, 'deadline_extended', $request->user()->id);
        });

        return $this->sendResponse([], 'Payment deadline extended.');
    }
}
