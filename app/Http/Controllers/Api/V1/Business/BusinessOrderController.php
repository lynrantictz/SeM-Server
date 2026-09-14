<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessUser;
use App\Models\Order\Order;
use App\Models\Order\OrderStatus;
use App\Models\Order\OrderStatusHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BusinessOrderController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $role = $this->authorizeBusiness($business);
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['awaiting', 'preparing', 'ready', 'history'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ]);

        $tab = $validated['tab'] ?? $this->defaultTab($role);
        abort_unless($this->canSeeTab($role, $tab), HTTP_FORBIDDEN, 'This order queue is not available for your role.');
        $statuses = $this->tabStatuses($tab);
        $baseQuery = $this->visibleOrders($business, $role)
            ->whereIn('order_status_id', $this->statusIds($statuses));

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $baseQuery->where(function (Builder $query) use ($search) {
                $query->where('number', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $customer) => $customer
                        ->where('phone_e164', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'ilike', "%{$search}%"));
            });
        }

        $orders = (clone $baseQuery)
            ->with([
                'status:id,name',
                'customer:id,phone,phone_e164',
                'approver:id,name',
                'servicePoint:id,type,label,display_name,section_id,sub_section_id',
                'servicePoint.section:id,name',
                'servicePoint.subSection:id,name',
                'items:id,order_id,item_id,quantity,unit_price,final_price,total_amount,comment',
                'items.item:id,name',
                'items.options:id,order_item_id,name,price_adjustment',
                'statusHistories.fromStatus:id,name',
                'statusHistories.toStatus:id,name',
                'statusHistories.changedBy:id,name',
            ])
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        $counts = [];
        foreach (['awaiting', 'preparing', 'ready', 'history'] as $countTab) {
            if ($this->canSeeTab($role, $countTab)) {
                $counts[$countTab] = $this->visibleOrders($business, $role)
                    ->whereIn('order_status_id', $this->statusIds($this->tabStatuses($countTab)))
                    ->count();
            }
        }

        return $this->sendResponse([
            'role' => $role,
            'default_tab' => $this->defaultTab($role),
            'counts' => $counts,
            'orders' => [
                'data' => collect($orders->items())->map(fn (Order $order) => $this->orderData($order))->values(),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ],
        ], 'Orders retrieved successfully.');
    }

    public function action(Request $request, Business $business, string $order)
    {
        $role = $this->authorizeBusiness($business);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'ready', 'complete'])],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
        ]);

        $updated = DB::transaction(function () use ($business, $order, $validated, $role) {
            $record = Order::query()
                ->where('business_id', $business->id)
                ->where('uuid', $order)
                ->lockForUpdate()
                ->firstOrFail();
            $current = OrderStatus::query()->findOrFail($record->order_status_id);

            $transition = match ($validated['action']) {
                'approve' => ['Pending', 'Processing'],
                'reject' => ['Pending', 'Cancelled'],
                'ready' => ['Processing', 'Ready'],
                'complete' => ['Ready', 'Completed'],
            };

            abort_unless($this->canPerform($role, $validated['action'], $record->channel), HTTP_FORBIDDEN,
                'Your role cannot perform this action for this order.');
            abort_unless($current->name === $transition[0], Response::HTTP_CONFLICT,
                'This order has already changed. Refresh the queue and try again.');

            $next = OrderStatus::query()->where('name', $transition[1])->firstOrFail();
            $record->order_status_id = $next->id;
            if ($validated['action'] === 'approve') {
                $record->approver_id = auth()->id();
                $record->approved_at = now();
            }
            $record->save();

            OrderStatusHistory::query()->create([
                'order_id' => $record->id,
                'from_status_id' => $current->id,
                'to_status_id' => $next->id,
                'changed_by_user_id' => auth()->id(),
                'note' => $validated['action'] === 'reject'
                    ? trim((string) $validated['reason'])
                    : null,
            ]);

            return $record->fresh()->load([
                'status:id,name', 'customer:id,phone,phone_e164', 'approver:id,name',
                'servicePoint:id,type,label,display_name,section_id,sub_section_id',
                'servicePoint.section:id,name', 'servicePoint.subSection:id,name',
                'items:id,order_id,item_id,quantity,unit_price,final_price,total_amount,comment',
                'items.item:id,name', 'items.options:id,order_item_id,name,price_adjustment',
                'statusHistories.fromStatus:id,name', 'statusHistories.toStatus:id,name',
                'statusHistories.changedBy:id,name',
            ]);
        });

        return $this->sendResponse(['order' => $this->orderData($updated)], 'Order updated successfully.');
    }

    private function authorizeBusiness(Business $business): string
    {
        /** @var User $user */
        $user = auth()->user();
        $staffMembership = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ($user->type === 'business') {
            abort_unless($staffMembership, HTTP_FORBIDDEN, 'You do not have access to this business.');
            abort_unless((bool) $user->is_active, HTTP_FORBIDDEN, 'Your staff account is inactive.');
            return $staffMembership->business_role;
        }

        $vendorAccess = $user->vendors()->whereKey($business->vendor_id)->first();
        abort_unless($vendorAccess, HTTP_FORBIDDEN, 'You do not have access to this business.');
        if ($vendorAccess->pivot->is_primary) return 'owner';
        abort_unless($vendorAccess->pivot->is_active && $vendorAccess->pivot->role === 'manager', HTTP_FORBIDDEN,
            'You do not have permission to view business orders.');
        if ($vendorAccess->pivot->access_scope !== 'all_businesses') {
            abort_unless($user->businesses()->whereKey($business->id)->wherePivot('is_active', true)->exists(),
                HTTP_FORBIDDEN, 'You do not have access to this business.');
        }
        return 'vendor_manager';
    }

    private function visibleOrders(Business $business, string $role): Builder
    {
        $query = Order::query()->where('business_id', $business->id);
        if ($role === 'waiter') return $query->where('channel', 'dine_in');
        return $query;
    }

    private function defaultTab(string $role): string
    {
        return in_array($role, ['kitchen', 'chef'], true) ? 'preparing' : 'awaiting';
    }

    private function canSeeTab(string $role, string $tab): bool
    {
        if (in_array($role, ['kitchen', 'chef'], true)) return in_array($tab, ['preparing', 'ready', 'history'], true);
        return in_array($tab, ['awaiting', 'preparing', 'ready', 'history'], true);
    }

    private function canPerform(string $role, string $action, ?string $channel): bool
    {
        $manager = in_array($role, ['owner', 'vendor_manager', 'business_manager', 'manager'], true);
        if ($manager) return true;
        return match ($action) {
            'approve', 'reject' => in_array($role, ['counter', 'counter-clerk'], true)
                || ($role === 'waiter' && $channel === 'dine_in'),
            'ready' => in_array($role, ['kitchen', 'chef'], true),
            'complete' => in_array($role, ['counter', 'counter-clerk'], true)
                || ($role === 'waiter' && $channel === 'dine_in'),
            default => false,
        };
    }

    private function tabStatuses(string $tab): array
    {
        return match ($tab) {
            'awaiting' => ['Pending'],
            'preparing' => ['Processing'],
            'ready' => ['Ready'],
            'history' => ['Completed', 'Cancelled', 'Refunded'],
            default => [],
        };
    }

    private function statusIds(array $names): array
    {
        return OrderStatus::query()->whereIn('name', $names)->pluck('id')->all();
    }

    private function orderData(Order $order): array
    {
        $point = $order->servicePoint;
        return [
            'uuid' => $order->uuid,
            'number' => $order->number,
            'channel' => $order->channel,
            'status' => $order->status?->name,
            'customer' => [
                'name' => null,
                'phone' => $order->customer?->phone,
            ],
            'service_point' => $point ? [
                'type' => $point->type,
                'label' => $point->label,
                'name' => $point->display_name,
                'section' => $point->section?->name,
                'subsection' => $point->subSection?->name,
                'order_label' => $order->service_point_label,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->item?->name ?? 'Menu item',
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total_amount,
                'comment' => $item->comment ?? null,
                'options' => $item->options->map(fn ($option) => [
                    'name' => $option->name,
                    'price_adjustment' => (float) $option->price_adjustment,
                ])->values(),
            ])->values(),
            'total_items_amount' => (float) $order->total_items_amount,
            'tax_amount' => (float) $order->tax_amount,
            'total_amount' => (float) $order->total_amount,
            'created_at' => $order->created_at,
            'approved_at' => $order->approved_at,
            'approved_by' => $order->approver?->name,
            'comment' => $order->comment,
            'history' => $order->relationLoaded('statusHistories')
                ? $order->statusHistories->map(fn ($entry) => [
                    'from' => $entry->fromStatus?->name,
                    'to' => $entry->toStatus?->name,
                    'by' => $entry->changedBy?->name,
                    'note' => $entry->note,
                    'at' => $entry->created_at,
                ])->values()
                : [],
        ];
    }
}
