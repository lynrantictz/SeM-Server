<?php

use App\Http\Controllers\Api\Location\CountryController;
use App\Http\Controllers\Api\V1\Business\BusinessController;
use App\Http\Controllers\Api\V1\Discovery\DiscoveryController;
use App\Http\Controllers\Api\Location\CityController;
use App\Http\Controllers\Api\Location\DistrictController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'business'], function () {
//    Route::get('', [CountryController::class, 'getAll']);
});

Route::get('discover/businesses', [DiscoveryController::class, 'index']);
Route::get('public/businesses/{business}/logo', [BusinessController::class, 'publicLogo']);
