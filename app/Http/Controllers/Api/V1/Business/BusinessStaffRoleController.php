<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\BusinessStaffRole;

class BusinessStaffRoleController extends BaseController
{
    public function index()
    {
        return $this->sendResponse([
            'business_staff_roles' => BusinessStaffRole::query()
                ->where('is_active', true)
                ->select(['id', 'uuid', 'name', 'slug', 'description'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ], 'Business staff roles retrieved successfully.');
    }
}
