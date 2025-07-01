<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\User\AuthController;
use App\Http\Controllers\Auth\RoleController;


Route::prefix('user')->group(function () {
    Route::post('register', [UserController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function () {
    Route::post('profile-update', [UserController::class, 'update_profile']);
    Route::post('profile-delete', [UserController::class, 'delete_profile']);
    });

});

Route::post('auth-get-all-roles', [RoleController::class, 'all_roles'])->name('roles.all_roles');
Route::post('auth-store-role', [RoleController::class, 'store_role'])->name('roles.store_role');
Route::post('auth-show-role', [RoleController::class, 'show_role'])->name('roles.show_role');
Route::post('auth-update-role', [RoleController::class, 'update_role'])->name('roles.update_role');
Route::post('auth-destroy-role', [RoleController::class, 'destroy_role'])->name('roles.destroy_role');

