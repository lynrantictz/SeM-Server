<?php

use App\Http\Controllers\Api\Location\CountryController;
use App\Http\Controllers\Api\Location\CityController;
use App\Http\Controllers\Api\Location\DistrictController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'orders'], function () {
   Route::post('', [OrderController::class, 'store']);
   Route::get('{number}', [OrderController::class, 'show']);
   Route::post('{order}/verify-phone', [OrderController::class, 'verifyPhone']);
});
