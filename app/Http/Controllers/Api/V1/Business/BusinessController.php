<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Api\V1\Business\Concerns\AuthorizesVendorDirectories;
use App\Http\Requests\Api\V1\Business\BusinessRequest;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Models\SystemSetting;
use App\Repositories\Business\BusinessRepository;
use App\Services\Business\BusinessActivationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BusinessController extends BaseController
{
    use AuthorizesVendorDirectories;

    protected BusinessRepository $businesses;

    public function __construct(
        BusinessRepository $businesses,
        private readonly BusinessActivationService $activation,
    ) {
        $this->businesses = $businesses;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->ensureCanAccessVendorDirectories();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $paginator = $this->businesses
            ->getQuery($validated)
            ->orderByDesc('businesses.created_at')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();
        $paginator->getCollection()->each(
            fn (Business $business) => $business->setAttribute('activation', $this->activation->status($business))
        );

        return $this->sendResponse([
            'businesses' => $this->businessPaginatorData($paginator),
        ], 'Businesses retrieved successfully.');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BusinessRequest $request, Vendor $vendor)
    {
        $this->canManageBusiness($vendor, null, true);
        $data['business'] = $this->businesses->store($vendor, $request->validated());

        return $this->sendResponse(
            $data,
            'Business created successfully.',
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Business $business)
    {
        abort_unless(auth()->user()->is_active, HTTP_FORBIDDEN, 'Your account is disabled.');
        $business = $this->businesses
            ->getQuery()
            ->whereKey($business->id)
            ->firstOrFail();
        $business->setAttribute('profile_permissions', app(\App\Services\Business\BusinessProfileAccess::class)->permissions($business));
        $business->setAttribute('owner_summary', app(\App\Services\VendorOwnerSummary::class)->forVendor($business->vendor));
        $business->setAttribute('activation', $this->activation->status($business));
        $business->setAttribute(
            'platform_commission_rate',
            app(\App\Services\Business\BusinessCommissionService::class)->rate($business),
        );

        if ($business->paymentSetting) $business->paymentSetting->setAttribute('commission_rate', (string) app(\App\Services\Business\BusinessCommissionService::class)->rate($business));

        return $this->sendResponse([
            'business' => $business,
        ], 'Business retrieved successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BusinessRequest $request, Business $business)
    {
        $this->canManageBusiness($business->vendor, $business);
        if (auth()->user()->type === 'business') {
            $protectedFields = ['vendor_id', 'is_active', 'tax_status', 'tax_allowed', 'order_prefix', 'code_prefix', 'commission_rate', 'payment_setting'];
            abort_if($request->hasAny($protectedFields), HTTP_FORBIDDEN, 'Registration, activation and payment configuration cannot be changed through the business profile.');
            foreach (['name', 'tin', 'business_type_id'] as $field) {
                abort_if((string) $request->validated($field) !== (string) $business->{$field}, HTTP_FORBIDDEN, 'Only the vendor owner or vendor manager can change registered business details.');
            }
        }
        $this->businesses->update($business, $request->validated());
        $data['business'] = $business->fresh()->load([
            'contacts',
            'district.city.country',
            'type',
        ]);

        return $this->sendResponse(
            $data,
            'Business updated successfully.',
        );
    }

    public function logo(Business $business)
    {
        $this->businesses->getQuery()->whereKey($business->id)->firstOrFail();

        return $this->logoResponse($business);
    }

    /** Public business identity images are safe to show in the app and menu pages. */
    public function publicLogo(Business $business)
    {
        return $this->logoResponse($business);
    }

    private function logoResponse(Business $business)
    {
        $business->refresh();
        [$disk, $path, $mimeType] = [$business->logo_disk, $business->logo_path, $business->logo_mime_type];

        abort_unless($path && $disk, HTTP_NOT_FOUND, 'This business has not uploaded a logo.');
        abort_unless(Storage::disk($disk)->exists($path), HTTP_NOT_FOUND, 'The business logo file is unavailable.');

        return Storage::disk($disk)->response(
            $path,
            null,
            ['Content-Type' => $mimeType ?? 'image/png']
        );
    }

    public function updateLogo(Request $request, Business $business)
    {
        $this->canManageBusiness($business->vendor, $business);
        $validated = $request->validate([
            'logo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:width=512,height=512',
            ],
        ]);

        $file = $validated['logo'];
        $oldDisk = $business->logo_disk;
        $oldPath = $business->logo_path;
        $path = $file->store("business-logos/{$business->uuid}", 'public');
        $updatedBusiness = DB::transaction(function () use ($business, $file, $path) {
            $business->update([
                'logo_disk' => 'public',
                'logo_path' => $path,
                'logo_mime_type' => $file->getMimeType() ?: 'image/png',
                'logo_updated_at' => now(),
            ]);

            return $business->fresh();
        });

        if ($oldDisk && $oldPath) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $this->sendResponse([
            'business' => $updatedBusiness,
        ], 'Business logo updated successfully.');
    }

    public function updateDiscoveryProfile(Request $request, Business $business)
    {
        $this->canManageBusiness($business->vendor, $business);
        $validated = $request->validate([
            'discovery_description' => ['present', 'nullable', 'string', 'max:2000'],
        ]);
        $description = trim($validated['discovery_description'] ?? '');
        $business->update(['discovery_description' => $description === '' ? null : $description]);

        return $this->sendResponse(['business' => $business->fresh()], 'Discovery description updated successfully.');
    }

    public function updateDiscoveryImage(Request $request, Business $business)
    {
        $this->canManageBusiness($business->vendor, $business);
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=600,min_height=300,max_width=8000,max_height=8000'],
        ]);

        $disk = Storage::disk('public');
        $directory = "business-discovery/{$business->uuid}";
        $path = $validated['image']->store($directory, 'public');
        abort_unless($path, 500, 'The discovery image could not be stored.');
        $url = $disk->url($path);
        try {
            $oldUrl = DB::transaction(function () use ($business, $url) {
                $lockedBusiness = Business::query()->lockForUpdate()->findOrFail($business->id);
                $oldUrl = $lockedBusiness->image_url;
                $lockedBusiness->update(['image_url' => $url]);

                return $oldUrl;
            });
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        // Only remove our own previous upload, never external or unrelated images.
        $prefix = $disk->url($directory).'/';
        if ($oldUrl && str_starts_with($oldUrl, $prefix)) {
            $filename = substr($oldUrl, strlen($prefix));
            if (preg_match('/^[a-zA-Z0-9]+\.(jpg|jpeg|png|webp)$/', $filename)) {
                $disk->delete($directory.'/'.$filename);
            }
        }

        return $this->sendResponse(['business' => $business->fresh()], 'Discovery image updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    private function canManageBusiness(Vendor $vendor, ?Business $business = null, bool $creating = false): void
    {
        if (auth()->user()->type === 'business') {
            abort_if($creating || !$business, HTTP_FORBIDDEN, 'Staff accounts cannot register businesses.');
        }
        if ($business) {
            abort_unless(app(\App\Services\Business\BusinessProfileAccess::class)->permissions($business)['can_update_profile'], HTTP_FORBIDDEN, 'You do not have permission to update this business.');
            return;
        }
        $membership = auth()->user()->vendors()->whereKey($vendor->id)->first();
        abort_unless(auth()->user()->is_active && $membership, HTTP_FORBIDDEN, 'You do not have access to this vendor.');
        if ($membership->pivot->is_primary) return;
        abort_unless($membership->pivot->is_active && $membership->pivot->role === 'manager' && $membership->pivot->access_scope === 'all_businesses', HTTP_FORBIDDEN, 'A vendor-wide management assignment is required to register a business.');
    }

    private function businessPaginatorData($paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }
}
