<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ApplicationApiController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('api.token')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/applications', [ApplicationApiController::class, 'index']);
    Route::get('/applications/{application}', [ApplicationApiController::class, 'show']);
});
