<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessCommissionPolicy;
use App\Services\Business\BusinessCommissionService;
use App\Services\Business\BusinessProfileAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessCommissionController extends BaseController
{
    public function __construct(private BusinessCommissionService $commissions) {}

    private function access(Business $business, bool $operations, bool $manage = false): void
    {
        abort_unless($this->commissions->installed(), 503, 'Commission policies require the pending database migration.');
        $user = auth()->user();
        abort_unless($user?->is_active && $user->type !== 'agent', 403);
        if ($operations) {
            abort_unless($user->can('operations.businesses.view') && ($user->can('operations.commissions.manage') || (! $manage && $user->can('operations.commissions.view'))), 403);
        } else {
            abort_unless(! $manage && app(BusinessProfileAccess::class)->permissions($business)['can_manage_operations'], 403);
        }
    }

    private function data(BusinessCommissionPolicy $policy): array
    {
        return [...$policy->attributesToArray(), 'status' => $policy->status,
            'creator' => $policy->creator?->name ?? 'System', 'approver' => $policy->approver?->name ?? ($policy->approved_at ? 'System' : null)];
    }

    public function index(Request $request, Business $business)
    {
        return $this->listing($request, $business, false);
    }

    public function operationsIndex(Request $request, Business $business)
    {
        return $this->listing($request, $business, true);
    }

    private function listing(Request $request, Business $business, bool $operations)
    {
        $this->access($business, $operations);
        $request->validate(['per_page' => ['nullable', 'integer', 'in:10,15,25,50,100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = BusinessCommissionPolicy::where('business_id', $business->id);
        $latest = (clone $query)->where('state', 'published')->orderByDesc('starts_at')->orderByDesc('id')->first();

        return $this->sendResponse([
            'current' => ($current = $this->commissions->current($business)) ? $this->data($current) : null,
            'latest_policy_uuid' => $latest?->uuid,
            'timezone' => $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC',
            'policies' => $query->with(['creator', 'approver'])->withCount('fees')->orderByDesc('starts_at')->orderByDesc('id')->paginate($request->integer('per_page', 10))->through(fn ($policy) => $this->data($policy)),
        ], 'Commission agreements retrieved.');
    }

    public function store(Request $request, Business $business)
    {
        return $this->persist($request, $business);
    }

    public function update(Request $request, Business $business, BusinessCommissionPolicy $policy)
    {
        return $this->persist($request, $business, $policy);
    }

    private function persist(Request $request, Business $business, ?BusinessCommissionPolicy $policy = null)
    {
        $this->access($business, true, true);
        abort_if($policy && $policy->business_id !== $business->id, 404);
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'action' => ['required', Rule::in(['draft', 'publish'])],
            'apply_now' => ['required', 'boolean'], 'starts_at' => ['nullable', 'required_if:apply_now,false', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'expected_policy_uuid' => ['present', 'nullable', 'uuid'],
        ]);
        $saved = $this->commissions->save($business, $data, $request->user()->id, $policy);

        return $this->sendResponse(['policy' => $this->data($saved)], 'Commission agreement '.$saved->state.'. Existing order fees are unchanged.');
    }

    public function cancel(Request $request, Business $business, BusinessCommissionPolicy $policy)
    {
        $this->access($business, true, true);
        abort_unless($policy->business_id === $business->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $this->commissions->cancel($business, $policy, $data['reason']);

        return $this->sendResponse([], 'Unused commission agreement cancelled.');
    }

    public function fees(Request $request, Business $business, BusinessCommissionPolicy $policy)
    {
        return $this->feeList($request, $business, $policy, false);
    }

    public function operationsFees(Request $request, Business $business, BusinessCommissionPolicy $policy)
    {
        return $this->feeList($request, $business, $policy, true);
    }

    private function feeList(Request $request, Business $business, BusinessCommissionPolicy $policy, bool $operations)
    {
        $this->access($business, $operations);
        abort_unless($policy->business_id === $business->id, 404);
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,15,25,50,100']]);
        $fees = $policy->fees()->with('order:id,uuid,number')->latest('id')->paginate($request->integer('per_page', 10));

        return $this->sendResponse(['policy' => $this->data($policy), 'fees' => $fees->through(fn ($fee) => [
            'uuid' => $fee->uuid, 'order_uuid' => $fee->order?->uuid, 'order_number' => $fee->order?->number,
            'rate' => $fee->rate, 'base_amount' => $fee->base_amount, 'amount' => $fee->amount, 'currency' => $fee->currency, 'status' => $fee->status, 'reason' => $fee->reason,
        ])], 'Related order fees retrieved.');
    }
}
