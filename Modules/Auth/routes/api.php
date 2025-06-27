<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\app\Http\Controllers\RoleController;

Route::post('auth-get-all-roles', [RoleController::class, 'all_roles'])->name('roles.all_roles');
Route::post('auth-store-role', [RoleController::class, 'store_role'])->name('roles.store_role');
Route::post('auth-show-role', [RoleController::class, 'show_role'])->name('roles.show_role');
Route::post('auth-update-role', [RoleController::class, 'update_role'])->name('roles.update_role');
Route::post('auth-destroy-role', [RoleController::class, 'destroy_role'])->name('roles.destroy_role');

