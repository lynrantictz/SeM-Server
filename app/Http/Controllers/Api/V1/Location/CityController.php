<?php

namespace App\Http\Controllers\Api\V1\Location;

use App\Http\Controllers\Api\BaseController;
use App\Repositories\Location\CityRepository;
use Illuminate\Http\Request;

class CityController extends BaseController
{
    protected CityRepository $cities;

    public function __construct(CityRepository $cities)
    {
        $this->cities = $cities;
    }

    public function getAll(Request $request)
    {
        $response['cities'] = $this->cities->getAll($request->all())->get();
        return $this->sendResponse($response, 'Cities retrieved successfully');
    }

    public function getById($id)
    {
        $response['city'] = $this->cities->getById($id);
        return $this->sendResponse($response, 'City retrieved successfully');
    }
}
