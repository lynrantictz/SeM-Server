<?php

use App\Http\Controllers\Api\Location\CountryController;
use App\Http\Controllers\Api\Location\CityController;
use App\Http\Controllers\Api\Location\DistrictController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('order-history/verification', [OrderController::class, 'sendOrderHistoryVerification'])->middleware('throttle:3,1');
Route::post('order-history/verification/confirm', [OrderController::class, 'verifyOrderHistoryVerification'])->middleware('throttle:5,1');
Route::get('phone/{phone}/verify', [OrderController::class, 'getOrdersByPhone']);

Route::group(['prefix' => 'orders'], function () {
   Route::post('', [OrderController::class, 'store'])->middleware('throttle:5,1');
   Route::post('active', [OrderController::class, 'activeGuestOrders'])->middleware('throttle:30,1');
   Route::get('checkout-verifications/{uuid}', [OrderController::class, 'resumeCheckoutVerification'])->middleware('throttle:10,1');
   Route::post('checkout-verifications/{uuid}/confirm', [OrderController::class, 'confirmCheckoutVerification'])->middleware('throttle:10,1');
   Route::post('checkout-verifications/{uuid}/resend', [OrderController::class, 'resendCheckoutVerification'])->middleware('throttle:3,1');
   Route::get('{number}', [OrderController::class, 'show']);
   Route::put('{order}/verify-phone', [OrderController::class, 'verifyPhone']);
   Route::post('{order}/resend-phone-verification-code', [OrderController::class, 'resendPhoneVerificationCode']);
   Route::put('{order}/change-phone', [OrderController::class, 'changePhone']);
   Route::put('{order}/rating', [OrderController::class, 'rating']);
   Route::post('{order}/feedback', [OrderController::class, 'feedback'])->middleware('throttle:5,1');
});
