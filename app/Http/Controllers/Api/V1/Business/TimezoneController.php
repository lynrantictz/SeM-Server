<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\TimezoneGroup;

class TimezoneController extends BaseController
{
    public function index()
    {
        $groups = TimezoneGroup::query()
            ->where('is_active', true)
            ->with(['timezones' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order'])
            ->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'timezones' => $group->timezones->map(fn ($timezone) => [
                    'id' => $timezone->id,
                    'identifier' => $timezone->identifier,
                    'name' => $timezone->name,
                ])->values(),
            ])
            ->values();

        return $this->sendResponse(['groups' => $groups], 'Timezones retrieved successfully.');
    }
}
