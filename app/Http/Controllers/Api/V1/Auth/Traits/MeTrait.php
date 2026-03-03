<?php

namespace App\Http\Controllers\Api\V1\Auth\Traits;

use Illuminate\Http\JsonResponse;

trait MeTrait
{
    public function owner($request)
    {
        return $request->user()->load(['vendors.businesses','roles', 'roles.permissions']);
    }
}
