<?php

use Illuminate\Support\Facades\Route;
use Modules\CoreApplication\Http\Controllers\CoreApplicationController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('coreapplications', CoreApplicationController::class)->names('coreapplication');
});
