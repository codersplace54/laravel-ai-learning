<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Service\PaymentController;
use App\Http\Controllers\Admin\ApplicationDataCorrectionController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/user/payment-callback', [PaymentController::class, 'payment_callback']);

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

    Route::get('import-profession-tax', [ImportController::class, 'import_profession_tax_form'])->name('import.profession_tax.form');
    Route::post('import-profession-tax', [ImportController::class, 'import_profession_tax'])->name('import.profession_tax');

    Route::get('import-profession-tax-questions', [ImportController::class, 'import_profession_tax_questions_form'])->name('import.profession_tax_questions.form');
    Route::post('import-profession-tax-questions', [ImportController::class, 'import_profession_tax_questions'])->name('import.profession_tax_questions');

    Route::get('import-profession-tax-certificate', [ImportController::class, 'import_profession_tax_certificate_form'])->name('import.profession_tax_certificate.form');
    Route::post('import-profession-tax-certificate', [ImportController::class, 'import_profession_tax_certificate'])->name('import.profession_tax_certificate');

    Route::get('application-data-correction', [ApplicationDataCorrectionController::class, 'correction_form'])->name('correction.form');
    Route::post('update-partnership-application-data', [ApplicationDataCorrectionController::class, 'update_partnership_application_data'])->name('correction.partnership_application');
    Route::post('update-partnership-partner-data', [ApplicationDataCorrectionController::class, 'update_partnership_partner_data'])->name('correction.partnership_partner');
    Route::post('update-partnership-application-noc-certificate', [ApplicationDataCorrectionController::class, 'update_partnership_application_noc_certificate'])->name('correction.partnership_application_noc_certificate');
    Route::post('correct-all-file-paths', [ApplicationDataCorrectionController::class, 'correct_all_file_paths'])->name('correction.all_file_paths');
    Route::post('normalize-to-relative-paths', [ApplicationDataCorrectionController::class, 'normalize_to_relative_paths'])->name('correction.normalize_paths');
    Route::post('fix-partner-dates', [ApplicationDataCorrectionController::class, 'fix_partner_dates'])->name('correction.fix_partner_dates');
});
