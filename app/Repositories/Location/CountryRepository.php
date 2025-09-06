<?php

namespace App\Repositories\Location;

use App\Models\Location\Country;
use App\Repositories\BaseRepository;

class CountryRepository extends BaseRepository
{
    const MODEL = Country::class;

    public function getAll()
    {
        return $this->query()->with(['cities', 'cities.districts']);
    }

    public function getById($id)
    {
//        $this->query()->with(['cities', 'districts'])
        return $this->query()->find($id);
    }
}
