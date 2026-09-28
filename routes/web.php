<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Paperstic API',
        'status' => 'operational',
        'version' => 'v1',
    ]);
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});

Route::get('/mail', function () {
    return view('emails.verify-email');
});

Route::fallback(function () {
    return response()->json([
        'message' => 'Not Found.',
    ], 404);
});
