<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Models\Location\Country;
use App\Services\Business\BusinessActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OperationsBusinessController extends BaseController
{
    public function __construct(private readonly BusinessActivationService $activation)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,active,inactive'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $businesses = Business::query()
            ->with(['vendor:id,name', 'type:id,name', 'district:id,name,city_id', 'district.city:id,name,country_id', 'district.city.country:id,name,iso2'])
            ->withCount('complianceDocuments')
            ->withCount(['complianceDocuments as pending_documents_count' => fn ($query) => $query->whereIn('status', ['pending', 'in_review', 'needs_review'])])
            ->when(!empty($filters['search']), function ($query) use ($filters): void {
                $term = '%' . trim($filters['search']) . '%';
                $query->where(function ($search) use ($term): void {
                    $search->where('name', 'ilike', $term)
                        ->orWhere('tin', 'ilike', $term)
                        ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'ilike', $term));
                });
            })
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($query) => $query->where('is_active', ($filters['status'] ?? null) === 'active'))
            ->when(!empty($filters['country_id']), fn ($query) => $query->whereHas('district.city', fn ($city) => $city->where('country_id', $filters['country_id'])))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return $this->sendResponse([
            'businesses' => $businesses->through(fn (Business $business) => $this->businessData($business)),
            'countries' => Country::query()->select(['id', 'name', 'iso2'])->orderBy('name')->get(),
        ], 'Operations businesses retrieved successfully.');
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $business = Business::query()
            ->with(['vendor:id,name', 'type:id,name', 'district:id,name,city_id', 'district.city:id,name,country_id', 'district.city.country:id,name,iso2', 'contacts:id,business_id,contact,is_active'])
            ->withCount(['complianceDocuments as pending_documents_count' => fn ($query) => $query->whereIn('status', ['pending', 'in_review', 'needs_review'])])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $documents = $request->user()->can('operations.kyc.view')
            ? $business->complianceDocuments()->latest()->get()->map(fn (ComplianceDocument $document) => $this->documentData($document))
            : collect();
        $activation = $this->activation->status($business);

        return $this->sendResponse([
            'business' => $this->businessData($business),
            'documents' => $documents,
            'activation' => $activation,
        ], 'Operations business details retrieved successfully.');
    }

    public function documents(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,pending,in_review,approved,rejected,expired'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $documents = ComplianceDocument::query()
            ->whereNotNull('business_id')
            ->with(['business:id,uuid,name,vendor_id,district_id,is_active', 'business.vendor:id,name', 'business.district:id,name,city_id', 'business.district.city:id,name,country_id', 'business.district.city.country:id,name,iso2', 'uploader:id,name'])
            ->when(($filters['status'] ?? 'pending') !== 'all', fn ($query) => $query->where('status', $filters['status'] ?? 'pending'))
            ->when(!empty($filters['search']), function ($query) use ($filters): void {
                $term = '%' . trim($filters['search']) . '%';
                $query->where(function ($search) use ($term): void {
                    $search->where('document_type_name', 'ilike', $term)
                        ->orWhere('document_type', 'ilike', $term)
                        ->orWhere('original_filename', 'ilike', $term)
                        ->orWhereHas('business', fn ($business) => $business->where('name', 'ilike', $term));
                });
            })
            ->when(!empty($filters['country_id']), fn ($query) => $query->whereHas('business.district.city', fn ($city) => $city->where('country_id', $filters['country_id'])))
            ->orderByRaw("CASE WHEN status IN ('pending', 'in_review', 'needs_review') THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return $this->sendResponse([
            'documents' => $documents->through(fn (ComplianceDocument $document) => $this->documentData($document)),
        ], 'Business compliance documents retrieved successfully.');
    }

    public function download(string $uuid)
    {
        $document = ComplianceDocument::query()->where('uuid', $uuid)->whereNotNull('business_id')->firstOrFail();
        abort_unless(Storage::disk($document->disk)->exists($document->storage_path), 404, 'The document file is unavailable.');

        return Storage::disk($document->disk)->response($document->storage_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="' . addcslashes($document->original_filename, '"\\') . '"',
        ]);
    }

    public function review(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'rejection_reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:2000'],
        ]);

        $permission = $validated['decision'] === 'approved' ? 'operations.kyc.approve' : 'operations.kyc.reject';
        abort_unless($request->user()->can($permission), 403, 'You do not have permission to make this document decision.');

        $document = ComplianceDocument::query()
            ->where('uuid', $uuid)
            ->whereNotNull('business_id')
            ->firstOrFail();
        abort_unless(in_array($document->status, ['pending', 'in_review', 'needs_review'], true), 409, 'Only documents awaiting review can be decided.');

        $document->update([
            'status' => $validated['decision'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $validated['decision'] === 'rejected' ? trim($validated['rejection_reason']) : null,
        ]);

        return $this->sendResponse([
            'document' => $this->documentData($document->fresh()->load(['business:id,uuid,name', 'uploader:id,name'])),
        ], 'Compliance document decision recorded.');
    }

    private function businessData(Business $business): array
    {
        return [
            'uuid' => $business->uuid,
            'name' => $business->name,
            'vendor' => $business->vendor?->name,
            'business_type' => $business->type?->name,
            'tin' => $business->tin,
            'location' => $business->location,
            'district' => $business->district?->name,
            'city' => $business->district?->city?->name,
            'country' => $business->district?->city?->country?->name,
            'country_iso2' => $business->district?->city?->country?->iso2,
            'is_active' => (bool) $business->is_active,
            'created_at' => $business->created_at?->toIso8601String(),
            'document_count' => (int) ($business->compliance_documents_count ?? 0),
            'pending_documents_count' => (int) ($business->pending_documents_count ?? 0),
            'contacts' => $business->relationLoaded('contacts') ? $business->contacts->where('is_active', true)->pluck('contact')->values() : [],
        ];
    }

    private function documentData(ComplianceDocument $document): array
    {
        return [
            'uuid' => $document->uuid,
            'business' => $document->business ? [
                'uuid' => $document->business->uuid,
                'name' => $document->business->name,
                'vendor' => $document->business->vendor?->name,
                'country' => $document->business->district?->city?->country?->name,
            ] : null,
            'type' => $document->document_type,
            'label' => $document->document_type_name ?? $document->document_type,
            'document_number' => $document->document_number,
            'filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'file_size' => (int) $document->file_size,
            'status' => $document->status,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'uploaded_by' => $document->uploader?->name,
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'rejection_reason' => $document->rejection_reason,
        ];
    }
}
