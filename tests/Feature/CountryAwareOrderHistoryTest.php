<?php

use App\Models\Customer\Customer;
use App\Models\Location\Country;
use App\Models\Order\Order;
use App\Repositories\Customer\CustomerRepository;
use App\Repositories\Order\OrderCustomerVerificationRepository;
use App\Repositories\Order\OrderRepository;

function historyOrderFor(Customer $customer, string $number): Order
{
    return Order::query()->create([
        'business_id' => 1,
        'customer_id' => $customer->id,
        'number' => $number,
    ]);
}

it('keeps Tanzanian local and international lookup compatible', function () {
    $customer = Customer::query()->create(['phone' => '0758483019']);
    historyOrderFor($customer, 'TZ-000001');

    $localResponse = $this->getJson('/api/v1/phone/0758483019/verify');
    $internationalResponse = $this->getJson('/api/v1/phone/+255758483019/verify');

    $localResponse->assertOk()
        ->assertJsonPath('data.0.number', 'TZ-000001')
        ->assertJsonMissingPath('data.0.customer.phone');
    $internationalResponse->assertOk()
        ->assertJsonPath('data.0.number', 'TZ-000001');

    expect($customer->fresh()->phone)->toBe('+255758483019');
});

it('finds a legacy nine-digit Tanzanian customer by canonical input', function () {
    $customer = Customer::query()->create(['phone' => '758483019']);
    historyOrderFor($customer, 'TZ-000006');

    $this->getJson('/api/v1/phone/+255758483019/verify')
        ->assertOk()
        ->assertJsonPath('data.0.number', 'TZ-000006');
});

it('does not return a Tanzanian order for another country with the same national digits', function () {
    $tanzania = Customer::query()->create(['phone' => '+255758483019']);
    $kenya = Customer::query()->create([
        'phone' => '+254758483019',
        'phone_e164' => '+254758483019',
    ]);
    historyOrderFor($tanzania, 'TZ-000002');
    historyOrderFor($kenya, 'KE-000001');

    $response = $this->getJson('/api/v1/phone/254758483019/verify');

    $response->assertOk()
        ->assertJsonPath('data.0.number', 'KE-000001')
        ->assertJsonMissing(['number' => 'TZ-000002']);
});

it('stores canonical phones for customers and order verification records', function () {
    $customer = (new CustomerRepository())->getCustomerByPhone('0758483019');
    $order = historyOrderFor($customer, 'TZ-000003');

    (new OrderCustomerVerificationRepository())->storeOrUpdatePhone($order);

    expect($customer->fresh()->phone)->toBe('+255758483019')
        ->and($order->fresh()->customerVerification->phone)->toBe('+255758483019');
});

it('reassigns only the changed order to the new canonical customer', function () {
    $oldCustomer = (new CustomerRepository())->getCustomerByPhone('0758483019');
    $order = historyOrderFor($oldCustomer, 'TZ-000004');
    $otherOrder = historyOrderFor($oldCustomer, 'TZ-000005');

    (new OrderRepository())->changePhone($order, ['phone' => '+254758483019']);

    $newCustomer = (new CustomerRepository())->findCustomerByPhone('+254758483019');

    expect($newCustomer)->not->toBeNull()
        ->and($order->fresh()->customer_id)->toBe($newCustomer->id)
        ->and($otherOrder->fresh()->customer_id)->toBe($oldCustomer->id)
        ->and($oldCustomer->fresh()->orders()->pluck('id')->all())->toBe([$otherOrder->id])
        ->and($order->fresh()->customerVerification->phone)->toBe('+254758483019');
});

it('rejects an unsupported numeric country code', function () {
    Country::query()->create([
        'name' => 'Tanzania',
        'iso2' => 'TZ',
        'iso3' => 'TZA',
        'currency' => 'TZS',
        'phone_code' => '255',
        'flag' => 'flags/tz.jpg',
    ]);

    expect(fn () => (new \App\Services\PhoneNumberNormalizer())->normalize('758483019', '999'))
        ->toThrow(\App\Exceptions\InvalidPhoneNumberException::class);
});

it('returns a validation error for invalid history phone input', function () {
    $this->getJson('/api/v1/phone/not-a-phone/verify')
        ->assertUnprocessable()
        ->assertJsonPath('success', false);
});
