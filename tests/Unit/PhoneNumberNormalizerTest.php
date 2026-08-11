<?php

use App\Exceptions\InvalidPhoneNumberException;
use App\Services\PhoneNumberNormalizer;

it('normalizes Tanzanian local and international input to one E.164 value', function () {
    $normalizer = new PhoneNumberNormalizer();

    expect($normalizer->normalize('0758 483 019'))->toBe('+255758483019')
        ->and($normalizer->normalize('+255758483019'))->toBe('+255758483019')
        ->and($normalizer->normalize('255758483019'))->toBe('+255758483019');
});

it('keeps a second country distinct when national digits match', function () {
    $normalizer = new PhoneNumberNormalizer();

    expect($normalizer->normalize('+254758483019'))
        ->toBe('+254758483019')
        ->not->toBe($normalizer->normalize('0758483019'));
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
