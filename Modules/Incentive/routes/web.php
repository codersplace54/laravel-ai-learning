<?php

use Illuminate\Support\Facades\Route;
use Modules\Incentive\Http\Controllers\IncentiveController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('incentives', IncentiveController::class)->names('incentive');
});
