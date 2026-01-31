<?php

use App\Http\Controllers\Api\V1\Location\CountryController;
use App\Http\Controllers\Api\V1\Location\CityController;
use App\Http\Controllers\Api\V1\Location\DistrictController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'countries'], function () {
    Route::get('', [CountryController::class, 'getAll']);
    Route::get('{id}', [CountryController::class, 'getById']);
});

Route::group(['prefix' => 'cities'], function () {
    Route::get('', [CityController::class, 'getAll']);
    Route::get('{id}', [CityController::class, 'getById']);
});

Route::group(['prefix' => 'districts'], function () {
    Route::get('', [DistrictController::class, 'getAll']);
    Route::get('{id}', [DistrictController::class, 'getById']);
});
