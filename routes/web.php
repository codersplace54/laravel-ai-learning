<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ImportController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('import-society-members', [ImportController::class, 'import_society_members_form'])->name('admin.import.society_members_form');

    Route::post('import-society-members', [ImportController::class, 'import_society_members'])->name('import.society_members');

    Route::get('import-society-applications', [ImportController::class, 'import_society_applications_form'])->name('import.society.form');
    Route::post('import-society-applications', [ImportController::class, 'import_society_applications'])->name('import.society_applications');

    Route::get('import-service-applications', [ImportController::class, 'import_service_application_form'])->name('import.applications.form');
    Route::post('import-service-applications', [ImportController::class, 'import_service_applications'])->name('import.service_applications');

    Route::get('import-users', [ImportController::class, 'import_users_form'])->name('import.users.form');
    Route::post('import-users', [ImportController::class, 'import_users'])->name('import.users');

    Route::get('import-partnership-registration', [ImportController::class, 'import_partnership_registration_form'])->name('import.partnership_registration.form');
    Route::post('import-partnership-registration', [ImportController::class, 'import_partnership_registration'])->name('import.partnership_registration');

    Route::get('import-partnership-partners', [ImportController::class, 'import_partnership_partners_form'])->name('import.partnership_partners.form');
    Route::post('import-partnership-partners', [ImportController::class, 'import_partnership_partners'])->name('import.partnership_partners');
});
