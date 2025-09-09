<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Repositories\Payment\PaymentStatusRepository;
use Illuminate\Http\Request;

class PaymentStatusController extends BaseController
{
    protected PaymentStatusRepository $paymentStatuses;

    public function __construct(PaymentStatusRepository $paymentStatuses)
    {
        $this->paymentStatuses = $paymentStatuses;
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
    public function store(Request $request)
    {
        //
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
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
