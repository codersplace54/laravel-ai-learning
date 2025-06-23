<?php

use Illuminate\Support\Facades\Route;
use Modules\PageWebsite\Http\Controllers\PageWebsiteController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('pagewebsites', PageWebsiteController::class)->names('pagewebsite');
});
