<?php

use App\Http\Controllers\Api\V1\Section\CodeController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'sections'], function () {
//    Route::get('', [CountryController::class, 'getAll']);
});

Route::group(['prefix' => 'codes'], function () {
    Route::get('{code}/generateQrCode', [CodeController::class, 'generateQrCode']);
});

