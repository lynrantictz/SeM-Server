<?php

namespace App\Repositories;

class BaseRepository
{
    /**
     * @return mixed
     */
    public function query()
    {
        return call_user_func(static::MODEL.'::query');
    }

    public function all()
    {
        return call_user_func(static::MODEL.'::all');
    }
}
