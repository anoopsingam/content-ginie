<?php

use App\Http\Controllers\API\ProductContentController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::prefix('v1/product-content')->withoutMiddleware(VerifyCsrfToken::class)->group(function () {
    Route::post('/generate', [ProductContentController::class, 'generateContent']);
    Route::get('/status/{requestId}', [ProductContentController::class, 'getStatus']);
    Route::get('/content/{requestId}', [ProductContentController::class, 'getContent']);
});
