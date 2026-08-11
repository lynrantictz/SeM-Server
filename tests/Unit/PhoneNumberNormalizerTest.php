<?php

use App\Exceptions\InvalidPhoneNumberException;
use App\Models\Location\Country;
use App\Services\PhoneNumberNormalizer;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Country::query()->create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    Country::query()->create(['name' => 'Kenya', 'iso2' => 'KE', 'iso3' => 'KEN', 'currency' => 'KES', 'phone_code' => '254', 'flag' => 'flags/ke.jpg']);
});

it('normalizes Tanzanian local and international input to one canonical value', function () {
    $normalizer = new PhoneNumberNormalizer();

    expect($normalizer->normalize('0758 483 019'))->toBe('255758483019')
        ->and($normalizer->normalize('+255758483019'))->toBe('255758483019')
        ->and($normalizer->normalize('255758483019', 'TZ'))->toBe('255758483019');
});

it('keeps a second country distinct when national digits match', function () {
    $normalizer = new PhoneNumberNormalizer();

    expect($normalizer->normalize('+254758483019'))
        ->toBe('254758483019')
        ->and($normalizer->normalize('254758483019', 'TZ'))->toBe('254758483019')
        ->not->toBe($normalizer->normalize('0758483019'));
});

it('includes legacy nine-digit Tanzanian values in lookup candidates', function () {
    expect((new PhoneNumberNormalizer())->legacyLookupValues('255758483019'))
        ->toContain('758483019');
});

it('rejects malformed and out-of-range phone input', function (string $phone) {
    (new PhoneNumberNormalizer())->normalize($phone);
})->with([
    'not-a-phone',
    '+255',
    '0000000000',
    '+1234567890123456',
])->throws(InvalidPhoneNumberException::class);

it('raises the phone-specific validation exception', function () {
    expect(fn () => (new PhoneNumberNormalizer())->normalize('abc'))
        ->toThrow(InvalidPhoneNumberException::class);
});
