<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\CoreApplication\CommonApplicationForm\EnterpriseDetailController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Department\DepartmentController;
use App\Http\Controllers\CoreApplication\CommonApplicationForm\UnitDetailController;
use App\Http\Controllers\CoreApplication\CommonApplicationForm\ManagementDetailsController;
use App\Http\Controllers\CoreApplication\NicCode\NicCodeController;
use App\Http\Controllers\CoreApplication\CommonApplicationForm\LineOfActivity\LineOfActivityDetailsController;
use App\Http\Controllers\CoreApplication\CommonApplicationForm\GeneralAttachmentsController;
use App\Http\Controllers\CoreApplication\CommonApplicationForm\BankDetailController;
use App\Http\Controllers\CoreApplication\CommonApplicationForm\ActivityController;
use App\Http\Controllers\ServiceMaster\ServiceMasterController;
use App\Http\Controllers\Service\RenewalCycleController;
use App\Http\Controllers\Service\ServiceQuestionnaireController;
use App\Http\Controllers\Service\ServiceFeeRuleController;
use App\Http\Controllers\Service\RenewalFeeRuleController;
use App\Http\Controllers\Service\ServiceApprovalFlowController;
use App\Http\Controllers\Service\UserServiceApplicationController;
use App\Http\Controllers\Service\HolidayController;
use App\Http\Controllers\Service\ServiceController;
use App\Http\Controllers\Service\CertificateController;
use App\Http\Controllers\Subdivision\TripuraMasterDataController;
use App\Http\Controllers\Incentive\SchemeController;
use App\Http\Controllers\Incentive\ProformaController;
use App\Http\Controllers\Incentive\ProformaQuestionnaireController;
use App\Http\Controllers\Incentive\UserIncentiveApplicationController;
use App\Http\Controllers\SchemaController;
use App\Http\Middleware\JWTActivityMiddleware;
use App\Http\Controllers\Report\ReportController;
use App\Http\Controllers\Report\UserFeedbackController;
use App\Http\Controllers\Inspection\InspectionController;
use App\Http\Controllers\Service\ExistingLicenseController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\EntityLockerController;
use App\Http\Controllers\KyaController;
use App\Http\Controllers\MigrationPlan\MigrationPlanController;
use App\Http\Controllers\Service\PaymentController;
use App\Http\Controllers\Service\FeedbackController;
use App\Http\Controllers\PanVerificationController;
use App\Http\Controllers\Service\AppealController;
use App\Http\Controllers\Admin\ActivityLogController;
use app\Http\Controllers\DeployController;
use App\Http\Controllers\Service\ClearanceController;
use App\Http\Controllers\TestingFacilityCapabilityController;
use App\Http\Controllers\PublicNotificationController;

