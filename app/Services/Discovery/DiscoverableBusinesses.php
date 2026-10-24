<?php

namespace App\Services\Discovery;

use App\Models\Business\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class DiscoverableBusinesses
{
    public function query(): Builder
    {
        $query = Business::query()
            ->where('businesses.is_active', true)
            ->whereNotNull('businesses.latitude')
            ->whereNotNull('businesses.longitude')
            ->whereHas('vendor')
            ->whereHas('district.city');

        $this->requireCompliance($query, 'vendor');
        $this->requireCompliance($query, 'business');

        return $query;
    }

    private function requireCompliance(Builder $query, string $scope): void
    {
        $type = 'discovery_'.$scope.'_type';
        $rule = 'discovery_'.$scope.'_rule';
        $document = 'discovery_'.$scope.'_document';
        $newer = 'discovery_'.$scope.'_newer';
        $ownerColumn = $scope === 'vendor' ? 'vendor_id' : 'business_id';
        $owner = $scope === 'vendor' ? 'businesses.vendor_id' : 'businesses.id';
        $country = $scope === 'vendor'
            ? '(SELECT country_id FROM vendors WHERE vendors.id = businesses.vendor_id)'
            : '(SELECT city.country_id FROM districts AS district JOIN cities AS city ON city.id = district.city_id WHERE district.id = businesses.district_id)';
        $businessTypeMatch = $scope === 'business'
            ? '('.$rule.'.business_type_id IS NULL OR '.$rule.'.business_type_id = businesses.business_type_id)'
            : $rule.'.business_type_id IS NULL';

        // Resolve the same most-specific active rule as ComplianceDocumentRequirements.
        // Apply compliance in SQL before limits, distance sorting and region averages.
        $effectiveRule = '(SELECT '.$rule.'.required_for_payment_activation FROM compliance_document_type_rules AS '.$rule.
            ' WHERE '.$rule.'.compliance_document_type_id = '.$type.'.id AND '.$rule.'.is_active = ?'.
            ' AND ('.$rule.'.country_id IS NULL OR '.$rule.'.country_id = '.$country.') AND '.$businessTypeMatch.
            ' ORDER BY (CASE WHEN '.$rule.'.country_id IS NULL THEN 0 ELSE 1 END + CASE WHEN '.$rule.'.business_type_id IS NULL THEN 0 ELSE 1 END) DESC, '.$rule.'.id ASC LIMIT 1) = ?';

        $query->whereNotExists(function (QueryBuilder $types) use ($scope, $type, $effectiveRule, $document, $newer, $ownerColumn, $owner): void {
            $types->selectRaw('1')->from('compliance_document_types as '.$type)
                ->where($type.'.scope', $scope)->where($type.'.is_active', true)
                ->whereRaw($effectiveRule, [true, true])
                ->whereNotExists(function (QueryBuilder $documents) use ($type, $document, $newer, $ownerColumn, $owner): void {
                    $documents->selectRaw('1')->from('compliance_documents as '.$document)
                        ->whereColumn($document.'.'.$ownerColumn, $owner)
                        ->whereColumn($document.'.document_type', $type.'.key')
                        ->where($document.'.status', 'approved')
                        // Existing compliance checks treat a dated document as expired
                        // once its expiry date has started; undated documents stay valid.
                        ->where(fn ($expiry) => $expiry->whereNull($document.'.expires_at')->orWhereDate($document.'.expires_at', '>', today()))
                        ->whereNotExists(function (QueryBuilder $latest) use ($document, $newer, $ownerColumn): void {
                            $latest->selectRaw('1')->from('compliance_documents as '.$newer)
                                ->whereColumn($newer.'.'.$ownerColumn, $document.'.'.$ownerColumn)
                                ->whereColumn($newer.'.document_type', $document.'.document_type')
                                ->whereColumn($newer.'.id', '>', $document.'.id');
                        });
                });
        });
    }
}
