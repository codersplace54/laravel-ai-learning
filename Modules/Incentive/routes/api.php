<?php

use Illuminate\Support\Facades\Route;
use Modules\Incentive\Http\Controllers\IncentiveController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('incentives', IncentiveController::class)->names('incentive');
});
