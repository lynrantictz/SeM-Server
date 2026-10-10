<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\ComplianceDocumentType;
use App\Models\Business\BusinessPaymentMethod;

class BusinessActivationService
{
    /**
     * Resolve the operational and payment readiness of one business.
     *
     * A business can operate with direct/manual payment methods once it is
     * active and its required compliance documents are approved. Online
     * mobile-money checkout remains a separate, future AzamPay capability.
     */
    public function status(Business $business): array
    {
        $business->loadMissing([
            'district.city',
            'paymentSetting',
            'complianceDocuments' => fn ($query) => $query->latest('id'),
            'paymentMethods.paymentMethod',
            'paymentMethods.accounts',
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
        $configuredMethods = $business->paymentMethods
            ->filter(fn (BusinessPaymentMethod $configuration): bool =>
                $configuration->is_enabled && $configuration->status === 'active'
            );
        $completeMethods = $configuredMethods->filter(
            fn (BusinessPaymentMethod $configuration): bool => $this->paymentMethodIsComplete($configuration)
        );
        $paymentMethodsApproved = $completeMethods->isNotEmpty();
        $checkoutEnabled = $business->paymentSetting?->provider === 'azampay'
            && (bool) $business->paymentSetting->is_checkout_enabled;
        $manualPaymentReady = (bool) $business->is_active
            && $documentsApproved
            && $paymentMethodsApproved;
        $canAcceptMobileMoney = $manualPaymentReady && $checkoutEnabled;

        return [
            'status' => $manualPaymentReady ? 'active' : 'needs_review',
            'business_enabled' => (bool) $business->is_active,
            'documents_approved' => $documentsApproved,
            'payment_methods_approved' => $paymentMethodsApproved,
            'configured_payment_method_count' => $configuredMethods->count(),
            'approved_payment_method_count' => $completeMethods->count(),
            'manual_payment_ready' => $manualPaymentReady,
            'payment_checkout_enabled' => $checkoutEnabled,
            'online_checkout_ready' => $canAcceptMobileMoney,
            'can_accept_mobile_money' => $canAcceptMobileMoney,
            'pending_document_types' => $pendingDocumentTypes,
        ];
    }

    private function paymentMethodIsComplete(BusinessPaymentMethod $configuration): bool
    {
        $method = $configuration->paymentMethod;
        if (! $method) {
            return false;
        }

        if ($method->code === 'bank_transfer') {
            return $configuration->accounts->isNotEmpty()
                && $configuration->accounts->every(fn ($account): bool =>
                    $account->is_enabled
                    && $account->status === 'active'
                    && filled($account->bank_name)
                    && filled($account->account_number)
                    && filled($account->account_holder_name)
                );
        }

        return ! $method->requires_identifier || filled($configuration->identifier);
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
