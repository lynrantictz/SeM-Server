<?php

namespace App\Services;

use App\Exceptions\InvalidPhoneNumberException;
use App\Models\Location\Country;

/**
 * Converts supported phone input to the canonical digits-only E.164 representation.
 *
 * National input requires country context; legacy Tanzanian local numbers
 * remain supported when TZ is supplied.
 */
final class PhoneNumberNormalizer
{
    private const DEFAULT_COUNTRY_CODE = '255';

    public function normalize(string|int|null $value, ?string $country = null): string
    {
        if ($value === null || trim((string) $value) === '') {
            throw new InvalidPhoneNumberException('A phone number is required.');
        }

        $input = trim((string) $value);

        if (!preg_match('/^[+0-9().\s-]+$/', $input) || substr_count($input, '+') > 1) {
            throw new InvalidPhoneNumberException('The phone number format is invalid.');
        }

        $hasPlus = str_starts_with($input, '+');
        if (str_contains($input, '+') && !$hasPlus) {
            throw new InvalidPhoneNumberException('The phone number format is invalid.');
        }

        $digits = preg_replace('/\D+/', '', $input);
        if ($digits === null || $digits === '') {
            throw new InvalidPhoneNumberException('The phone number format is invalid.');
        }

        // Also accept the common international access-code form.
        if (!$hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $countryCode = $country === null ? null : $this->countryCode($country);

        if (!$hasPlus && strlen($digits) === 9 && $countryCode === self::DEFAULT_COUNTRY_CODE) {
            return $this->asE164(self::DEFAULT_COUNTRY_CODE . $digits);
        }

        if (str_starts_with($digits, '0')) {
            if ($countryCode === null) {
                throw new InvalidPhoneNumberException('A country is required for this phone number.');
            }

            return $this->asE164($countryCode . substr($digits, 1));
        }

        if (!$hasPlus && $countryCode === null && strlen($digits) === 9) {
            throw new InvalidPhoneNumberException('A country is required for this phone number.');
        }

        // Non-national digits are international input. Resolve their calling
        // code before considering any supplied country.
        if ($this->hasSupportedCallingCode($digits)) {
            return $this->asE164($digits);
        }

        throw new InvalidPhoneNumberException('The phone number country code is invalid.');
    }

    /**
     * Values used only when resolving pre-E.164 customer rows.
     *
     * A local legacy value is considered Tanzanian only. Treating every
     * country's national digits as TZ would reintroduce the country collision
     * this normalizer is intended to prevent.
     */
    public function legacyLookupValues(string $canonical): array
    {
        $digits = ltrim($canonical, '+');
        $values = [$canonical, $digits];

        if (str_starts_with($digits, self::DEFAULT_COUNTRY_CODE) && strlen($digits) > 3) {
            $nationalDigits = substr($digits, 3);
            $values[] = '0' . $nationalDigits;

            if (strlen($nationalDigits) === 9) {
                $values[] = $nationalDigits;
            }
        }

        return array_values(array_unique($values));
    }

    private function countryCode(?string $country): string
    {
        if ($country === null || trim($country) === '') {
            return self::DEFAULT_COUNTRY_CODE;
        }

        $country = trim($country);
        if (preg_match('/^[0-9]{1,3}$/', $country)) {
            $countryModel = Country::query()
                ->where('phone_code', $country)
                ->first();

            if (!$countryModel) {
                throw new InvalidPhoneNumberException('The country code is invalid.');
            }

            return (string) $countryModel->phone_code;
        }

        if (!preg_match('/^[A-Za-z]{2}$/', $country)) {
            throw new InvalidPhoneNumberException('The country code is invalid.');
        }

        $countryModel = Country::query()
            ->where('iso2', strtoupper($country))
            ->first();

        if (!$countryModel) {
            throw new InvalidPhoneNumberException('The country code is invalid.');
        }

        return (string) $countryModel->phone_code;
    }

    private function asE164(string $digits): string
    {
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
            throw new InvalidPhoneNumberException('The phone number must contain 8 to 15 international digits.');
        }

        if (!$this->hasSupportedCallingCode($digits)) {
            throw new InvalidPhoneNumberException('The phone number country code is invalid.');
        }

        return $digits;
    }

    private function hasSupportedCallingCode(string $digits): bool
    {
        return Country::query()->pluck('phone_code')
            ->map(fn ($code) => (string) $code)
            ->contains(fn (string $code): bool => str_starts_with($digits, $code));
    }
}
