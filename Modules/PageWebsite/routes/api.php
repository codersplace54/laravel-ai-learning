<?php

use Illuminate\Support\Facades\Route;
use Modules\PageWebsite\Http\Controllers\PageWebsiteController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('pagewebsites', PageWebsiteController::class)->names('pagewebsite');
});
