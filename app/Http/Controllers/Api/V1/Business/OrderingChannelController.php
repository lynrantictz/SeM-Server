<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\OrderingChannel;

class OrderingChannelController extends BaseController
{
    public function index()
    {
        return $this->sendResponse([
            'ordering_channels' => OrderingChannel::query()
                ->where('is_active', true)
                ->select(['id', 'uuid', 'slug', 'name', 'description'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ], 'Ordering channels retrieved successfully.');
    }
}
