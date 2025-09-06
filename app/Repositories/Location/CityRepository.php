<?php

namespace App\Repositories\Location;

use App\Models\Location\City;
use App\Repositories\BaseRepository;

class CityRepository extends BaseRepository
{
    const MODEL = City::class;

    public function getAll($inputs)
    {
        if (isset($inputs['ids'])) {
            $ids = array_unique(array_map('intval', explode(',', $inputs['ids'])));
            return $this->query()->whereIn('id', $ids);
        }
        return $this->query()->with(['country','districts']);
    }

    public function getById($id)
    {
        return $this->query()->with(['country','districts'])->find($id);
    }
}
