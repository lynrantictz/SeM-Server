<?php

use App\Http\Controllers\Api\V1\Business\BusinessOrderController;
use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Order\Order;
use App\Services\Business\BusinessBillingService;

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
    ['counter', 'paid', true, 'complete', true],
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
