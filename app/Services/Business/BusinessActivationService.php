<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\ComplianceDocumentType;

class BusinessActivationService
{
    /**
     * Resolve the operational and payment readiness of one business.
     *
     * Payment checkout may be offered only after Paperstic has enabled the
     * business, approved every required compliance document, and enabled its
     * checkout configuration.
     */
    public function status(Business $business): array
    {
        $business->loadMissing([
            'district.city',
            'paymentSetting',
            'complianceDocuments' => fn ($query) => $query->latest('id'),
        ]);

        $requiredDocumentTypes = $this->requiredDocumentTypes($business);
        $latestDocuments = $business->complianceDocuments
            ->groupBy('document_type')
            ->map->first();

        $pendingDocumentTypes = $requiredDocumentTypes
            ->filter(function (array $definition) use ($latestDocuments) {
                $document = $latestDocuments->get($definition['key']);

                return ! $this->isApprovedAndCurrent($document);
            })
            ->pluck('key')
            ->values()
            ->all();

        $documentsApproved = $pendingDocumentTypes === [];
        $checkoutEnabled = $business->paymentSetting?->provider === 'azampay'
            && (bool) $business->paymentSetting->is_checkout_enabled;
        $canAcceptMobileMoney = (bool) $business->is_active
            && $documentsApproved
            && $checkoutEnabled;

        return [
            'status' => $canAcceptMobileMoney ? 'active' : 'needs_review',
            'business_enabled' => (bool) $business->is_active,
            'documents_approved' => $documentsApproved,
            'payment_checkout_enabled' => $checkoutEnabled,
            'can_accept_mobile_money' => $canAcceptMobileMoney,
            'pending_document_types' => $pendingDocumentTypes,
        ];
    }

    private function requiredDocumentTypes(Business $business)
    {
        $countryId = $business->district?->city?->country_id;

        return ComplianceDocumentType::query()
            ->where('scope', 'business')
            ->where('is_active', true)
            ->with(['rules' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (ComplianceDocumentType $type) use ($business, $countryId) {
                $rule = $type->rules
                    ->filter(fn ($rule) => ($rule->country_id === null || $rule->country_id === $countryId)
                        && ($rule->business_type_id === null || $rule->business_type_id === $business->business_type_id))
                    ->sortByDesc(fn ($rule) => (int) ($rule->country_id !== null) + (int) ($rule->business_type_id !== null))
                    ->first();

                if (! $rule || ! $rule->required_for_payment_activation) {
                    return null;
                }

                return ['key' => $type->key];
            })
            ->filter()
            ->values();
    }

    private function isApprovedAndCurrent(?ComplianceDocument $document): bool
    {
        return $document !== null
            && $document->status === 'approved'
            && ! ($document->expires_at && $document->expires_at->isPast());
    }
}
