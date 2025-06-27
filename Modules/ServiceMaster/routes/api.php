<?php

use Illuminate\Support\Facades\Route;
use Modules\ServiceMaster\Http\Controllers\ServiceMasterController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('servicemasters', ServiceMasterController::class)->names('servicemaster');
});