Route::prefix('user')->group(function () {
    Route::post('register', [UserController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('send-otp', [AuthController::class, 'send_otp']);
    Route::post('verify-otp', [AuthController::class, 'verify_otp']);
    Route::post('forgot-password-send-otp', [AuthController::class, 'forgot_password_send_otp']);
    Route::post('forgot-password-verify-otp', [AuthController::class, 'forgot_password_verify_otp']);
    Route::post('forgot-password-reset', [AuthController::class, 'forgot_password_reset']);
    Route::post('check-pan-registered', [AuthController::class, 'check_pan_registered']);
    Route::post('check-mobile-resgistered', [AuthController::class, 'check_mobile_registered']);
});

Route::middleware(['auth:api', JWTActivityMiddleware::class])->group(function () {

    Route::prefix('user')->group(function () {
        Route::post('profile-update', [UserController::class, 'update_profile']);
        Route::post('profile-delete', [UserController::class, 'delete_profile']);
        Route::post('get-profile', [UserController::class, 'get_profile']);
        Route::post('send-profile-update-otp', [UserController::class, 'send_profile_update_otp']);
        Route::post('verify-profile-update-otp', [UserController::class, 'verify_profile_update_otp']);
        Route::post('get-duplicate-pan-accounts', [UserController::class, 'get_duplicate_pan_accounts']);
        Route::post('choose-active-account', [UserController::class, 'choose_active_account']);

        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'change_password']);
    });

    Route::post('caf/unit-details-store', [UnitDetailController::class, 'unit_details_store_or_update']);
    Route::post('caf/unit-details-view', [UnitDetailController::class, 'unit_details_view']);

    Route::post('caf/management-details-store', [ManagementDetailsController::class, 'management_details_store_or_update']);
    Route::post('caf/management-details-view', [ManagementDetailsController::class, 'management_details_view']);

    Route::post('auth-get-all-roles', [RoleController::class, 'all_roles'])->name('roles.all_roles');
    Route::post('auth-store-role', [RoleController::class, 'store_role'])->name('roles.store_role');
    Route::post('auth-show-role', [RoleController::class, 'show_role'])->name('roles.show_role');
    Route::post('auth-update-role', [RoleController::class, 'update_role'])->name('roles.update_role');
    Route::post('auth-destroy-role', [RoleController::class, 'destroy_role'])->name('roles.destroy_role');

    Route::post('department-store-department', [DepartmentController::class, 'store_department'])->name('department.store_department');
    Route::post('department-show-department', [DepartmentController::class, 'show_department'])->name('department.show_department');
    Route::post('department-update-department', [DepartmentController::class, 'update_department'])->name('department.update_department');
    Route::post('department-destroy-department', [DepartmentController::class, 'destroy_department'])->name('department.destroy_department');


    Route::post('caf/core-application-store-enterprise-detail', [EnterpriseDetailController::class, 'enterprise_details_store_or_update'])->name('core_application.store_enterprise_detail');
    Route::post('caf/core-application-show-enterprise-detail', [EnterpriseDetailController::class, 'show_enterprise_details'])->name('core_application.show_enterprise_detail');

    Route::post('nic-digit-code-store', [NicCodeController::class, 'nic_code_store']);
    Route::post('nic-digit-code-update', [NicCodeController::class, 'nic_code_update']);
    Route::post('nic-digit-code-view', [NicCodeController::class, 'nic_code_view']);
    Route::post('nic-digit-code-delete', [NicCodeController::class, 'nic_code_delete']);
    Route::post('fetch-all-nic-2-digit-codes-with-description', [NicCodeController::class, 'fetch_all_nic_2_digit_codes_with_description']);
    Route::post('fetch-all-nic-4-digit-codes-with-description', [NicCodeController::class, 'fetch_all_nic_4_digit_codes_with_description']);
    Route::post('fetch-all-nic-5-digit-codes-with-description', [NicCodeController::class, 'fetch_all_nic_5_digit_codes_with_description']);

    Route::post('caf/line-of-activity-store', [LineOfActivityDetailsController::class, 'line_of_activity_store_or_update']);
    Route::post('caf/line-of-activity-delete', [LineOfActivityDetailsController::class, 'line_of_activity_delete']);
    Route::post('caf/raw-material-delete', [LineOfActivityDetailsController::class, 'raw_material_delete']);
    Route::post('caf/list-of-products-delete', [LineOfActivityDetailsController::class, 'list_of_products_delete']);
    Route::post('caf/line-of-activity-view', [LineOfActivityDetailsController::class, 'line_of_activity_view']);

    Route::post('caf/general-attachment-store', [GeneralAttachmentsController::class, 'general_attachment_store_or_update']);
    Route::post('caf/general-attachment-view', [GeneralAttachmentsController::class, 'general_attachment_view']);

    Route::post('caf/bank-detail-store', [BankDetailController::class, 'bank_detail_store_or_update']);
    Route::post('caf/bank-detail-view', [BankDetailController::class, 'bank_detail_view']);

    Route::post('caf/activity-store', [ActivityController::class, 'activity_store']);
    Route::post('caf/activity-delete', [ActivityController::class, 'activity_delete']);
    Route::post('caf/activity-view', [ActivityController::class, 'activity_view']);

    Route::prefix('admin')->group(function () {

        Route::post('login-by-admin', [AuthController::class, 'login_by_admin']);
        Route::post('return-to-admin', [AuthController::class, 'return_to_admin']);

        Route::prefix('incentive')->group(function () {

            Route::post('proforma-store', [ProformaController::class, 'proforma_store']);
            Route::post('proforma-update', [ProformaController::class, 'proforma_update']);
            Route::post('proforma-list', [ProformaController::class, 'proforma_list']);
            Route::post('fetch-proforma-details', [ProformaController::class, 'fetch_proforma_details']);
            Route::post('proforma-delete', [ProformaController::class, 'proforma_delete']);

            Route::post('scheme-store', [SchemeController::class, 'scheme_store']);
            Route::post('scheme-update', [SchemeController::class, 'scheme_update']);
            Route::post('scheme-list', [SchemeController::class, 'scheme_list']);
            Route::post('fetch-scheme-details', [SchemeController::class, 'fetch_scheme_details']);
            Route::post('scheme-delete',   [SchemeController::class, 'scheme_delete']);

            Route::post('proforma-questionnaire-store',  [ProformaQuestionnaireController::class, 'proforma_questionnaire_store']);
            Route::post('proforma-questionnaire-update', [ProformaQuestionnaireController::class, 'proforma_questionnaire_update']);
            Route::post('proforma-questionnaire-delete', [ProformaQuestionnaireController::class, 'proforma_questionnaire_delete']);
            Route::post('proforma-questionnaire-view',   [ProformaQuestionnaireController::class, 'proforma_questionnaire_view']);
            Route::post('fetch-proforma-questionnaire-details', [ProformaQuestionnaireController::class, 'proforma_questionnaire_details']);
        });

        Route::post('service-template-show', [CertificateController::class, 'service_template_show']);
        Route::post('service-template-store',  [CertificateController::class, 'service_template_store']);

        Route::post('download-application-pdf',  [CertificateController::class, 'download_application_pdf']);

        Route::post('service-master-store', [ServiceMasterController::class, 'service_master_store']);
        Route::post('service-master-update', [ServiceMasterController::class, 'service_master_update']);
        Route::post('service-master-delete', [ServiceMasterController::class, 'service_master_delete']);
        Route::post('service-third-party-params-store', [ServiceMasterController::class, 'service_third_party_params_store']);
        Route::post('service-third-party-params-update', [ServiceMasterController::class, 'service_third_party_params_update']);
        Route::post('service-third-party-params-view', [ServiceMasterController::class, 'service_third_party_params_view']);
        Route::post('service-third-party-params-delete', [ServiceMasterController::class, 'service_third_party_params_delete']);
        Route::post('update-service-status/{id}', [ServiceMasterController::class, 'update_service_status']);
        Route::post('export-services', [ServiceMasterController::class, 'export_services']);

        Route::post('renewal-cycle-store', [RenewalCycleController::class, 'renewal_cycle_store']);
        Route::post('renewal-cycle-update', [RenewalCycleController::class, 'renewal_cycle_update']);
        Route::post('renewal-cycle-delete', [RenewalCycleController::class, 'renewal_cycle_delete']);

        Route::post('service-questionnaire-store', [ServiceQuestionnaireController::class, 'service_questionnaire_store']);
        Route::post('service-questionnaire-update', [ServiceQuestionnaireController::class, 'service_questionnaire_update']);
        Route::post('service-questionnaire-delete', [ServiceQuestionnaireController::class, 'service_questionnaire_delete']);
        Route::post('fetch-questionnaire-section', [ServiceQuestionnaireController::class, 'fetch_questionnaire_section']);

        Route::post('service-fee-rule-store', [ServiceFeeRuleController::class, 'service_fee_rule_store']);
        Route::post('service-fee-rule-update', [ServiceFeeRuleController::class, 'service_fee_rule_update']);
        Route::post('service-fee-rule-delete', [ServiceFeeRuleController::class, 'service_fee_rule_delete']);

        Route::post('renewal-fee-rule-store', [RenewalFeeRuleController::class, 'renewal_fee_rule_store']);
        Route::post('renewal-fee-rule-update', [RenewalFeeRuleController::class, 'renewal_fee_rule_update']);
        Route::post('renewal-fee-rule-delete', [RenewalFeeRuleController::class, 'renewal_fee_rule_delete']);

        Route::post('service-approval-flow-store', [ServiceApprovalFlowController::class, 'service_approval_flow_store']);
        Route::post('service-approval-flow-update', [ServiceApprovalFlowController::class, 'service_approval_flow_update']);
        Route::post('service-approval-flow-delete', [ServiceApprovalFlowController::class, 'service_approval_flow_delete']);

        Route::post('fetch-all-business-users', [AdminController::class, 'fetch_all_business_users']);
        Route::post('fetch-all-department-users', [AdminController::class, 'fetch_all_department_users']);
        Route::post('get-department-user-details', [UserController::class, 'get_department_user_details']);
        Route::post('update-user-status/{user_id}', [AdminController::class, 'update_user_status']);
        Route::post('update-user-profile', [AdminController::class, 'update_user_profile']);

        Route::post('existing-license-update-status', [ExistingLicenseController::class, 'existing_license_update_status']);
        Route::post('existing-license-view', [ExistingLicenseController::class, 'existing_license_view']);
        Route::post('existing-license-details', [ExistingLicenseController::class, 'existing_license_details']);

        Route::post('get-all-applications-list', [UserServiceApplicationController::class, 'get_all_applications_list']);
        Route::post('get-all-applications-details', [UserServiceApplicationController::class, 'get_all_applications_details']);
        Route::post('applications/export-full', [UserServiceApplicationController::class, 'export_all_applications']);
        Route::post('applications/export-filtered', [UserServiceApplicationController::class, 'export_filtered_applications']);
        Route::post('mark-application-paid', [UserServiceApplicationController::class, 'mark_application_paid']);
        Route::post('delete-user-service-application', [UserServiceApplicationController::class, 'admin_delete_user_service_application']);

        Route::post('get-total-applications-by-admin', [DashboardController::class, 'get_total_applications_by_admin']);
        Route::post('get-analytical-dashboard-count-for-admin', [DashboardController::class, 'get_analytical_dashboard_count_for_admin']);

        Route::post('activity-logs', [ActivityLogController::class, 'activity_logs']);
        Route::post('activity-log-details', [ActivityLogController::class, 'activity_log_details']);
        Route::post('activity-log-filters', [ActivityLogController::class, 'activity_log_filters']);

        Route::post('public-notification-store', [PublicNotificationController::class, 'public_notification_store']);
        Route::post('public-notification-update', [PublicNotificationController::class, 'public_notification_update']);
        Route::post('public-notification-delete', [PublicNotificationController::class, 'public_notification_delete']);
    });

    Route::post('fetch-all-services', [ServiceMasterController::class, 'fetch_all_services']);
    Route::post('fetch-service-details', [ServiceMasterController::class, 'fetch_service_details']);
    Route::post('service-questionnaire-view', [ServiceQuestionnaireController::class, 'service_questionnaire_view']);
    Route::post('renewal-cycle-view', [RenewalCycleController::class, 'renewal_cycle_view']);
    Route::post('service-fee-rule-view', [ServiceFeeRuleController::class, 'service_fee_rule_view']);
    Route::post('service-approval-flow-view', [ServiceApprovalFlowController::class, 'service_approval_flow_view']);
    Route::post('renewal-fee-rule-view', [RenewalFeeRuleController::class, 'renewal_fee_rule_view']);
    Route::post('get-approved-services', [ClearanceController::class, 'get_approved_services']);

    Route::prefix('user')->group(function () {

        Route::post('service-application-store', [UserServiceApplicationController::class, 'user_service_application_store']);
        Route::post('service-application-update', [UserServiceApplicationController::class, 'user_service_application_update']);
        Route::post('service-application-view', [UserServiceApplicationController::class, 'user_service_application_view']);
        Route::post('service-application-delete', [UserServiceApplicationController::class, 'user_service_application_delete']);
        Route::post('get-all-user-service-applications', [UserServiceApplicationController::class, 'get_all_user_service_applications']);
        Route::post('get-details-user-service-applications', [UserServiceApplicationController::class, 'get_details_user_service_applications']);
        Route::post('download-user-application-pdf',  [CertificateController::class, 'download_application_pdf']);
        Route::post('get-user-applications-per-service', [UserServiceApplicationController::class, 'get_user_applications_per_service']);
        Route::post('/third-party-apply/{service_id}', [ServiceMasterController::class, 'third_party_apply']);
        Route::post('calculate-fee', [UserServiceApplicationController::class, 'calculate_fee']);
        Route::post('calculate-industrial-estate-amounts', [UserServiceApplicationController::class, 'calculate_industrial_estate_amounts']);

        Route::post('update-payment', [PaymentController::class, 'update_payment']);
        Route::post('user-service-applications-by-payment-status', [PaymentController::class, 'user_service_applications_by_payment_status']);
        Route::post('check-payment-status', [PaymentController::class, 'check_payment_status']);
        Route::post('check-all-pending-payments', [PaymentController::class, 'check_all_pending_payments']);
        Route::post('get-grn-status', [PaymentController::class, 'get_grn_status']);

        Route::post('existing-license-store', [ExistingLicenseController::class, 'existing_license_store']);
        Route::post('existing-license-update', [ExistingLicenseController::class, 'existing_license_update']);
        Route::post('existing-license-view', [ExistingLicenseController::class, 'existing_license_view']);
        Route::post('existing-license-delete', [ExistingLicenseController::class, 'existing_license_delete']);
        Route::post('existing-license-details', [ExistingLicenseController::class, 'existing_license_details']);
        Route::post('get-department-services', [ExistingLicenseController::class, 'get_department_services']);

        Route::prefix('incentive')->group(function () {
            Route::post('scheme-list', [UserIncentiveApplicationController::class, 'user_incentive_scheme_list']);
            Route::post('eligibility-proforma-list', [UserIncentiveApplicationController::class, 'user_eligibility_proforma_list']);
            Route::post('claim-proforma-list', [UserIncentiveApplicationController::class, 'user_claim_proforma_list']);
            Route::post('proforma-questionnaire-view',   [UserIncentiveApplicationController::class, 'user_proforma_questionnaire_view']);
            Route::post('proforma-application-store', [UserIncentiveApplicationController::class, 'user_proforma_application_store']);
            Route::post('application-workflow-history', [UserIncentiveApplicationController::class, 'application_workflow_history']);
        });

        Route::post('/get-total-applications-by-user', [DashboardController::class, 'get_total_applications_by_user']);

        Route::post('calculate-renewal-fee', [UserServiceApplicationController::class, 'calculate_renewal_fee']);
        Route::post('update-renewed-application', [UserServiceApplicationController::class, 'update_renewed_application']);
        Route::post('get-applications-ready-for-renewal', [UserServiceApplicationController::class, 'get_applications_ready_for_renewal']);
        Route::post('service/renewal-cycles', [UserServiceApplicationController::class, 'get_service_renewal_cycles']);

        Route::post('service-feedback-store', [FeedbackController::class, 'service_feedback_store']);
        Route::post('service-feedback-list', [FeedbackController::class, 'service_feedback_list']);

        Route::post('user-appeal-store', [AppealController::class, 'user_appeal_store']);

        Route::post('user-unit-store', [UserController::class, 'user_unit_store']);
        Route::post('user-unit-update', [UserController::class, 'user_unit_update']);
        Route::post('get-user-unit-list', [UserController::class, 'get_user_unit_list']);

        Route::post('fetch-licence-numbers', [ClearanceController::class, 'fetch_licence_numbers']);
        Route::post('fetch-user-clearances', [ClearanceController::class, 'fetch_user_clearances']);
        Route::post('fetch-clearance-details', [ClearanceController::class, 'fetch_clearance_details']);
    });

    Route::post('holidays-store', [HolidayController::class, 'holidays_store']);
    Route::post('holidays-update', [HolidayController::class, 'holidays_update']);
    Route::post('holidays-view', [HolidayController::class, 'holidays_view']);
    Route::post('holiday-delete', [HolidayController::class, 'holiday_delete']);

    Route::prefix('public')->group(function () {
        Route::post('/services/count', [ServiceController::class, 'get_total_services']);
        Route::post('/applications/count-by-service', [ServiceController::class, 'get_applications_count_by_service']);
        Route::post('/fees/total', [ServiceController::class, 'get_total_fees_paid_all_services']);
        Route::post('/fees/per-service', [ServiceController::class, 'get_total_fees_paid_per_service']);
        Route::post('/fees/avg-per-service', [ServiceController::class, 'get_avg_fees_paid_per_service']);
        Route::post('/approvals/avg-timeline-per-service', [ServiceController::class, 'get_average_approval_timeline_per_service']);
        Route::post('/applications/approved-count-per-service', [ServiceController::class, 'get_approved_applications_per_service']);
        Route::post('/applications/pending-count-per-service', [ServiceController::class, 'get_pending_applications_per_service']);
    });

    Route::prefix('department')->group(function () {
        Route::prefix('incentive')->group(function () {
            Route::post('applications', [UserIncentiveApplicationController::class, 'get_department_applications']);
            Route::post('update-application-status', [UserIncentiveApplicationController::class, 'update_application_status']);
            Route::post('application-details', [UserIncentiveApplicationController::class, 'application_details']);
        });

        Route::post('/services', [ServiceController::class, 'get_services_by_department']);
        Route::post('/applications', [ServiceController::class, 'get_department_applications']);
        Route::post('/applications/{id}', [ServiceController::class, 'get_application_details']);
        Route::post('/applications/{id}/status', [ServiceController::class, 'update_application_status']);
        Route::post('/dashboard', [ServiceController::class, 'get_department_dashboard']);
        Route::post('/workflow-history/{application_id}', [ServiceController::class, 'get_work_flow_history']);
        Route::post('/user/{id}/assigned-applications', [ServiceController::class, 'get_department_user_assigned_applications']);
        Route::post('/preview-certificate/{application_id}', [ServiceController::class, 'preview_certificate']);

        Route::post('certificate-variables-list',  [CertificateController::class, 'certificate_variables_list']);
        Route::post('user-certificate-view',  [CertificateController::class, 'user_certificate_view']);
        Route::post('user-certificate-generate',  [CertificateController::class, 'user_certificate_generate']);
        Route::post('upload-offline-certificate',  [CertificateController::class, 'upload_offline_certificate']);

        Route::post('/get-department-users', [UserController::class, 'get_department_users']);
        Route::post('/get-user-caf-unit_details', [UnitDetailController::class, 'get_user_caf_unit_details']);
        Route::post('/get-user-caf-management-details', [ManagementDetailsController::class, 'get_user_caf_management_details']);
        Route::post('/get-user-caf-enterprise-details', [EnterpriseDetailController::class, 'get_user_caf_enterprise_details']);
        Route::post('/get-user-caf-lineOfActivity-details', [LineOfActivityDetailsController::class, 'get_user_caf_lineOfActivity_details']);
        Route::post('/get-user-caf-generalAttachment-details', [GeneralAttachmentsController::class, 'get_user_caf_generalAttachment_details']);
        Route::post('/get-user-caf-bank-details', [BankDetailController::class, 'get_user_caf_bank_details']);
        Route::post('/get-user-caf-activity-details', [ActivityController::class, 'get_user_caf_activity_details']);

        Route::post('/get-total-applications-by-department', [DashboardController::class, 'get_total_applications_by_department']);
        Route::post('/get-list-of-NOC-issued-by-department', [ServiceController::class, 'get_list_of_NOC_issued_by_department']);
        Route::post('export-service-applications', [ServiceController::class, 'export_service_applications']);

        Route::post('inspections-by-department', [InspectionController::class, 'inspections_by_department']);
        Route::post('inspections-status-update', [InspectionController::class, 'inspections_status_update']);
        Route::post('approved-inspections-list', [InspectionController::class, 'approved_inspections_list']);
        Route::post('inspection-date-update-by-inspector', [InspectionController::class, 'inspection_date_update_by_inspector']);

        Route::post('update-joint-inspection', [InspectionController::class, 'update_joint_inspection']);

        Route::post('get-department-appeals/{user_id}', [AppealController::class, 'get_department_appeals']);
        Route::post('update-appeal-status', [AppealController::class, 'update_appeal_status']);
    });

    Route::post('table-columns', [SchemaController::class, 'get_table_columns']);
    Route::post('get-default-source', [ServiceMasterController::class, 'get_default_source_value']);

    Route::prefix('inspection')->group(function () {
        Route::post('inspection-store', [InspectionController::class, 'inspection_store']);
        Route::post('inspection-view', [InspectionController::class, 'inspection_view']);
        Route::post('inspection-update', [InspectionController::class, 'inspection_update']);
        Route::post('inspection-delete', [InspectionController::class, 'inspection_delete']);
        Route::post('get-inspection-departments', [InspectionController::class, 'get_inspection_departments']);
        Route::post('inspection-list', [InspectionController::class, 'inspection_list']);
        Route::post('date-confirmed-inspections-list-per-user', [InspectionController::class, 'date_confirmed_inspections_list_per_user']);
        Route::post('inspection-date-update-by-user', [InspectionController::class, 'inspection_date_update_by_user']);
    });
});

