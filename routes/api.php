<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CoreApplication\EnterpriseDetailController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Department\DepartmentController;
use App\Http\Controllers\CoreApplication\CommonApplicationForm\UnitDetailsController;


Route::prefix('user')->group(function () {
    Route::post('register', [UserController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:api')->group(function () {

    Route::prefix('user')->group(function () {
        Route::post('profile-update', [UserController::class, 'update_profile']);
        Route::post('profile-delete', [UserController::class, 'delete_profile']);
        Route::post('get-profile', [UserController::class, 'get_profile']);

        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'change_password']);
    });

    Route::post('unit-details', [UnitDetailsController::class, 'unit_details_store_or_update']);
    Route::post('unit-details-view', [UnitDetailsController::class, 'unit_details_view']);


    Route::post('auth-get-all-roles', [RoleController::class, 'all_roles'])->name('roles.all_roles');
    Route::post('auth-store-role', [RoleController::class, 'store_role'])->name('roles.store_role');
    Route::post('auth-show-role', [RoleController::class, 'show_role'])->name('roles.show_role');
    Route::post('auth-update-role', [RoleController::class, 'update_role'])->name('roles.update_role');
    Route::post('auth-destroy-role', [RoleController::class, 'destroy_role'])->name('roles.destroy_role');



    Route::post('department-get-all-departments', [DepartmentController::class, 'all_departments'])->name('department.all_departments');
    Route::post('department-store-department', [DepartmentController::class, 'store_department'])->name('department.store_department');
    Route::post('department-show-department', [DepartmentController::class, 'show_department'])->name('department.show_department');
    Route::post('department-update-department', [DepartmentController::class, 'update_department'])->name('department.update_department');
    Route::post('department-destroy-department', [DepartmentController::class, 'destroy_department'])->name('department.destroy_department');



    Route::post('core-application-store-enterprise-detail', [EnterpriseDetailController::class, 'enterprise_details_store_or_update'])->name('core_application.store_enterprise_detail');
    Route::post('core-application-show-enterprise-detail', [EnterpriseDetailController::class, 'show_enterprise_details'])->name('core_application.show_enterprise_detail');
});
