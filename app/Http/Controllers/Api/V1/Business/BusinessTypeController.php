<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Repositories\Business\BusinessTypeRepository;

class BusinessTypeController extends BaseController
{
    protected BusinessTypeRepository $businessTypes;

    public function __construct(BusinessTypeRepository $businessTypes)
    {
        $this->businessTypes = $businessTypes;
    }

    public function index()
    {
        $response['business_types'] = $this->businessTypes
            ->query()
            ->select(['id', 'name', 'description'])
            ->orderBy('name')
            ->get();

        return $this->sendResponse($response, 'Business types retrieved successfully', HTTP_OK);
    }
}
