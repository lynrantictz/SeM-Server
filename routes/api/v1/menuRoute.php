<?php

use App\Http\Controllers\Api\V1\Menu\CategoryController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'menu'], function () {
    Route::get('', [CategoryController::class, 'getMenu']);
});
