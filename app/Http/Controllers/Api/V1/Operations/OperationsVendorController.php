<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsVendorController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'in:10,25,50,75,100,all'],
        ]);

        $query = Vendor::query()
            ->with('country:id,name,iso2')
            ->withCount(['businesses', 'users', 'complianceDocuments'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $term = '%' . trim($search) . '%';
                $query->where(fn ($builder) => $builder
                    ->where('name', 'ilike', $term)
                    ->orWhere('tin', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('phone', 'ilike', $term));
            })
            ->orderBy('name');

        if (($filters['per_page'] ?? '10') === 'all') {
            $vendors = $query->get();
            return $this->sendResponse([
                'vendors' => [
                    'data' => $vendors->map(fn (Vendor $vendor) => $this->vendorData($vendor)),
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => $vendors->count(),
                ],
            ], 'Operations vendors retrieved successfully.');
        }

        $vendors = $query->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();

        return $this->sendResponse([
            'vendors' => $vendors->through(fn (Vendor $vendor) => $this->vendorData($vendor)),
        ], 'Operations vendors retrieved successfully.');
    }

    public function show(string $uuid): JsonResponse
    {
        $vendor = Vendor::query()
            ->with([
                'country:id,name,iso2',
                'businesses:id,uuid,vendor_id,name,tin,location,is_active,business_type_id,district_id,created_at',
                'businesses.type:id,name',
                'businesses.district:id,name,city_id',
                'businesses.district.city:id,name,country_id',
                'users:id,name,email,phone,is_active,type',
                'complianceDocuments',
            ])
            ->withCount(['businesses', 'users', 'complianceDocuments'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return $this->sendResponse([
            'vendor' => $this->vendorData($vendor),
            'businesses' => $vendor->businesses->map(fn ($business) => [
                'uuid' => $business->uuid,
                'name' => $business->name,
                'tin' => $business->tin,
                'type' => $business->type?->name,
                'location' => $business->location,
                'city' => $business->district?->city?->name,
                'district' => $business->district?->name,
                'is_active' => (bool) $business->is_active,
                'created_at' => $business->created_at?->toIso8601String(),
            ])->values(),
            'team' => $vendor->users->map(fn ($user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'type' => $user->type,
                'is_active' => (bool) $user->is_active,
                'membership' => [
                    'is_primary' => (bool) $user->pivot->is_primary,
                    'role' => $user->pivot->role,
                    'access_scope' => $user->pivot->access_scope,
                    'is_active' => (bool) $user->pivot->is_active,
                ],
            ])->values(),
            'documents' => $vendor->complianceDocuments->map(fn ($document) => [
                'uuid' => $document->uuid,
                'type' => $document->document_type,
                'label' => $document->document_type_name ?? $document->document_type,
                'filename' => $document->original_filename,
                'status' => $document->status,
                'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            ])->values(),
        ], 'Operations vendor details retrieved successfully.');
    }

    private function vendorData(Vendor $vendor): array
    {
        return [
            'uuid' => $vendor->uuid,
            'name' => $vendor->name,
            'tin' => $vendor->tin,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'address' => $vendor->address,
            'country' => $vendor->country?->name,
            'country_iso2' => $vendor->country?->iso2,
            'business_count' => (int) ($vendor->businesses_count ?? 0),
            'team_count' => (int) ($vendor->users_count ?? 0),
            'document_count' => (int) ($vendor->compliance_documents_count ?? 0),
            'created_at' => $vendor->created_at?->toIso8601String(),
        ];
    }
}
