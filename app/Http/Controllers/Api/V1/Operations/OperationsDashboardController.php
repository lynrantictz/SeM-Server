<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessPayout;
use App\Models\Business\ComplianceDocument;
use App\Models\Location\Country;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;

class OperationsDashboardController extends BaseController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $now = now();
        $from = Carbon::parse($validated['from'] ?? $now->copy()->subDays(6)->toDateString())->startOfDay();
        $to = Carbon::parse($validated['to'] ?? $now->toDateString())->endOfDay();
        $countryId = $validated['country_id'] ?? null;
        $businessId = $validated['business_id'] ?? null;
        $statusName = $validated['status'] ?? null;

        $businessScope = function ($query) use ($countryId, $businessId): void {
            $query
                ->when($businessId, fn ($builder) => $builder->whereKey($businessId))
                ->when($countryId, fn ($builder) => $builder->whereHas(
                    'district.city',
                    fn ($districtQuery) => $districtQuery->where('country_id', $countryId),
                ));
        };

        $orderStatuses = DB::table('order_statuses')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get();
        $statusId = $statusName
            ? $orderStatuses->firstWhere('name', $statusName)?->id
            : null;

        $orderScope = function ($query) use ($businessId, $countryId, $statusId): void {
            $query
                ->when($businessId, fn ($builder) => $builder->where('business_id', $businessId))
                ->when($countryId, fn ($builder) => $builder->whereHas(
                    'business.district.city',
                    fn ($countryQuery) => $countryQuery->where('country_id', $countryId),
                ))
                ->when($statusId, fn ($builder) => $builder->where('order_status_id', $statusId));
        };

        $ordersInRange = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->tap($orderScope);
        $successfulPaymentsInRange = Payment::query()
            ->where('status', 'SUCCESS')
            ->whereBetween('created_at', [$from, $to])
            ->when($businessId || $countryId, fn ($query) => $query->whereHas('order', $orderScope));

        $orderTrend = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->tap($orderScope)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as amount')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->toDateString(),
                'orders' => (int) $row->orders,
                'amount' => (float) $row->amount,
            ])
            ->values();

        $orderSources = Order::query()
            ->whereBetween('orders.created_at', [$from, $to])
            ->when($businessId, fn ($query) => $query->where('orders.business_id', $businessId))
            ->when($countryId, fn ($query) => $query->whereHas(
                'business.district.city',
                fn ($countryQuery) => $countryQuery->where('country_id', $countryId),
            ))
            ->when($statusId, fn ($query) => $query->where('orders.order_status_id', $statusId))
            ->leftJoin('business_user as business_membership', function ($join): void {
                $join->on('business_membership.business_id', '=', 'orders.business_id')
                    ->on('business_membership.user_id', '=', 'orders.user_id');
            })
            ->selectRaw("CASE
                WHEN orders.user_id IS NULL THEN 'customer'
                WHEN business_membership.business_role = 'waiter' THEN 'waiter'
                ELSE 'counter'
            END as source, COUNT(*) as orders")
            ->groupBy('source')
            ->pluck('orders', 'source');

        $user = $request->user();
        $summary = [];

        if ($user->can('operations.businesses.view')) {
            $summary['businesses'] = [
                'total' => (clone Business::query())->tap($businessScope)->count(),
                'active' => (clone Business::query())->tap($businessScope)->where('is_active', true)->count(),
                'inactive' => (clone Business::query())->tap($businessScope)->where('is_active', false)->count(),
            ];
        }

        if ($user->can('operations.kyc.view')) {
            $summary['kyc'] = [
                'pending_documents' => ComplianceDocument::query()
                    ->whereIn('status', ['pending', 'in_review', 'needs_review'])
                    ->when($businessId || $countryId, fn ($query) => $query->whereHas('business', $businessScope))
                    ->count(),
                'approved_documents' => ComplianceDocument::query()
                    ->where('status', 'approved')
                    ->when($businessId || $countryId, fn ($query) => $query->whereHas('business', $businessScope))
                    ->count(),
            ];
        }

        if ($user->can('operations.orders.view')) {
            $summary['orders'] = [
                'today' => (clone $ordersInRange)->count(),
                'today_amount' => (float) ((clone $ordersInRange)->sum('total_amount') ?? 0),
                'pending' => Order::query()
                    ->tap($orderScope)
                    ->whereIn('order_status_id', $orderStatuses->whereIn('name', ['Pending', 'Processing'])->pluck('id'))
                    ->count(),
            ];

            $summary['payments'] = [
                'successful_today' => (clone $successfulPaymentsInRange)->count(),
                'successful_today_amount' => (float) ((clone $successfulPaymentsInRange)->sum('amount') ?? 0),
                'failed_today' => Payment::query()
                    ->where('status', 'FAILED')
                    ->whereBetween('created_at', [$from, $to])
                    ->when($businessId || $countryId, fn ($query) => $query->whereHas('order', $orderScope))
                    ->count(),
            ];
        }

        if ($user->can('operations.payouts.view')) {
            $summary['payouts'] = [
                'pending' => BusinessPayout::query()
                    ->whereIn('status', ['pending_review', 'approved', 'processing'])
                    ->when($businessId || $countryId, fn ($query) => $query->whereHas('business', $businessScope))
                    ->count(),
                'pending_amount' => (float) (BusinessPayout::query()
                    ->whereIn('status', ['pending_review', 'approved', 'processing'])
                    ->when($businessId || $countryId, fn ($query) => $query->whereHas('business', $businessScope))
                    ->sum('net_amount') ?? 0),
            ];
        }

        $recentActivity = $user->can('operations.audit.view')
            ? Audit::query()
                ->latest('created_at')
                ->limit(8)
                ->get(['id', 'event', 'auditable_type', 'auditable_id', 'user_id', 'created_at'])
                ->map(fn (Audit $audit) => [
                    'id' => $audit->id,
                    'event' => $audit->event,
                    'resource' => class_basename((string) $audit->auditable_type),
                    'resource_id' => $audit->auditable_id,
                    'user_id' => $audit->user_id,
                    'created_at' => $audit->created_at?->toIso8601String(),
                ])
                ->values()
            : collect();

        $businessOptions = $user->can('operations.businesses.view')
            ? Business::query()
                ->tap($businessScope)
                ->select(['id', 'name'])
                ->orderBy('name')
                ->limit(250)
                ->get()
            : collect();

        return $this->sendResponse([
            'summary' => $summary,
            'order_trend' => $orderTrend,
            'order_sources' => [
                ['source' => 'customer', 'orders' => (int) ($orderSources['customer'] ?? 0)],
                ['source' => 'waiter', 'orders' => (int) ($orderSources['waiter'] ?? 0)],
                ['source' => 'counter', 'orders' => (int) ($orderSources['counter'] ?? 0)],
            ],
            'recent_activity' => $recentActivity,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'country_id' => $countryId,
                'business_id' => $businessId,
                'status' => $statusName,
            ],
            'options' => [
                'countries' => Country::query()->select(['id', 'name', 'iso2'])->orderBy('name')->get(),
                'businesses' => $businessOptions,
                'statuses' => $orderStatuses,
            ],
            'generated_at' => $now->toIso8601String(),
        ], 'Operations dashboard retrieved successfully.');
    }
}
