<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ImportController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('import-users', [ImportController::class, 'import_users_form'])->name('import.users.form');

    Route::post('import-users', [ImportController::class, 'import_users'])->name('import.users');
});