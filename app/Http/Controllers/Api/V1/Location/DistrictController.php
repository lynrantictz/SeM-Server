<?php

namespace App\Http\Controllers\Api\V1\Location;

use App\Http\Controllers\Api\BaseController;
use App\Repositories\Location\DistrictRepository;

class DistrictController extends BaseController
{
    protected DistrictRepository $districts;

    public function __construct(DistrictRepository $districts)
    {
        $this->districts = $districts;
    }

    public function getAll()
    {
        $response['districts'] = $this->districts->getAll()->get();
        return $this->sendResponse($response, 'Districts retrieved successfully');
    }

    public function getById($id)
    {
        $response['district'] = $this->districts->getById($id);
        return $this->sendResponse($response, 'District retrieved successfully');
    }
}