Route::post('department-get-all-departments', [DepartmentController::class, 'all_departments'])->name('department.all_departments');
Route::post('inspectors-by-department', [InspectionController::class, 'inspectors_by_department']);

Route::post('/tripura/get-all-districts', [TripuraMasterDataController::class, 'get_districts']);
Route::post('/tripura/get-sub-subdivisions', [TripuraMasterDataController::class, 'get_subdivisions']);
Route::post('/tripura/get-block-names', [TripuraMasterDataController::class, 'get_ulbs']);
Route::post('/tripura/get-gp-vc-wards', [TripuraMasterDataController::class, 'get_wards']);
Route::post('/tripura/get-multiple-subdivisions', [TripuraMasterDataController::class, 'get__multiple_subdivisions']);
Route::post('/tripura/get-multiple-block', [TripuraMasterDataController::class, 'get_multiple_ulbs']);

Route::prefix('report')->group(function () {
    Route::post('registration-renewal-granted', [ReportController::class, 'registration_renewal_granted']);
    Route::post('online-single-windows', [ReportController::class, 'online_single_windows']);
    Route::post('application-status', [ReportController::class, 'application_status']);
    Route::post('department-user-list', [ReportController::class, 'department_user_list']);
    Route::post('approval-steps-list', [ReportController::class, 'approval_step_list']);
    Route::post('user-list', [ReportController::class, 'user_list']);
    Route::post('industry-report-summary', [ReportController::class, 'industry_report_summary']);
    Route::post('industry-report-details', [ReportController::class, 'industry_report_details']);
    Route::post('department-approvals', [ReportController::class, 'departmental_approvals']);
    Route::post('cis-summary-report', [ReportController::class, 'cis_summary_report']);
    Route::post('cis-details-report', [ReportController::class, 'cis_details_report']);
});

