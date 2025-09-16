<?php

namespace App\Http\Controllers\Api\V1\Menu;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Section\Code;
use App\Repositories\Menu\CategoryRepository;
use Illuminate\Http\Request;

class CategoryController extends BaseController
{
    protected CategoryRepository $categories;

    public function __construct(CategoryRepository $categories)
    {
        $this->categories = $categories;
    }

    public function getMenu($code)
    {
        //check if code is valid
        $code = Code::query()->where('code', $code)->first();
        // if not valid return error
        if(!$code){
            return $this->sendError('Invalid code', [], HTTP_NOT_FOUND);
        }
        $data['business'] = $code->codable;
        $data['categories'] = $code->codable->categories;
        // if code is active
        // if valid return menu

        return $this->sendResponse($data, 'Menu retrieved successfully', HTTP_OK);
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
