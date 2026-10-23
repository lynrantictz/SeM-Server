<?php

use App\Http\Controllers\Api\V1\Business\BusinessOrderController;
use App\Models\Business\Business;

it('includes online orders in waiter visibility while excluding takeaway', function () {
    $controller = new BusinessOrderController;
    $business = new Business;
    $business->id = 42;
    $query = (new ReflectionMethod($controller, 'visibleOrders'))->invoke($controller, $business, 'waiter');
    expect($query->getBindings())->toBe([42, 'dine_in', 'online']);
});

it('allows waiter and counter roles to use the staff order editor', function (string $role) {
    $controller = new BusinessOrderController;
    expect((new ReflectionMethod($controller, 'canCreateOrEdit'))->invoke($controller, $role))->toBeTrue();
})->with(['waiter', 'counter', 'counter-clerk']);

it('does not allow kitchen staff to edit customer orders', function () {
    $controller = new BusinessOrderController;
    expect((new ReflectionMethod($controller, 'canCreateOrEdit'))->invoke($controller, 'kitchen'))->toBeFalse();
});