Route::post('user-feedback-store', [UserFeedbackController::class, 'user_feedback_store']);
Route::post('user-feedback-list', [UserFeedbackController::class, 'user_feedback_list']);
Route::post('user-feedback-details', [UserFeedbackController::class, 'user_feedback_details']);

Route::post('unit-list', [InspectionController::class, 'unit_list']);
Route::post('get-unit-details', [InspectionController::class, 'get_unit_details']);

Route::post('user/third-party/return', [UserServiceApplicationController::class, 'third_party_return'])->name('third_party.return');
Route::post('/third-party/status/update', [UserServiceApplicationController::class, 'update_third_party_status_log']);

Route::prefix('kya')->group(function () {
    Route::post('/sectors', [KyaController::class, 'get_sectors']);
    Route::post('/risk-categories', [KyaController::class, 'get_risk_categories']);
    Route::post('/industry-sectors', [KyaController::class, 'get_industry_sectors']);
    Route::post('/questions', [KyaController::class, 'get_questions']);
    Route::post('/approval-details', [KyaController::class, 'get_approval_details']);
});

Route::post('migration-notice', [MigrationPlanController::class, 'migration_notice']);

Route::prefix('pan')->group(function () {
    Route::post('verify', [PanVerificationController::class, 'verify_pan']);
    Route::post('verify-multiple', [PanVerificationController::class, 'verify_multiple_pans']);
    Route::get('status-codes', [PanVerificationController::class, 'get_status_codes']);
});

