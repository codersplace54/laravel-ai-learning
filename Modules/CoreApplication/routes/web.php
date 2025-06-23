<?php

use Illuminate\Support\Facades\Route;
use Modules\CoreApplication\Http\Controllers\CoreApplicationController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('coreapplications', CoreApplicationController::class)->names('coreapplication');
});
