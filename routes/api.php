<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ResourceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::apiResource('resources', ResourceController::class);
    Route::apiResource('bookings', BookingController::class);
    Route::put('/users/{user}', [AuthController::class, 'update']);
    Route::get('/users', [AuthController::class, 'index']);
    Route::delete('/users/{user}', [AuthController::class, 'destroy']);
});
