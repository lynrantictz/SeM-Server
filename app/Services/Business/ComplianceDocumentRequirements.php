<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Models\Business\ComplianceDocumentType;
use App\Models\Business\Vendor;
use Illuminate\Support\Collection;

class ComplianceDocumentRequirements
{
    private array $definitions = [];

    public function available(string $scope, Business|Vendor $owner): Collection
    {
        if ($owner instanceof Business) {
            $owner->loadMissing('district.city');
            $countryId = $owner->district?->city?->country_id;
            $businessTypeId = $owner->business_type_id;
        } else {
            $countryId = $owner->country_id;
            $businessTypeId = null;
        }

        $types = $this->definitions[$scope] ??= ComplianceDocumentType::query()
            ->where('scope', $scope)
            ->where('is_active', true)
            ->with(['rules' => fn ($query) => $query->where('is_active', true)->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $types->map(function (ComplianceDocumentType $type) use ($scope, $owner, $countryId, $businessTypeId) {
            $rule = $type->rules
                ->filter(function ($rule) use ($countryId, $businessTypeId) {
                    return ($rule->country_id === null || $rule->country_id === $countryId)
                        && ($rule->business_type_id === null || $rule->business_type_id === $businessTypeId);
                })
                ->sortByDesc(fn ($rule) => (int) ($rule->country_id !== null) + (int) ($rule->business_type_id !== null))
                ->first();

            if (! $rule) {
                return null;
            }

            return [
                'id' => $type->id,
                'key' => $type->key,
                'name' => $type->key === 'business_registration_certificate'
                    ? ($owner instanceof Vendor && filled($owner->business_license_number) && blank($owner->tin)
                        ? 'Business licence certificate'
                        : 'Company registration certificate')
                    : $type->name,
                'requires_expiry_date' => $type->requires_expiry_date,
                'reminder_days' => $type->reminder_days ?? [],
                'required_for_payment_activation' => $this->isRequiredForActivation($scope, $owner, $type->key, $rule->required_for_payment_activation),
            ];
        })
            ->filter()
            ->values();
    }

    private function isRequiredForActivation(string $scope, Business|Vendor $owner, string $key, bool $required): bool
    {
        if ($scope !== 'vendor' || ! $owner instanceof Vendor) {
            return $required;
        }

        $hasTin = filled($owner->tin);
        $hasBusinessLicence = filled($owner->business_license_number);

        // The vendor supplies one identifier. Require the corresponding proof
        // and make the alternate proof optional. If legacy data has both or
        // neither identifier, keep configured requirements as a safe fallback.
        if ($hasTin && ! $hasBusinessLicence && $key === 'business_registration_certificate') {
            return false;
        }

        if ($hasBusinessLicence && ! $hasTin && $key === 'tin_certificate') {
            return false;
        }

        return $required;
    }
}
