<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Api\V1\Business\Concerns\AuthorizesVendorDirectories;
use App\Http\Requests\Api\V1\Business\BusinessRequest;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Repositories\Business\BusinessRepository;
use Illuminate\Http\Request;

class BusinessController extends BaseController
{
    use AuthorizesVendorDirectories;

    protected BusinessRepository $businesses;

    public function __construct(BusinessRepository $businesses)
    {
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
        $data['business'] = $this->businesses->store($vendor, $request->all());
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
        $business = $this->businesses
            ->getQuery()
            ->whereKey($business->id)
            ->firstOrFail();

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
        $this->businesses->update($business, $request->all());
        $data['business'] = $business->fresh()->load('contacts');
        return $this->sendResponse(
            $data,
            'Business updated successfully.',
        );
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
        $membership = auth()->user()->vendors()->whereKey($vendor->id)->first();
        abort_unless($membership, HTTP_FORBIDDEN, 'You do not have access to this vendor.');

        if ($membership->pivot->is_primary) {
            return;
        }

        abort_unless($membership->pivot->is_active && $membership->pivot->role === 'manager', HTTP_FORBIDDEN, 'You do not have permission to manage businesses.');

        if ($membership->pivot->access_scope === 'all_businesses') {
            return;
        }

        abort_if($creating || !$business || !auth()->user()->businesses()->whereKey($business->id)->where('vendor_id', $vendor->id)->exists(), HTTP_FORBIDDEN, 'You only have access to selected businesses.');
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
