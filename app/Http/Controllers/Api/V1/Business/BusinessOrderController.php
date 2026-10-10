<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessUser;
use App\Models\Menu\Category;
use App\Models\Menu\Item;
use App\Models\Order\Order;
use App\Models\Order\OrderPaymentLink;
use App\Models\Order\OrderStaffNote;
use App\Models\Order\OrderStatus;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OrderWorkLock;
use App\Models\Payment\PaymentStatus;
use App\Models\Payment\PaymentMethod;
use App\Models\Section\ServicePoint;
use App\Repositories\Order\OrderItemRepository;
use App\Repositories\Order\OrderRepository;
use App\Services\MenuAvailabilityService;
use App\Services\Business\BusinessActivationService;
use App\Services\PaymentGateway\PaymentSettlementService;
use App\Services\Order\GuestOrderSessionService;
use App\Services\Order\OrderPaymentLinkService;
use App\Jobs\WhatsApp\SendOrderPaymentRequestWhatsApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\Response;

class BusinessOrderController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $role = $this->authorizeBusiness($business);
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['awaiting', 'preparing', 'ready', 'awaiting_payment', 'paid', 'completed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:100'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'section_id' => ['nullable', 'integer'],
            'sub_section_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ]);

        $tab = $validated['tab'] ?? $this->defaultTab($role);
        abort_unless($this->canSeeTab($role, $tab), HTTP_FORBIDDEN, 'This order queue is not available for your role.');
        $baseQuery = $this->ordersForTab($business, $role, $tab);
        if ($tab === 'paid' && !empty($validated['payment_method_id'])) {
            $baseQuery->where('payment_method_id', $validated['payment_method_id']);
        }
        if (in_array($tab, ['paid', 'completed', 'cancelled'], true)) {
            if (!empty($validated['date_from'])) $baseQuery->whereDate('created_at', '>=', $validated['date_from']);
            if (!empty($validated['date_to'])) $baseQuery->whereDate('created_at', '<=', $validated['date_to']);
        }

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
                'assignee:id,name',
                'paymentStatus:id,name',
                'paymentMethod:id,name',
                'payment:payments.id,payments.order_id,payments.provider,payments.status,payments.expires_at,payments.confirmation_source,payments.confirmed_by_user_id,payments.confirmed_at',
                'payment.confirmedBy:id,name',
                'servicePoint:id,type,label,display_name,section_id,sub_section_id',
                'servicePoint.section:id,name',
                'servicePoint.subSection:id,name',
                'items:id,order_id,item_id,quantity,unit_price,final_price,total_amount,comment',
                'items.item:id,uuid,name',
                'items.options:id,order_item_id,name,price_adjustment',
                'items.options.itemOption:id,uuid',
                'statusHistories.fromStatus:id,name',
                'statusHistories.toStatus:id,name',
                'statusHistories.changedBy:id,name',
                'staffNotes.author:id,name',
                'workLock.user:id,name',
            ])
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        $counts = [];
        foreach (['awaiting', 'preparing', 'ready', 'awaiting_payment', 'paid', 'completed', 'cancelled'] as $countTab) {
            if ($this->canSeeTab($role, $countTab)) {
                $counts[$countTab] = $this->ordersForTab($business, $role, $countTab)->count();
            }
        }

        return $this->sendResponse([
            'role' => $role,
            'default_tab' => $this->defaultTab($role),
            'counts' => $counts,
            'payment_methods' => PaymentMethod::query()->orderBy('name')->get(['id', 'name'])->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])->values(),
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

    public function reports(Request $request, Business $business)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless(in_array($role, ['owner', 'vendor_manager', 'business_manager', 'manager'], true), Response::HTTP_FORBIDDEN, 'You do not have permission to view business reports.');
        $validated = $request->validate([
            'view' => ['nullable', Rule::in(['overview', 'sales', 'orders', 'payments', 'products'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 75, 100])],
            'search' => ['nullable', 'string', 'max:100'],
            'section_id' => ['nullable', 'integer'],
            'sub_section_id' => ['nullable', 'integer'],
        ]);

        $from = $validated['date_from'] ?? now()->subDays(29)->toDateString();
        $to = $validated['date_to'] ?? now()->toDateString();
        $view = $validated['view'] ?? 'overview';
        $perPage = $validated['per_page'] ?? 10;
        $sectionId = $validated['section_id'] ?? null;
        $subSectionId = $validated['sub_section_id'] ?? null;
        $applyServicePointFilter = function ($query) use ($business, $sectionId, $subSectionId) {
            return $query->when($sectionId || $subSectionId, function ($query) use ($business, $sectionId, $subSectionId) {
                $query->whereExists(function ($servicePoint) use ($business, $sectionId, $subSectionId) {
                    $servicePoint->selectRaw('1')
                        ->from('service_points')
                        ->whereColumn('service_points.id', 'orders.service_point_id')
                        ->where('service_points.business_id', $business->id)
                        ->when($sectionId, fn ($section) => $section->where('service_points.section_id', $sectionId))
                        ->when($subSectionId, fn ($subSection) => $subSection->where('service_points.sub_section_id', $subSectionId));
                });
            });
        };
        $orders = fn () => Order::query()
            ->where('orders.business_id', $business->id)
            ->whereBetween('orders.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($sectionId || $subSectionId, function (Builder $query) use ($business, $sectionId, $subSectionId) {
                $query->whereHas('servicePoint', function (Builder $point) use ($business, $sectionId, $subSectionId) {
                    $point->where('business_id', $business->id)
                        ->when($sectionId, fn (Builder $section) => $section->where('section_id', $sectionId))
                        ->when($subSectionId, fn (Builder $subSection) => $subSection->where('sub_section_id', $subSectionId));
                });
            });

        if ($view === 'orders') {
            $query = $orders()->with(['status:id,name', 'paymentStatus:id,name', 'paymentMethod:id,name', 'customer:id,phone,phone_e164'])
                ->orderByDesc('created_at');
            $search = trim((string) ($validated['search'] ?? ''));
            if ($search !== '') {
                $query->where(function (Builder $builder) use ($search) {
                    $builder->where('number', 'ilike', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('phone_e164', 'ilike', "%{$search}%"));
                });
            }
            $page = $query->paginate($perPage)->withQueryString();

            return $this->sendResponse([
                'view' => $view,
                'date_from' => $from,
                'date_to' => $to,
                'orders' => [
                    'data' => collect($page->items())->map(fn (Order $order) => [
                        'number' => $order->number,
                        'created_at' => $order->created_at,
                        'channel' => $order->channel,
                        'status' => $order->status?->name,
                        'payment_status' => $order->paymentStatus?->name,
                        'payment_method' => $order->paymentMethod?->name,
                        'customer_phone' => $this->businessCustomerPhone($order),
                        'total_amount' => (float) $order->total_amount,
                        'tax_amount' => (float) $order->tax_amount,
                        'paid_amount' => (float) ($order->paid_amount ?? 0),
                        'due_amount' => (float) ($order->due_amount ?? 0),
                    ])->values(),
                    'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
                ],
            ], 'Order report retrieved successfully.');
        }

        if ($view === 'products') {
            $products = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->leftJoin('items', 'items.id', '=', 'order_items.item_id')
                ->where('orders.business_id', $business->id)
                ->whereBetween('orders.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->tap($applyServicePointFilter)
                ->selectRaw("COALESCE(items.name, 'Menu item') as name, SUM(order_items.quantity) as quantity, SUM(order_items.total_amount) as revenue")
                ->groupBy('items.name')
                ->orderByDesc('revenue')
                ->paginate($perPage);

            return $this->sendResponse(['view' => $view, 'date_from' => $from, 'date_to' => $to, 'rows' => ['data' => $products->items(), 'meta' => ['current_page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'per_page' => $products->perPage(), 'total' => $products->total(), 'from' => $products->firstItem(), 'to' => $products->lastItem()]]], 'Product report retrieved successfully.');
        }

        if ($view === 'payments') {
            $payments = DB::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'orders.payment_method_id')
                ->where('orders.business_id', $business->id)
                ->whereBetween('orders.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->tap($applyServicePointFilter)
                ->selectRaw("COALESCE(payment_methods.name, payments.provider, 'Not recorded') as method, payments.status, COUNT(*) as count, SUM(payments.amount) as amount")
                ->groupBy('payment_methods.name', 'payments.provider', 'payments.status')
                ->orderByDesc('amount')
                ->paginate($perPage);

            return $this->sendResponse(['view' => $view, 'date_from' => $from, 'date_to' => $to, 'rows' => ['data' => $payments->items(), 'meta' => ['current_page' => $payments->currentPage(), 'last_page' => $payments->lastPage(), 'per_page' => $payments->perPage(), 'total' => $payments->total(), 'from' => $payments->firstItem(), 'to' => $payments->lastItem()]]], 'Payment report retrieved successfully.');
        }

        $base = $orders();
        $paid = (clone $base)->whereHas('paymentStatus', fn (Builder $query) => $query->whereIn('name', ['Completed', 'Paid', 'Successful']));
        $statusCounts = (clone $base)->join('order_statuses', 'order_statuses.id', '=', 'orders.order_status_id')->selectRaw('order_statuses.name as status, COUNT(*) as count')->groupBy('order_statuses.name')->pluck('count', 'status');
        $paymentStatusCounts = (clone $base)->join('payment_statuses', 'payment_statuses.id', '=', 'orders.payment_status_id')->selectRaw('payment_statuses.name as status, COUNT(*) as count')->groupBy('payment_statuses.name')->pluck('count', 'status');
        $sourceCounts = (clone $base)->selectRaw("CASE WHEN user_id IS NULL THEN 'Customer' ELSE 'Staff' END as source, COUNT(*) as count")->groupBy('source')->pluck('count', 'source');
        $daily = (clone $base)->selectRaw('DATE(created_at) as day, COUNT(*) as orders, SUM(total_amount) as revenue')->groupBy('day')->orderBy('day')->get();
        $allocationQuery = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')->join('orders', 'orders.id', '=', 'payments.order_id')->where('payment_allocations.business_id', $business->id)->whereBetween('orders.created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);
        $allocation = $applyServicePointFilter($allocationQuery)->selectRaw('COALESCE(SUM(payment_allocations.gross_amount), 0) as gross, COALESCE(SUM(payment_allocations.commission_amount), 0) as commission, COALESCE(SUM(payment_allocations.gateway_fee_amount), 0) as gateway_fees, COALESCE(SUM(payment_allocations.business_payable_amount), 0) as payable')->first();

        return $this->sendResponse([
            'view' => $view,
            'date_from' => $from,
            'date_to' => $to,
            'summary' => [
                'orders' => (clone $base)->count(),
                'gross_sales' => (float) ((clone $base)->sum('total_amount') ?? 0),
                'tax' => (float) ((clone $base)->sum('tax_amount') ?? 0),
                'average_order' => (float) ((clone $base)->avg('total_amount') ?? 0),
                'paid_orders' => $paid->count(),
                'unpaid_orders' => max(0, (clone $base)->count() - $paid->count()),
                'commission' => (float) ($allocation->commission ?? 0),
                'gateway_fees' => (float) ($allocation->gateway_fees ?? 0),
                'net_business' => (float) ($allocation->payable ?? 0),
            ],
            'status_counts' => $statusCounts,
            'payment_status_counts' => $paymentStatusCounts,
            'source_counts' => $sourceCounts,
            'daily' => $daily,
        ], 'Business report retrieved successfully.');
    }

    public function action(Request $request, Business $business, string $order, OrderPaymentLinkService $paymentLinks, PaymentSettlementService $settlement)
    {
        $role = $this->authorizeBusiness($business);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'ready', 'serve', 'mark_paid', 'confirm_cash', 'complete'])],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_reference' => ['nullable', 'string', 'max:160'],
        ]);

        $updated = DB::transaction(function () use ($business, $order, $validated, $role, $settlement) {
            $record = Order::query()
                ->where('business_id', $business->id)
                ->where('uuid', $order)
                ->lockForUpdate()
                ->firstOrFail();
            OrderWorkLock::query()->where('expires_at', '<=', now())->delete();
            $lock = OrderWorkLock::query()->where('order_id', $record->id)->lockForUpdate()->first();
            abort_unless(! $lock || $lock->user_id === auth()->id(), Response::HTTP_CONFLICT,
                ($lock?->user?->name ?? 'Another staff member') . ' is currently updating this order.');
            $current = OrderStatus::query()->findOrFail($record->order_status_id);

            $transition = match ($validated['action']) {
                'approve' => ['Pending', 'Processing'],
                'reject' => ['Pending', 'Cancelled'],
                'ready' => ['Processing', 'Ready'],
                'serve' => ['Ready', 'Served'],
                'mark_paid' => ['Served', 'Served'],
                'confirm_cash' => ['Served', 'Served'],
                'complete' => ['Served', 'Completed'],
            };

            abort_unless($this->canPerform($role, $validated['action'], $record->channel), HTTP_FORBIDDEN,
                'Your role cannot perform this action for this order.');
            abort_unless($current->name === $transition[0], Response::HTTP_CONFLICT,
                'This order has already changed. Refresh the queue and try again.');

            if ($validated['action'] === 'complete') {
                $paidStatus = PaymentStatus::query()->where('name', 'Completed')->firstOrFail();
                abort_unless($record->payment_status_id === $paidStatus->id, Response::HTTP_UNPROCESSABLE_ENTITY,
                    'Record payment before completing this order.');
            }

            $next = OrderStatus::query()->where('name', $transition[1])->firstOrFail();
            $record->order_status_id = $next->id;
            if ($validated['action'] === 'approve') {
                $record->approver_id = auth()->id();
                $record->assigned_to_user_id = auth()->id();
                $record->approved_at = now();
            }
            if (in_array($validated['action'], ['mark_paid', 'confirm_cash'], true)) {
                $paymentMethodId = $validated['payment_method_id'] ?? null;
                if ($validated['action'] === 'confirm_cash') {
                    $paymentMethodId = PaymentMethod::query()->firstOrCreate(['name' => 'Cash'])->id;
                }
                if (! $paymentMethodId) {
                    $paymentMethodId = $record->payment_method_id;
                }
                if (! $paymentMethodId) {
                    $paymentMethodId = PaymentMethod::query()->firstOrCreate(['name' => 'Cash'])->id;
                }
                $settlement->recordManualPayment(
                    $record,
                    (int) $paymentMethodId,
                    (float) ($validated['payment_amount'] ?? ($record->due_amount ?: $record->total_amount)),
                    $validated['payment_reference'] ?? null,
                    (int) auth()->id(),
                    $validated['action'] === 'confirm_cash' ? 'staff_cash' : 'staff_manual',
                );
            }
            $record->save();

            if (in_array($validated['action'], ['mark_paid', 'confirm_cash'], true)) {
                OrderPaymentLink::query()
                    ->where('order_id', $record->id)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->update(['revoked_at' => now()]);
            }

            OrderStatusHistory::query()->create([
                'order_id' => $record->id,
                'from_status_id' => $current->id,
                'to_status_id' => $next->id,
                'changed_by_user_id' => auth()->id(),
                'note' => $validated['action'] === 'reject'
                    ? trim((string) $validated['reason'])
                    : ($validated['action'] === 'confirm_cash' ? 'Cash payment confirmed by staff.' : ($validated['action'] === 'mark_paid' ? 'Payment recorded by staff.' : null)),
            ]);

            return $record->fresh()->load([
                'status:id,name', 'customer:id,phone,phone_e164', 'approver:id,name', 'assignee:id,name', 'paymentStatus:id,name', 'paymentMethod:id,name',
                'payment:payments.id,payments.order_id,payments.provider,payments.status,payments.expires_at,payments.confirmation_source,payments.confirmed_by_user_id,payments.confirmed_at', 'payment.confirmedBy:id,name',
                'servicePoint:id,type,label,display_name,section_id,sub_section_id',
                'servicePoint.section:id,name', 'servicePoint.subSection:id,name',
                'items:id,order_id,item_id,quantity,unit_price,final_price,total_amount,comment',
                'items.item:id,uuid,name', 'items.options:id,order_item_id,name,price_adjustment',
                'items.options.itemOption:id,uuid',
                'statusHistories.fromStatus:id,name', 'statusHistories.toStatus:id,name',
                'statusHistories.changedBy:id,name',
                'staffNotes.author:id,name', 'workLock.user:id,name',
            ]);
        });

        // Public QR orders have no staff creator. Once a staff member accepts
        // one, notify its verified customer. Staff-created orders deliberately
        // stay manual so the counter can choose cash, link, or QR payment.
        if ($validated['action'] === 'approve' && ! $updated->user_id && $updated->customer?->phone_e164 && app(BusinessActivationService::class)->status($business)['can_accept_mobile_money']) {
            $issued = $paymentLinks->issue($updated, auth()->id(), $updated->customer->phone_e164, 'staff');
            SendOrderPaymentRequestWhatsApp::dispatch($issued['link']->uuid, $issued['token'])->onQueue(config('whatsapp.queue'));
        }

        return $this->sendResponse(['order' => $this->orderData($updated)], 'Order updated successfully.');
    }

    public function paymentQr(Business $business, string $order, GuestOrderSessionService $guestSessions)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless($this->canGeneratePaymentQr($role), HTTP_FORBIDDEN, 'Your role cannot request payment QR codes.');
        $record = Order::query()
            ->where('business_id', $business->id)
            ->where('uuid', $order)
            ->with(['status', 'paymentStatus'])
            ->firstOrFail();

        abort_unless($record->status?->name === 'Served', HTTP_UNPROCESSABLE_ENTITY, 'Only served orders can be presented for payment.');
        abort_unless($record->paymentStatus?->name === 'Pending', HTTP_UNPROCESSABLE_ENTITY, 'Payment has already been recorded for this order.');

        $paymentSession = $guestSessions->issueForOrder($record, 120);
        $url = rtrim((string) config('paperstick.client_url'), '/') . "/orders/{$record->number}?payment_session=" . rawurlencode($paymentSession['access_token']);
        $svg = QrCode::format('svg')->size(320)->margin(1)->generate($url);

        return $this->sendResponse([
            'url' => $url,
            'qr_data_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
        ], 'Customer order QR code generated successfully.');
    }

    public function paymentLink(Business $business, string $order, OrderPaymentLinkService $paymentLinks)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless($this->canGeneratePaymentQr($role), HTTP_FORBIDDEN, 'Your role cannot request payment links.');
        $record = Order::query()->where('business_id', $business->id)->where('uuid', $order)->with(['status', 'paymentStatus', 'customer'])->firstOrFail();
        abort_unless(in_array($record->status?->name, ['Processing', 'Served'], true), HTTP_UNPROCESSABLE_ENTITY, 'Payment links are available once the order is accepted.');
        abort_unless($record->paymentStatus?->name === 'Pending', HTTP_UNPROCESSABLE_ENTITY, 'Payment has already been recorded for this order.');
        abort_unless(app(BusinessActivationService::class)->status($business)['can_accept_mobile_money'], HTTP_UNPROCESSABLE_ENTITY, 'Mobile-money checkout is not available for this business yet.');

        $issued = $paymentLinks->issue($record, auth()->id(), $record->customer?->phone_e164, 'staff');
        $url = $paymentLinks->url($issued['token']);
        if ($record->customer?->phone_e164) {
            DB::afterCommit(fn () => SendOrderPaymentRequestWhatsApp::dispatch($issued['link']->uuid, $issued['token'])->onQueue(config('whatsapp.queue')));
        }

        return $this->sendResponse([
            'url' => $url,
            'expires_at' => $issued['link']->expires_at,
            'whatsapp_queued' => (bool) $record->customer?->phone_e164,
        ], $record->customer?->phone_e164 ? 'Payment link created and WhatsApp delivery queued.' : 'Payment link created. Add a customer mobile number to send it through WhatsApp.');
    }

    public function context(Business $business)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless($this->canCreateOrEdit($role), HTTP_FORBIDDEN, 'Your role cannot create orders.');
        $channels = $business->orderingChannels()->where('ordering_channels.is_active', true)->wherePivot('is_enabled', true)->orderBy('ordering_channels.sort_order')->get(['ordering_channels.slug', 'ordering_channels.name', 'ordering_channels.description']);
        return $this->sendResponse([
            'default_country_id' => $business->district?->city?->country?->id,
            'channels' => $channels,
            'service_points' => ServicePoint::query()->where('business_id', $business->id)->where('is_active', true)->with(['section:id,name', 'subSection:id,name', 'orderingChannels:id,slug'])->orderBy('display_name')->get(['id', 'uuid', 'type', 'label', 'display_name', 'section_id', 'sub_section_id']),
            'categories' => Category::query()->where('business_id', $business->id)->where('is_active', true)->orderBy('name')->get(['id', 'uuid', 'name']),
        ], 'Staff ordering context retrieved successfully.');
    }

    public function menuItems(Request $request, Business $business)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless($this->canCreateOrEdit($role), HTTP_FORBIDDEN, 'Your role cannot create orders.');
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'channel' => ['required', 'string', 'max:24'],
            'include' => ['nullable', 'array', 'max:100'],
            'include.*' => ['uuid'],
        ]);

        abort_unless($business->orderingChannels()->where('ordering_channels.slug', $data['channel'])->where('ordering_channels.is_active', true)->wherePivot('is_enabled', true)->exists(), HTTP_UNPROCESSABLE_ENTITY, 'The selected ordering channel is not enabled for this business.');

        $search = trim((string) ($data['search'] ?? ''));
        $itemQuery = Item::query()
            ->where('is_active', true)
            ->where('is_sold_out', false)
            ->whereHas('category', fn (Builder $query) => $query->where('business_id', $business->id)->where('is_active', true))
            ->when(!empty($data['category_id']), fn (Builder $query) => $query->where('category_id', $data['category_id']))
            ->with([
                'category:id,uuid,name',
                'discountRules',
                'optionGroups' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
                'optionGroups.options' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
            ]);

        $items = (clone $itemQuery)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $itemQuery) => $itemQuery
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('description', 'ilike', "%{$search}%")))
            ->orderBy('name')
            ->limit(40)
            ->get();

        if (!empty($data['include'])) {
            $pinnedItems = (clone $itemQuery)->whereIn('uuid', $data['include'])->get();
            $items = $items->concat($pinnedItems)->unique('id')->values();
        }

        return $this->sendResponse([
            'items' => $items->map(fn (Item $item) => $this->staffMenuItemData($item, $business, $data['channel']))->values(),
        ], 'Available menu items retrieved successfully.');
    }

    public function store(Request $request, Business $business)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless($this->canCreateOrEdit($role), HTTP_FORBIDDEN, 'Your role cannot create orders.');
        $data = $this->orderPayload($request, $business);
        $order = (new OrderRepository())->storeForStaff($business, $data, auth()->id());
        return $this->sendResponse(['order' => $this->orderData($this->loadOrder($order))], 'Order created and sent to the kitchen.', HTTP_CREATED);
    }

    public function update(Request $request, Business $business, string $order)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless($this->canCreateOrEdit($role), HTTP_FORBIDDEN, 'Your role cannot edit orders.');
        $data = $this->orderPayload($request, $business);
        $updated = DB::transaction(function () use ($business, $order, $data, $role) {
            $record = Order::query()->where('business_id', $business->id)->where('uuid', $order)->lockForUpdate()->firstOrFail();
            $record->load('status');
            abort_unless($record->status?->name === 'Pending', Response::HTTP_CONFLICT, 'Only awaiting approval orders can be edited.');
            $this->assertLockOwner($record);
            $servicePoint = !empty($data['service_point_id']) ? ServicePoint::query()->whereKey($data['service_point_id'])->where('business_id', $business->id)->where('is_active', true)->firstOrFail() : null;
            if ($servicePoint) abort_unless($servicePoint->orderingChannels()->where('slug', $data['channel'])->exists(), HTTP_UNPROCESSABLE_ENTITY, 'This service point is not available for the selected ordering channel.');
            $record->items()->each(fn ($item) => $item->options()->delete());
            $record->items()->delete();
            $record->update(['channel' => $data['channel'], 'service_point_id' => $servicePoint?->id, 'service_point_label' => $servicePoint?->display_name, 'comment' => $data['comment'] ?? null]);
            (new OrderItemRepository())->store($record, $data['items'], $data['channel']);
            $business->load('promotions');
            $record->update(app(OrderRepository::class)->totalsFor($business, $record->items()->sum('total_amount')));
            OrderStatusHistory::query()->create(['order_id' => $record->id, 'from_status_id' => $record->order_status_id, 'to_status_id' => $record->order_status_id, 'changed_by_user_id' => auth()->id(), 'note' => 'Order details updated.']);
            return $record;
        });
        return $this->sendResponse(['order' => $this->orderData($this->loadOrder($updated))], 'Order updated successfully.');
    }

    public function storeNote(Request $request, Business $business, string $order)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless($this->canCreateOrEdit($role), HTTP_FORBIDDEN, 'Your role cannot add order notes.');
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        $record = Order::query()->where('business_id', $business->id)->where('uuid', $order)->firstOrFail();
        $note = $record->staffNotes()->create(['user_id' => auth()->id(), 'body' => trim($data['body'])]);
        return $this->sendResponse(['note' => ['uuid' => $note->uuid, 'body' => $note->body, 'author' => auth()->user()->name, 'created_at' => $note->created_at]], 'Team note added successfully.', HTTP_CREATED);
    }

    public function acquireLock(Business $business, string $order)
    {
        $role = $this->authorizeBusiness($business);
        abort_unless($this->canCreateOrEdit($role), HTTP_FORBIDDEN, 'Your role cannot edit orders.');
        $lock = DB::transaction(function () use ($business, $order) {
            $record = Order::query()->where('business_id', $business->id)->where('uuid', $order)->lockForUpdate()->firstOrFail();
            $record->load('status');
            abort_unless($record->status?->name === 'Pending', Response::HTTP_CONFLICT, 'Only awaiting approval orders can be edited.');
            OrderWorkLock::query()->where('expires_at', '<=', now())->delete();
            $lock = OrderWorkLock::query()->where('order_id', $record->id)->lockForUpdate()->first();
            if ($lock && $lock->user_id !== auth()->id()) abort(Response::HTTP_CONFLICT, $lock->user?->name . ' is currently updating this order.');
            $lock ??= new OrderWorkLock(['order_id' => $record->id, 'user_id' => auth()->id()]);
            $lock->expires_at = now()->addMinutes(2);
            $lock->save();
            return $lock->load('user:id,name');
        });
        return $this->sendResponse(['lock' => $this->lockData($lock)], 'Order is ready to edit.');
    }

    public function releaseLock(Business $business, string $order)
    {
        $this->authorizeBusiness($business);
        $record = Order::query()->where('business_id', $business->id)->where('uuid', $order)->firstOrFail();
        OrderWorkLock::query()->where('order_id', $record->id)->where('user_id', auth()->id())->delete();
        return $this->sendResponse([], 'Order lock released.');
    }

    private function orderPayload(Request $request, Business $business): array
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:24'],
            'service_point_id' => ['nullable', 'integer', 'exists:service_points,id'],
            'phone' => ['nullable', 'string', 'max:24'],
            'country_iso2' => ['nullable', 'string', 'size:2', 'exists:countries,iso2'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.uuid' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.comment' => ['nullable', 'string', 'max:500'],
            'items.*.options' => ['nullable', 'array'],
            'items.*.options.*.uuid' => ['required', 'uuid'],
        ]);
        abort_unless($business->orderingChannels()->where('ordering_channels.slug', $data['channel'])->where('ordering_channels.is_active', true)->wherePivot('is_enabled', true)->exists(), HTTP_UNPROCESSABLE_ENTITY, 'The selected ordering channel is not enabled for this business.');
        if ($data['channel'] === 'dine_in') abort_unless(!empty($data['service_point_id']), HTTP_UNPROCESSABLE_ENTITY, 'Select a table, room, or service point for a dine-in order.');
        return $data;
    }

    private function canCreateOrEdit(string $role): bool
    {
        return in_array($role, ['owner', 'vendor_manager', 'business_manager', 'manager', 'counter', 'counter-clerk', 'waiter'], true);
    }

    private function staffMenuItemData(Item $item, Business $business, string $channel): array
    {
        $pricing = app(MenuAvailabilityService::class)->itemPricing($item, $business, $channel);

        return [
            'uuid' => $item->uuid,
            'category_id' => $item->category_id,
            'name' => $item->name,
            'description' => $item->description,
            'price' => (float) $pricing['final_price'],
            'currency' => $item->currency,
            'option_groups' => $item->optionGroups->map(fn ($group) => [
                'uuid' => $group->uuid,
                'name' => $group->name,
                'selection_type' => $group->selection_type,
                'is_required' => (bool) $group->is_required,
                'min_selections' => (int) $group->min_selections,
                'max_selections' => $group->max_selections,
                'options' => $group->options->map(fn ($option) => [
                    'uuid' => $option->uuid,
                    'name' => $option->name,
                    'price_adjustment' => (float) $option->price_adjustment,
                ])->values(),
            ])->values(),
        ];
    }

    private function assertLockOwner(Order $order): void
    {
        OrderWorkLock::query()->where('expires_at', '<=', now())->delete();
        $lock = OrderWorkLock::query()->where('order_id', $order->id)->first();
        abort_unless($lock && $lock->user_id === auth()->id(), Response::HTTP_CONFLICT, 'Open this order in edit mode before saving changes.');
        $lock->update(['expires_at' => now()->addMinutes(2)]);
    }

    private function loadOrder(Order $order): Order
    {
        return $order->fresh()->load([
            'status:id,name', 'customer:id,phone,phone_e164', 'approver:id,name', 'assignee:id,name', 'paymentStatus:id,name', 'paymentMethod:id,name',
            'payment:payments.id,payments.order_id,payments.provider,payments.status,payments.expires_at,payments.confirmation_source,payments.confirmed_by_user_id,payments.confirmed_at', 'payment.confirmedBy:id,name',
            'servicePoint:id,type,label,display_name,section_id,sub_section_id', 'servicePoint.section:id,name', 'servicePoint.subSection:id,name',
            'items:id,order_id,item_id,quantity,unit_price,final_price,total_amount,comment', 'items.item:id,uuid,name', 'items.options:id,order_item_id,name,price_adjustment',
            'items.options.itemOption:id,uuid',
            'statusHistories.fromStatus:id,name', 'statusHistories.toStatus:id,name', 'statusHistories.changedBy:id,name',
            'staffNotes.author:id,name', 'workLock.user:id,name',
        ]);
    }

    private function lockData(?OrderWorkLock $lock): ?array
    {
        if (!$lock || $lock->expires_at->isPast()) return null;
        return ['user_id' => $lock->user_id, 'user_name' => $lock->user?->name, 'expires_at' => $lock->expires_at];
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
        if (in_array($role, ['kitchen', 'chef'], true)) return in_array($tab, ['preparing', 'ready', 'completed', 'cancelled'], true);
        if ($role === 'waiter') return in_array($tab, ['awaiting', 'preparing', 'ready', 'awaiting_payment', 'paid', 'completed', 'cancelled'], true);
        return in_array($tab, ['awaiting', 'preparing', 'ready', 'awaiting_payment', 'paid', 'completed', 'cancelled'], true);
    }

    private function canPerform(string $role, string $action, ?string $channel): bool
    {
        $manager = in_array($role, ['owner', 'vendor_manager', 'business_manager', 'manager'], true);
        if ($manager) return true;
        return match ($action) {
            'approve', 'reject' => in_array($role, ['counter', 'counter-clerk'], true)
                || ($role === 'waiter' && $channel === 'dine_in'),
            'ready' => in_array($role, ['kitchen', 'chef'], true),
            'serve' => in_array($role, ['counter', 'counter-clerk'], true)
                || ($role === 'waiter' && $channel === 'dine_in'),
            'mark_paid', 'complete' => in_array($role, ['counter', 'counter-clerk'], true),
            'confirm_cash' => in_array($role, ['counter', 'counter-clerk'], true)
                || ($role === 'waiter' && $channel === 'dine_in'),
            default => false,
        };
    }

    private function canGeneratePaymentQr(string $role): bool
    {
        return in_array($role, ['owner', 'vendor_manager', 'business_manager', 'manager', 'counter', 'counter-clerk', 'waiter'], true);
    }

    private function tabStatuses(string $tab): array
    {
        return match ($tab) {
            'awaiting' => ['Pending'],
            'preparing' => ['Processing'],
            'ready' => ['Ready'],
            'awaiting_payment', 'paid' => ['Served'],
            'completed' => ['Completed'],
            'cancelled' => ['Cancelled', 'Refunded'],
            default => [],
        };
    }

    private function applyTab(Builder $query, string $tab): Builder
    {
        $query->whereIn('order_status_id', $this->statusIds($this->tabStatuses($tab)));
        if ($tab === 'awaiting_payment') {
            $query->whereHas('paymentStatus', fn (Builder $payment) => $payment->where('name', 'Pending'));
        }
        if ($tab === 'paid') {
            $query->whereHas('paymentStatus', fn (Builder $payment) => $payment->where('name', 'Completed'));
        }
        return $query;
    }

    private function ordersForTab(Business $business, string $role, string $tab): Builder
    {
        if (in_array($role, ['kitchen', 'chef'], true) && $tab === 'completed') {
            return $this->visibleOrders($business, $role)
                ->whereHas('statusHistories', fn (Builder $history) => $history
                    ->where('changed_by_user_id', auth()->id())
                    ->whereHas('toStatus', fn (Builder $status) => $status->where('name', 'Ready')));
        }

        $query = $this->applyTab($this->visibleOrders($business, $role), $tab);

        if ($role === 'waiter' && $tab === 'paid') {
            $query->where(function (Builder $paidOrder) {
                $paidOrder->where('approver_id', auth()->id())
                    ->orWhereHas('payment', fn (Builder $payment) => $payment->where('confirmed_by_user_id', auth()->id()));
            });
        }

        return $query;
    }

    private function statusIds(array $names): array
    {
        return OrderStatus::query()->whereIn('name', $names)->pluck('id')->all();
    }

    private function orderData(Order $order): array
    {
        $point = $order->servicePoint;
        $servedAt = $order->relationLoaded('statusHistories')
            ? $order->statusHistories->first(fn (OrderStatusHistory $history) => $history->toStatus?->name === 'Served')?->created_at
            : null;
        $serviceDurationMinutes = $servedAt
            ? max(0, (int) $order->created_at->diffInMinutes($servedAt))
            : null;
        $serviceLeadTimeSeconds = $servedAt
            ? max(0, (int) $order->created_at->diffInSeconds($servedAt))
            : null;
        $activeCheckout = $order->payment
            && in_array($order->payment->status, ['PENDING', 'PROCESSING'], true)
            && $order->payment->expires_at?->isFuture();

        return [
            'uuid' => $order->uuid,
            'number' => $order->number,
            'channel' => $order->channel,
            'status' => $order->status?->name,
            'payment_status' => $order->paymentStatus?->name,
            'payment_method' => $order->paymentMethod?->name
                ?? ($order->payment?->provider ? str($order->payment->provider)->replace('_', ' ')->title()->toString() : null),
            'paid_by' => $order->payment?->confirmedBy?->name ?? $order->approver?->name,
            'payment_checkout' => $activeCheckout ? [
                'status' => $order->payment->status,
                'expires_at' => $order->payment->expires_at?->toIso8601String(),
            ] : null,
            'customer' => [
                'name' => null,
                'phone' => $this->businessCustomerPhone($order),
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
                'uuid' => $item->item?->uuid,
                'name' => $item->item?->name ?? 'Menu item',
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total_amount,
                'comment' => $item->comment ?? null,
                'options' => $item->options->map(fn ($option) => [
                    'uuid' => $option->itemOption?->uuid,
                    'name' => $option->name,
                    'price_adjustment' => (float) $option->price_adjustment,
                ])->values(),
            ])->values(),
            'total_items_amount' => (float) $order->total_items_amount,
            'tax_amount' => (float) $order->tax_amount,
            'total_amount' => (float) $order->total_amount,
            'created_at' => $order->created_at,
            'service_duration_minutes' => $serviceDurationMinutes,
            'service_lead_time_seconds' => $serviceLeadTimeSeconds,
            'approved_at' => $order->approved_at,
            'approved_by' => $order->approver?->name,
            'assigned_to' => $order->assignee ? ['id' => $order->assignee->id, 'name' => $order->assignee->name] : null,
            'comment' => $order->comment,
            'staff_notes' => $order->relationLoaded('staffNotes') ? $order->staffNotes->map(fn ($note) => [
                'uuid' => $note->uuid,
                'body' => $note->body,
                'author' => $note->author?->name ?? 'Staff',
                'created_at' => $note->created_at,
            ])->values() : [],
            'work_lock' => $order->relationLoaded('workLock') ? $this->lockData($order->workLock) : null,
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

    private function businessCustomerPhone(Order $order): ?string
    {
        $phone = $order->customer?->phone_e164 ?: $order->customer?->phone;

        if (! $phone) {
            return null;
        }

        $paymentCompleted = in_array(strtolower((string) $order->paymentStatus?->name), ['completed', 'paid', 'successful'], true);
        $serviceCompleted = in_array(strtolower((string) $order->status?->name), ['served', 'completed'], true);

        return ($paymentCompleted || $serviceCompleted) ? $this->maskBusinessPhone($phone) : $phone;
    }

    private function maskBusinessPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        return strlen($digits) > 6
            ? '+' . substr($digits, 0, 3) . ' ' . str_repeat('•', strlen($digits) - 6) . ' ' . substr($digits, -3)
            : '••••••';
    }
}
