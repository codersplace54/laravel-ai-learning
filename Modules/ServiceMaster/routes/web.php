<?php

use Illuminate\Support\Facades\Route;
use Modules\ServiceMaster\Http\Controllers\ServiceMasterController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('servicemasters', ServiceMasterController::class)->names('servicemaster');
});
