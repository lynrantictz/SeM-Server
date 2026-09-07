<?php

namespace App\Http\Controllers\Api\V1\Menu;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Section\Code;
use App\Repositories\Menu\CategoryRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CategoryController extends BaseController
{
    protected CategoryRepository $categories;

    public function __construct(CategoryRepository $categories)
    {
        $this->categories = $categories;
    }

    public function getMenu(Request $request)
    {
        $codeParam = $request->query('c');
        //check if code is valid
        $code = Code::query()->where('code', $codeParam)->first();
        // if not valid return error
        if (!$code) {
            return $this->sendError('Invalid code', [], HTTP_NOT_FOUND);
        }
        if (!$code->is_active) {
            return $this->sendError('This QR code is no longer active.', [], HTTP_NOT_FOUND);
        }

        $relationships = [
            'business',
            'business.type',
            'business.vendor',
            'business.district',
            'business.district.city',
            'business.district.city.country'
        ];

        if ($code->codable instanceof \App\Models\Section\ServicePoint) {
            array_unshift($relationships, 'section', 'subSection');
        } elseif ($code->codable instanceof \App\Models\Section\SubSection) {
            array_unshift($relationships, 'section');
        }

        $codable = $code->codable->load($relationships);
        if (!$codable->business->is_active) {
            return $this->sendError('This business is not currently accepting orders.', [], HTTP_NOT_FOUND);
        }
        $menu = $code->codable->business->categories()->with([
            'items' => function ($query) {
                $query->where('is_active', true);
            },
        ])
            ->where('is_active', true)
            ->orderBy('categories.name', 'ASC')
            ->get();

        $data['code'] = $codable;
        $data['menu'] = $menu;

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