Route::post('/external-send-otp-sms', [AuthController::class, 'external_send_otp']);

Route::post('entity_locker-initiate', [EntityLockerController::class, 'initiate_auth']);
Route::get('entity_locker', [EntityLockerController::class, 'handle_callback']);
Route::post('entity_locker-documents', [EntityLockerController::class, 'user_documents']);

Route::post('/deploy-backend-latest-code-in-server', [DeployController::class, 'deploy']);

Route::post('get-testing-facility-capabilities', [TestingFacilityCapabilityController::class, 'get_testing_facility_capabilities']);
Route::post('get-testing-facilities', [TestingFacilityCapabilityController::class, 'get_testing_facilities']);
Route::post('get-fssai-lab-equipment', [TestingFacilityCapabilityController::class, 'get_fssai_lab_equipment']);

Route::post('get-department-wise-static-count', [DashboardController::class, 'get_department_wise_static_count']);
Route::post('get-overall-static-count', [DashboardController::class, 'get_overall_static_count']);

Route::post('public-notification-list', [PublicNotificationController::class, 'public_notification_list']);
Route::post('public-notification-view', [PublicNotificationController::class, 'public_notification_view']);
Route::post('pan-lookup', [UserController::class, 'pan_lookup'])->middleware('pan.lookup.rl');
Route::post('get-all-services', [ServiceMasterController::class, 'get_all_services']);
