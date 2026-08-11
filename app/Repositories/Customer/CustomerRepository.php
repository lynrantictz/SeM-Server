<?php

namespace App\Repositories\Customer;

use App\Models\Customer\Customer;
use App\Repositories\BaseRepository;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;

class CustomerRepository extends BaseRepository
{
    const MODEL = Customer::class;

    public function getQuery()
    {
        return $this->query();
    }

    /**
     * Resolve or create a customer using canonical E.164 storage.
     */
    public function getCustomerByPhone(string|int|null $phone, ?string $country = null): Customer
    {
        $canonical = $this->normalize($phone, $country);

        return DB::transaction(function () use ($canonical) {
            $customer = $this->findByCanonicalPhone($canonical);
            if ($customer) {
                $this->populateCanonicalPhone($customer, $canonical);
                return $customer->refresh();
            }

            return $this->storeCanonical($canonical);
        });
    }

    /**
     * Backwards-compatible alias used by existing order code.
     */
    public function checkIfCustomerIsRegistered(string|int|null $phone, ?string $country = null): Customer
    {
        return $this->getCustomerByPhone($phone, $country);
    }

    /**
     * Find a customer without creating one. This is used by the public history
     * lookup so an unknown phone cannot create an empty customer record.
     */
    public function findCustomerByPhone(string|int|null $phone, ?string $country = null): ?Customer
    {
        $canonical = $this->normalize($phone, $country);
        $customer = $this->findByCanonicalPhone($canonical);

        if ($customer) {
            $this->populateCanonicalPhone($customer, $canonical);
            $customer->refresh();
        }

        return $customer;
    }

    public function store(string|int|null $phone, ?string $country = null): Customer
    {
        return $this->storeCanonical($this->normalize($phone, $country));
    }

    private function storeCanonical(string $canonical): Customer
    {
        return $this->query()->create([
            'phone' => $canonical,
            'phone_e164' => $canonical,
        ]);
    }

    private function findByCanonicalPhone(string $canonical): ?Customer
    {
        $normalizer = new PhoneNumberNormalizer();
        $legacyValues = $normalizer->legacyLookupValues($canonical);

        $canonicalCustomer = $this->query()
            ->where('phone_e164', $canonical)
            ->first();

        if ($canonicalCustomer) {
            return $canonicalCustomer;
        }

        return $this->query()
            ->whereIn('phone', $legacyValues)
            ->first();
    }

    private function populateCanonicalPhone(Customer $customer, string $canonical): void
    {
        if ($customer->getRawOriginal('phone_e164') === $canonical) {
            return;
        }

        // A legacy row is never rewritten in place. The additive canonical
        // field lets future lookups use E.164 without losing old data.
        $canonicalAlreadyUsed = $this->query()
            ->where('phone_e164', $canonical)
            ->where('id', '!=', $customer->getKey())
            ->exists();

        if (!$canonicalAlreadyUsed) {
            $customer->forceFill(['phone_e164' => $canonical])->save();
        }
    }

    private function normalize(string|int|null $phone, ?string $country): string
    {
        return (new PhoneNumberNormalizer())->normalize($phone, $country);
    }
}
