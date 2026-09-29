<?php

use App\Http\Controllers\Api\V1\Inquiry\BusinessInquiryController;
use Illuminate\Support\Facades\Route;

Route::post('business-inquiries', [BusinessInquiryController::class, 'store'])->middleware('throttle:5,1');
