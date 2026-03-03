<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\BusinessRequest;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Repositories\Business\BusinessRepository;
use Illuminate\Http\Request;

class BusinessController extends BaseController
{
    protected BusinessRepository $businesses;

    public function __construct(BusinessRepository $businesses)
    {
        $this->businesses = $businesses;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(BusinessRequest $request, Vendor $vendor)
    {
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
    public function show(string $id)
    {
        //
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
}
