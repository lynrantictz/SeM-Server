<?php

namespace App\Repositories\Location;

use App\Models\Location\District;
use App\Repositories\BaseRepository;

class DistrictRepository extends BaseRepository
{
    const MODEL = District::class;

    public function getAll()
    {
        return $this->query()->with(['city','city.country']);
    }

    public function getById($id)
    {
        return $this->query()->with(['city', 'city.country'])->find($id);
    }
}
