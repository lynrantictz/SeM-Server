<?php

use App\Http\Controllers\Api\V1\Business\BusinessOrderController;
use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Order\Order;
use App\Models\Menu\Item;
use App\Models\Menu\ItemOption;
use App\Models\Menu\ItemOptionGroup;
use App\Repositories\Order\OrderItemRepository;
use Illuminate\Validation\ValidationException;
use App\Services\Business\BusinessBillingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('limits waiter order visibility to orders they approved or are assigned', function () {
    $controller = new BusinessOrderController;
    $business = new Business;
    $business->id = 42;
    $waiter = new User;
    $waiter->id = 72;
    $this->actingAs($waiter, 'api');
    $query = (new ReflectionMethod($controller, 'visibleOrders'))->invoke($controller, $business, 'waiter');
    expect($query->getBindings())->toBe([42, 72, 72]);
});

it('allows waiter and counter roles to use the staff order editor', function (string $role) {
    $controller = new BusinessOrderController;
    expect((new ReflectionMethod($controller, 'canCreateOrEdit'))->invoke($controller, $role))->toBeTrue();
})->with(['waiter', 'counter', 'counter-clerk']);

it('rejects guest phone fields in generic order edits', function () {
    $controller = new BusinessOrderController;
    $guard = new ReflectionMethod($controller, 'rejectGuestContactChanges');

    expect(fn () => $guard->invoke($controller, Request::create('/orders/example', 'PUT', [
        'phone' => '+255712345678',
        'country_iso2' => 'TZ',
    ])))->toThrow(HttpException::class);
});

it('allows generic order edits when guest phone fields are omitted', function () {
    $controller = new BusinessOrderController;
    $guard = new ReflectionMethod($controller, 'rejectGuestContactChanges');

    $guard->invoke($controller, Request::create('/orders/example', 'PUT', [
        'comment' => 'No onions',
    ]));

    expect(true)->toBeTrue();
});

it('keeps order edit audit notes within the database note limit', function () {
    $controller = new BusinessOrderController;
    $makeNote = new ReflectionMethod($controller, 'orderEditHistoryNote');

    $note = $makeNote->invoke($controller, str_repeat('Change reason. ', 80), 7000, 12000, 6);

    expect(mb_strlen($note))->toBeLessThanOrEqual(500)
        ->and($note)->toStartWith('Order changed: ')
        ->and($note)->toContain('Total: 7000.00 to 12000.00 | Lines: 6');
});


it('preserves an inactive saved option during order edits', function () {
    $group = new ItemOptionGroup;
    $group->forceFill(['id' => 31, 'uuid' => '10000000-0000-4000-8000-000000000031', 'name' => 'Sides', 'is_active' => true, 'selection_type' => 'single', 'is_required' => true, 'min_selections' => 1, 'max_selections' => 1]);
    $option = new ItemOption;
    $option->forceFill(['id' => 41, 'uuid' => '20000000-0000-4000-8000-000000000041', 'item_option_group_id' => 31, 'name' => 'Seasonal salad', 'is_active' => false, 'price_adjustment' => 0]);
    $group->setRelation('options', collect([$option]));
    $item = new Item;
    $item->forceFill(['name' => 'Lunch plate']);
    $item->setRelation('optionGroups', collect([$group]));
    $repository = new OrderItemRepository;
    $resolve = new ReflectionMethod($repository, 'resolveOptions');

    $resolved = $resolve->invoke($repository, ['options' => [['uuid' => $option->uuid]]], $item, [$option->uuid]);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['item_option_id'])->toBe(41);
});

it('does not allow a newly selected inactive option during order edits', function () {
    $group = new ItemOptionGroup;
    $group->forceFill(['id' => 32, 'uuid' => '10000000-0000-4000-8000-000000000032', 'name' => 'Sides', 'is_active' => true, 'selection_type' => 'single', 'is_required' => true, 'min_selections' => 1, 'max_selections' => 1]);
    $option = new ItemOption;
    $option->forceFill(['id' => 42, 'uuid' => '20000000-0000-4000-8000-000000000042', 'item_option_group_id' => 32, 'name' => 'Seasonal salad', 'is_active' => false, 'price_adjustment' => 0]);
    $group->setRelation('options', collect([$option]));
    $item = new Item;
    $item->forceFill(['name' => 'Lunch plate']);
    $item->setRelation('optionGroups', collect([$group]));
    $repository = new OrderItemRepository;
    $resolve = new ReflectionMethod($repository, 'resolveOptions');

    expect(fn () => $resolve->invoke($repository, ['options' => [['uuid' => $option->uuid]]], $item, []))
        ->toThrow(ValidationException::class);
});

