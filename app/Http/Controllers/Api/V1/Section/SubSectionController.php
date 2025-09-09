<?php

namespace App\Http\Controllers\Api\V1\Section;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Repositories\Section\SubSectionRepository;
use Illuminate\Http\Request;

class SubSectionController extends BaseController
{
    protected SubSectionRepository $subSections;

    public function __construct(SubSectionRepository $subSections)
    {
        $this->subSections = $subSections;
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
