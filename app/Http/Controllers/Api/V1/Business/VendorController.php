<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Busines\VendorStoreRequest;
use App\Models\Business\Vendor;
use App\Repositories\Business\VendorRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VendorController extends BaseController
{
    protected VendorRepository $vendors;

    public function __construct(VendorRepository $vendors)
    {
        $this->vendors = $vendors;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $perPage = $validated['per_page'] ?? 10;
        $paginator = $this->vendors
            ->getAllAccess($validated)
            ->orderByDesc('vendors.created_at')
            ->paginate($perPage)
            ->withQueryString();

        $data['vendors'] = [
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

        return $this->sendResponse($data, 'Vendor Lists');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(VendorStoreRequest $request)
    {
        $data['vendor'] = $this->vendors->store($request->all());
        return $this->sendResponse($data, 'Vendor created successfully.', HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Vendor $vendor)
    {
        $this->vendorMembership($vendor);
        $data['vendor'] = $vendor->load('country');
        return $this->sendResponse($data, 'Vendor retrieved successfully.', HTTP_OK);
    }

    public function businesses(Request $request, Vendor $vendor)
    {
        $membership = $this->vendorMembership($vendor);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $query = $this->vendors
            ->getBusinessesQuery($vendor, $validated)
            ->orderByDesc('businesses.created_at');

        if (!$membership->pivot->is_primary && $membership->pivot->access_scope === 'selected_businesses') {
            $query->whereIn('businesses.id', auth()->user()->businesses()->where('vendor_id', $vendor->id)->pluck('businesses.id'));
        }

        $paginator = $query
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        $data['businesses'] = [
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

        return $this->sendResponse($data, 'Vendor businesses retrieved successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(VendorStoreRequest $request, Vendor $vendor)
    {
        $this->vendorMembership($vendor, true, true);
        $this->vendors->update($vendor, $request->all());
        $data['vendor'] = $vendor->load('country');
        return $this->sendResponse($data, 'Vendor updated successfully.', HTTP_OK);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    private function vendorMembership(Vendor $vendor, bool $requiresManager = false, bool $requiresAllBusinesses = false)
    {
        $membership = auth()->user()->vendors()->whereKey($vendor->id)->first();
        abort_unless($membership, HTTP_FORBIDDEN, 'You do not have access to this vendor.');

        $isOwner = (bool) $membership->pivot->is_primary;
        $isManager = $membership->pivot->role === 'manager' && (bool) $membership->pivot->is_active;
        abort_unless($isOwner || $membership->pivot->is_active, HTTP_FORBIDDEN, 'Your access to this vendor is not active.');
        abort_if($requiresManager && !$isOwner && !$isManager, HTTP_FORBIDDEN, 'You do not have permission to manage this vendor.');
        abort_if($requiresAllBusinesses && !$isOwner && $membership->pivot->access_scope !== 'all_businesses', HTTP_FORBIDDEN, 'You need access to all businesses to perform this action.');

        return $membership;
    }
}
