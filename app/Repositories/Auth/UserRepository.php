<?php

namespace App\Repositories\Auth;

use App\Models\User;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;

class UserRepository extends BaseRepository
{
    const MODEL = User::class;

    public function store(array $inputs)
    {
        return DB::transaction(function () use ($inputs) {
            return $this->query()->create($inputs);
        });
    }
}
