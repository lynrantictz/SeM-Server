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
    public function index()
    {
        $data['vendors'] = $this->vendors->getAllAccess()->paginate(10);
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
        $data['vendor'] = $vendor->load('country', 'businesses');
        return $this->sendResponse($data, 'Vendor retrieved successfully.', HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(VendorStoreRequest $request, Vendor $vendor)
    {
        $this->vendors->update($vendor, $request->all());
        $data['vendor'] = $vendor->load('country', 'businesses');
        return $this->sendResponse($data, 'Vendor updated successfully.', HTTP_OK);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
