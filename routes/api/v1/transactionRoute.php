<?php

use App\Http\Controllers\Api\Location\CountryController;
use App\Http\Controllers\Api\Location\CityController;
use App\Http\Controllers\Api\Location\DistrictController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'transactions'], function () {
//    Route::get('', [CountryController::class, 'getAll']);
});

