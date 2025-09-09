<?php

namespace App\Http\Controllers\Api\V1\Location;

use App\Http\Controllers\Api\BaseController;
use App\Repositories\Location\CountryRepository;

class CountryController extends BaseController
{
    protected CountryRepository $countries;

    public function __construct(CountryRepository $countries)
    {
        $this->countries = $countries;
    }

    public function getAll()
    {
        $response['countries'] = $this->countries->getAll()->get();
        return $this->sendResponse($response, 'Countries retrieved successfully', HTTP_OK);
    }

    public function getById($id)
    {
        $response['country'] = $this->countries->getById($id);
        return $this->sendResponse($response, 'Country retrieved successfully', HTTP_OK);
    }
}
