<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;
use Modules\User\Http\Controllers\AuthController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('users', UserController::class)->names('user');
});

Route::prefix('user')->group(function () {
    Route::post('register', [UserController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function () {
    Route::post('update', [UserController::class, 'update']);
    Route::post('delete', [UserController::class, 'destroy']);
    });

});
