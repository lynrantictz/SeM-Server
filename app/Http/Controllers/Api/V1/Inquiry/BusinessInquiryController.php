<?php

namespace App\Http\Controllers\Api\V1\Inquiry;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Api\V1\Inquiry\StoreBusinessInquiryRequest;
use App\Models\BusinessInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BusinessInquiryController extends BaseController
{
    public function store(StoreBusinessInquiryRequest $request): JsonResponse
    {
        $inquiry = DB::transaction(function () use ($request): BusinessInquiry {
            return BusinessInquiry::query()->create([
                ...$request->safe()->only([
                    'package_code',
                    'package_name',
                    'name',
                    'business_name',
                    'email',
                    'phone',
                    'message',
                ]),
                'source' => 'public_pricing',
                'status' => 'new',
            ]);
        });

        return $this->sendResponse([
            'inquiry' => [
                'uuid' => $inquiry->uuid,
                'status' => $inquiry->status,
            ],
        ], 'Thanks — your request has been received. Paperstic will be in touch shortly.', HTTP_CREATED);
    }
}
