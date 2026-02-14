<?php

use App\Http\Controllers\Api\V1\Business\VendorController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'vendors'], function () {
    Route::post('', [VendorController::class, 'store']);
});
