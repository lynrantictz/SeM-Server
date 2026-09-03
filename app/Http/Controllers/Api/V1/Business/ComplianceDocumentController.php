<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\ComplianceDocumentType;
use App\Models\Business\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ComplianceDocumentController extends BaseController
{
    public function businessIndex(Business $business): mixed
    {
        $this->ensureCanViewBusiness($business);

        return $this->sendResponse(
            $this->documentList($business->complianceDocuments()->with('documentType')->latest()->get(), 'business', $business),
            'Business compliance documents retrieved successfully.'
        );
    }

    public function businessStore(Request $request, Business $business): mixed
    {
        $this->ensureCanManageBusiness($business);
        $document = $this->storeDocument($request, 'business', $business);

        return $this->sendResponse([
            'document' => $this->documentData($document),
        ], 'Business document uploaded successfully.', HTTP_CREATED);
    }

    public function businessDownload(Business $business, ComplianceDocument $document)
    {
        $this->ensureCanViewBusiness($business);
        abort_unless($document->business_id === $business->id, HTTP_NOT_FOUND);

        return $this->download($document);
    }

    public function businessUpdate(Request $request, Business $business, ComplianceDocument $document): mixed
    {
        $this->ensureCanManageBusiness($business);
        abort_unless($document->business_id === $business->id, HTTP_NOT_FOUND);

        return $this->updateDocument($request, $document);
    }

    public function businessDestroy(Business $business, ComplianceDocument $document): mixed
    {
        $this->ensureCanManageBusiness($business);
        abort_unless($document->business_id === $business->id, HTTP_NOT_FOUND);
        $this->destroyDocument($document);

        return $this->sendResponse([], 'Business document deleted successfully.');
    }

    public function vendorIndex(Vendor $vendor): mixed
    {
        $this->ensureCanViewVendor($vendor);

        return $this->sendResponse(
            $this->documentList($vendor->complianceDocuments()->with('documentType')->latest()->get(), 'vendor', $vendor),
            'Vendor compliance documents retrieved successfully.'
        );
    }

    public function vendorStore(Request $request, Vendor $vendor): mixed
    {
        $this->ensureCanManageVendor($vendor);
        $document = $this->storeDocument($request, 'vendor', $vendor);

        return $this->sendResponse([
            'document' => $this->documentData($document),
        ], 'Vendor document uploaded successfully.', HTTP_CREATED);
    }

    public function vendorDownload(Vendor $vendor, ComplianceDocument $document)
    {
        $this->ensureCanViewVendor($vendor);
        abort_unless($document->vendor_id === $vendor->id, HTTP_NOT_FOUND);

        return $this->download($document);
    }

    public function vendorUpdate(Request $request, Vendor $vendor, ComplianceDocument $document): mixed
    {
        $this->ensureCanManageVendor($vendor);
        abort_unless($document->vendor_id === $vendor->id, HTTP_NOT_FOUND);

        return $this->updateDocument($request, $document);
    }

    public function vendorDestroy(Vendor $vendor, ComplianceDocument $document): mixed
    {
        $this->ensureCanManageVendor($vendor);
        abort_unless($document->vendor_id === $vendor->id, HTTP_NOT_FOUND);
        $this->destroyDocument($document);

        return $this->sendResponse([], 'Vendor document deleted successfully.');
    }

    private function storeDocument(Request $request, string $scope, Business|Vendor $owner): ComplianceDocument
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:100'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'file' => [
                'required',
                'file',
                'mimes:' . implode(',', config('compliance-documents.upload.mimes', [])),
                'max:' . config('compliance-documents.upload.max_kilobytes', 10240),
            ],
        ]);
        $definition = $this->availableDocumentTypes($scope, $owner)
            ->firstWhere('key', $validated['document_type']);
        abort_unless($definition, HTTP_UNPROCESSABLE_ENTITY, 'This document type is not available for the selected business.');
        abort_if(
            $definition['requires_expiry_date'] && empty($validated['expires_at']),
            HTTP_UNPROCESSABLE_ENTITY,
            'An expiry date is required for this document type.'
        );

        $file = $request->file('file');
        $path = $file->store("compliance-documents/{$scope}/{$owner->uuid}", 'local');

        return ComplianceDocument::query()->create([
            $scope . '_id' => $owner->id,
            'compliance_document_type_id' => $definition['id'],
            // Keep immutable key/name snapshots so historical records remain
            // intelligible if Paperstick later renames or retires a type.
            'document_type' => $definition['key'],
            'document_type_name' => $definition['name'],
            'document_number' => $validated['document_number'] ?? null,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'storage_path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'status' => 'pending',
            'issued_at' => $validated['issued_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'uploaded_by' => $request->user()->id,
        ])->load('documentType');
    }

    private function documentList($documents, string $scope, Business|Vendor $owner): array
    {
        $definitions = $this->availableDocumentTypes($scope, $owner);
        $latestByType = $documents->groupBy('document_type')->map->first();
        $required = $definitions
            ->filter(fn (array $definition) => $definition['required_for_payment_activation'])
            ->pluck('key');
        $complete = $required->every(function (string $type) use ($latestByType) {
            $document = $latestByType->get($type);

            return $document
                && $document->status !== 'rejected'
                && !($document->expires_at && $document->expires_at->isPast());
        });

        return [
            'documents' => $documents->map(fn (ComplianceDocument $document) => $this->documentData($document))->values(),
            'requirements' => $definitions->map(function (array $definition) use ($latestByType) {
                $document = $latestByType->get($definition['key']);

                return [
                    'type' => $definition['key'],
                    'label' => $definition['name'],
                    'required_for_payment_activation' => $definition['required_for_payment_activation'],
                    'expires' => $definition['requires_expiry_date'],
                    'uploaded' => (bool) $document,
                    'expired' => (bool) ($document?->expires_at && $document->expires_at->isPast()),
                ];
            })->values(),
            'payment_activation' => [
                'documents_complete' => $complete,
                'missing_document_types' => $required
                    ->filter(fn (string $type) => !$latestByType->has($type))
                    ->values(),
            ],
            'can_upload' => $scope === 'vendor'
                ? $this->canManageVendor($owner)
                : $this->canManageBusiness($owner),
        ];
    }

    private function documentData(ComplianceDocument $document): array
    {
        return [
            'uuid' => $document->uuid,
            'type' => $document->document_type,
            'label' => $document->document_type_name ?? $document->documentType?->name ?? $document->document_type,
            'original_filename' => $document->original_filename,
            'document_number' => $document->document_number,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'status' => $document->status,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'uploaded_at' => $document->created_at?->toIso8601String(),
            // Approved documents are read-only to preserve the completed
            // review record. A future renewal is uploaded after expiry.
            'can_update' => $this->canModify($document),
            'can_delete' => $this->canModify($document),
        ];
    }

    private function updateDocument(Request $request, ComplianceDocument $document): mixed
    {
        abort_unless($this->canModify($document), HTTP_FORBIDDEN, 'Approved documents cannot be changed or deleted. Upload a renewal after expiry instead.');

        $validated = $request->validate([
            'document_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_at'],
            'file' => [
                'nullable',
                'file',
                'mimes:' . implode(',', config('compliance-documents.upload.mimes', [])),
                'max:' . config('compliance-documents.upload.max_kilobytes', 10240),
            ],
        ]);
        $type = $document->documentType;
        $expiresAt = array_key_exists('expires_at', $validated) ? $validated['expires_at'] : $document->expires_at?->toDateString();
        abort_if(
            $type?->requires_expiry_date && empty($expiresAt),
            HTTP_UNPROCESSABLE_ENTITY,
            'An expiry date is required for this document type.'
        );

        $updates = [
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ];
        foreach (['document_number', 'issued_at', 'expires_at'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        $oldPath = null;
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $scope = $document->business_id ? 'business' : 'vendor';
            $ownerUuid = $document->business?->uuid ?? $document->vendor?->uuid;
            $updates = array_merge($updates, [
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $file->store("compliance-documents/{$scope}/{$ownerUuid}", 'local'),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'file_size' => $file->getSize(),
            ]);
            $oldPath = $document->storage_path;
        }

        $document->update($updates);
        if ($oldPath) {
            Storage::disk($document->disk)->delete($oldPath);
        }

        return $this->sendResponse([
            'document' => $this->documentData($document->fresh()->load('documentType')),
        ], 'Document updated and returned for review.');
    }

    private function destroyDocument(ComplianceDocument $document): void
    {
        abort_unless($this->canModify($document), HTTP_FORBIDDEN, 'Approved documents cannot be changed or deleted.');
        Storage::disk($document->disk)->delete($document->storage_path);
        $document->delete();
    }

    private function canModify(ComplianceDocument $document): bool
    {
        return in_array($document->status, ['pending', 'rejected', 'expired'], true);
    }

    /**
     * Resolve active document types against the vendor country and, for a
     * business, its business type. The most specific matching rule wins.
     */
    private function availableDocumentTypes(string $scope, Business|Vendor $owner): Collection
    {
        if ($owner instanceof Business) {
            $owner->loadMissing('district.city');
            $countryId = $owner->district?->city?->country_id;
            $businessTypeId = $owner->business_type_id;
        } else {
            $countryId = $owner->country_id;
            $businessTypeId = null;
        }

        return ComplianceDocumentType::query()
            ->where('scope', $scope)
            ->where('is_active', true)
            ->with(['rules' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (ComplianceDocumentType $type) use ($countryId, $businessTypeId) {
                $rule = $type->rules
                    ->filter(function ($rule) use ($countryId, $businessTypeId) {
                        return ($rule->country_id === null || $rule->country_id === $countryId)
                            && ($rule->business_type_id === null || $rule->business_type_id === $businessTypeId);
                    })
                    ->sortByDesc(fn ($rule) => (int) ($rule->country_id !== null) + (int) ($rule->business_type_id !== null))
                    ->first();

                if (!$rule) {
                    return null;
                }

                return [
                    'id' => $type->id,
                    'key' => $type->key,
                    'name' => $type->name,
                    'requires_expiry_date' => $type->requires_expiry_date,
                    'reminder_days' => $type->reminder_days ?? [],
                    'required_for_payment_activation' => $rule->required_for_payment_activation,
                ];
            })
            ->filter()
            ->values();
    }

    private function download(ComplianceDocument $document)
    {
        abort_unless(Storage::disk($document->disk)->exists($document->storage_path), HTTP_NOT_FOUND, 'The document file is unavailable.');

        return Storage::disk($document->disk)->download($document->storage_path, $document->original_filename);
    }

    private function ensureCanViewVendor(Vendor $vendor): void
    {
        $membership = auth()->user()->vendors()->whereKey($vendor->id)->first();
        abort_unless($membership && ($membership->pivot->is_primary || $membership->pivot->is_active), HTTP_FORBIDDEN, 'You do not have access to this vendor.');
    }

    private function ensureCanManageVendor(Vendor $vendor): void
    {
        $this->ensureCanViewVendor($vendor);
        abort_unless($this->canManageVendor($vendor), HTTP_FORBIDDEN, 'You do not have permission to manage vendor documents.');
    }

    private function canManageVendor(?Vendor $vendor): bool
    {
        if (!$vendor || auth()->user()->type === 'business') {
            return false;
        }

        $membership = auth()->user()->vendors()->whereKey($vendor->id)->first();

        return (bool) ($membership?->pivot->is_primary
            || ($membership?->pivot->is_active
                && $membership?->pivot->role === 'manager'
                && $membership?->pivot->access_scope === 'all_businesses'));
    }

    private function ensureCanViewBusiness(Business $business): void
    {
        if (auth()->user()->type === 'business') {
            abort_unless(
                auth()->user()->businesses()->whereKey($business->id)->wherePivot('is_active', true)->exists(),
                HTTP_FORBIDDEN,
                'You do not have access to this business.'
            );

            return;
        }

        $membership = auth()->user()->vendors()->whereKey($business->vendor_id)->first();
        abort_unless($membership && ($membership->pivot->is_primary || $membership->pivot->is_active), HTTP_FORBIDDEN, 'You do not have access to this business.');

        if ($membership->pivot->is_primary || $membership->pivot->access_scope === 'all_businesses') {
            return;
        }

        abort_unless(
            auth()->user()->businesses()->whereKey($business->id)->wherePivot('is_active', true)->exists(),
            HTTP_FORBIDDEN,
            'You only have access to selected businesses.'
        );
    }

    private function ensureCanManageBusiness(Business $business): void
    {
        $this->ensureCanViewBusiness($business);
        abort_unless($this->canManageBusiness($business), HTTP_FORBIDDEN, 'You do not have permission to manage business documents.');
    }

    private function canManageBusiness(?Business $business): bool
    {
        if (!$business || auth()->user()->type === 'business') {
            return false;
        }

        $membership = auth()->user()->vendors()->whereKey($business->vendor_id)->first();
        if ($membership?->pivot->is_primary) {
            return true;
        }

        if (!$membership?->pivot->is_active || $membership?->pivot->role !== 'manager') {
            return false;
        }

        return $membership->pivot->access_scope === 'all_businesses'
            || auth()->user()->businesses()->whereKey($business->id)->wherePivot('is_active', true)->exists();
    }
}
