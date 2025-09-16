<?php

use App\Http\Controllers\Api\Location\CountryController;
use App\Http\Controllers\Api\Location\CityController;
use App\Http\Controllers\Api\Location\DistrictController;
use App\Http\Controllers\Api\V1\Menu\CategoryController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'menu'], function () {
    Route::get('{code}', [CategoryController::class, 'getMenu']);
});