it('preserves the saved choices inside an inactive option group', function () {
    $group = new ItemOptionGroup;
    $group->forceFill(['id' => 33, 'uuid' => '10000000-0000-4000-8000-000000000033', 'name' => 'Seasonal set', 'is_active' => false, 'selection_type' => 'single', 'is_required' => true, 'min_selections' => 1, 'max_selections' => 1]);
    $option = new ItemOption;
    $option->forceFill(['id' => 43, 'uuid' => '20000000-0000-4000-8000-000000000043', 'item_option_group_id' => 33, 'name' => 'Seasonal salad', 'is_active' => false, 'price_adjustment' => 0]);
    $group->setRelation('options', collect([$option]));
    $item = new Item;
    $item->forceFill(['name' => 'Lunch plate']);
    $item->setRelation('optionGroups', collect([$group]));
    $repository = new OrderItemRepository;
    $resolve = new ReflectionMethod($repository, 'resolveOptions');

    $resolved = $resolve->invoke($repository, ['options' => [['uuid' => $option->uuid]]], $item, [$option->uuid]);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['item_option_id'])->toBe(43);
});

it('does not allow kitchen staff to edit customer orders', function () {
    $controller = new BusinessOrderController;
    expect((new ReflectionMethod($controller, 'canCreateOrEdit'))->invoke($controller, 'kitchen'))->toBeFalse();
});

it('keeps order queues and actions separated by frontline role', function (string $role, string $tab, bool $canSee, string $action, bool $canAct, string $channel = 'dine_in') {
    $controller = new BusinessOrderController;
    $canSeeTab = new ReflectionMethod($controller, 'canSeeTab');
    $canPerform = new ReflectionMethod($controller, 'canPerform');

    expect($canSeeTab->invoke($controller, $role, $tab))->toBe($canSee)
        ->and($canPerform->invoke($controller, $role, $action, $channel))->toBe($canAct);
})->with([
    // Counter staff can approve incoming orders, verify payments, and serve them.
    ['counter', 'awaiting', true, 'approve', true],
    ['counter-clerk', 'awaiting', true, 'reject', true],
    ['counter', 'awaiting_payment', true, 'mark_paid', true],
    ['counter', 'ready', true, 'serve', true],
    ['counter', 'paid', false, 'complete', true],
    ['counter', 'awaiting_payment', true, 'confirm_cash', true],
    ['counter', 'preparing', true, 'ready', false],
    // Waiters handle orders and payments, and may close an order after it is paid.
    ['waiter', 'awaiting', true, 'approve', true],
    ['waiter', 'ready', true, 'serve', true],
    ['waiter', 'awaiting_payment', true, 'mark_paid', true],
    ['waiter', 'awaiting_payment', true, 'confirm_cash', true],
    ['waiter', 'awaiting_payment', true, 'confirm_cash', false, 'online'],
    ['waiter', 'completed', true, 'complete', true],
    // Kitchen and chefs only advance preparation; they cannot approve or verify payment.
    ['kitchen', 'preparing', true, 'ready', true],
    ['chef', 'preparing', true, 'ready', true],
    ['kitchen', 'awaiting', false, 'approve', false],
    ['chef', 'awaiting_payment', false, 'mark_paid', false],
    ['chef', 'ready', true, 'serve', false],
    ['chef', 'completed', true, 'complete', false],
]);

it('serializes owner orders when the loaded business relation omits vendor_id', function () {
    $order = new Order;
    $order->forceFill([
        'id' => 1,
        'uuid' => '00000000-0000-4000-8000-000000000001',
        'number' => 'ORD-TEST-000001',
        'channel' => 'dine_in',
        'total_items_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 100,
        'created_at' => now(),
    ]);
    $business = new Business;
    $business->id = 42;
    $business->setRelation('paymentSetting', null);

    foreach ([
        'business' => $business,
        'servicePoint' => null,
        'statusHistories' => collect(),
        'payment' => null,
        'status' => null,
        'paymentStatus' => null,
        'paymentMethod' => null,
        'customer' => null,
        'approver' => null,
        'assignee' => null,
        'items' => collect(),
    ] as $relation => $value) {
        $order->setRelation($relation, $value);
    }

    $billing = Mockery::mock(BusinessBillingService::class);
    $billing->shouldReceive('orderFee')->once()->with($order)->andReturn(['amount' => 1.0]);
    app()->instance(BusinessBillingService::class, $billing);

    $serialized = (new ReflectionMethod(new BusinessOrderController, 'orderData'))
        ->invoke(new BusinessOrderController, $order, 'owner');

    expect($serialized['uuid'])->toBe($order->uuid)
        ->and($serialized['paperstic_fee'])->toBe(['amount' => 1.0]);
});
