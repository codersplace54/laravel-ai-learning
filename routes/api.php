<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
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
use App\Http\Controllers\Service\ServiceApprovalFlowController;
use App\Http\Controllers\Service\UserServiceApplicationController;
use App\Http\Controllers\Service\HolidayController;
use App\Http\Controllers\Service\ServiceController;
use App\Http\Controllers\Subdivision\TripuraMasterDataController;
use App\Http\Controllers\SchemaController;



Route::prefix('user')->group(function () {
    Route::post('register', [UserController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:api')->group(function () {

    Route::prefix('user')->group(function () {
        Route::post('profile-update', [UserController::class, 'update_profile']);
        Route::post('profile-delete', [UserController::class, 'delete_profile']);
        Route::post('get-profile', [UserController::class, 'get_profile']);

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



    Route::post('department-get-all-departments', [DepartmentController::class, 'all_departments'])->name('department.all_departments');
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
    Route::post('caf/line-of-activity-view', [LineOfActivityDetailsController::class, 'line_of_activity_view']);

    Route::post('caf/general-attachment-store', [GeneralAttachmentsController::class, 'general_attachment_store_or_update']);
    Route::post('caf/general-attachment-view', [GeneralAttachmentsController::class, 'general_attachment_view']);

    Route::post('caf/bank-detail-store', [BankDetailController::class, 'bank_detail_store_or_update']);
    Route::post('caf/bank-detail-view', [BankDetailController::class, 'bank_detail_view']);

    Route::post('caf/activity-store', [ActivityController::class, 'activity_store']);
    Route::post('caf/activity-delete', [ActivityController::class, 'activity_delete']);

    Route::post('service-master-store', [ServiceMasterController::class, 'service_master_store']);
    Route::post('service-master-update', [ServiceMasterController::class, 'service_master_update']);
    Route::post('service-master-delete', [ServiceMasterController::class, 'service_master_delete']);
    Route::post('fetch-all-services', [ServiceMasterController::class, 'fetch_all_services']);
    Route::post('fetch-service-details', [ServiceMasterController::class, 'fetch_service_details']);


    Route::post('renewal-cycle-store', [RenewalCycleController::class, 'renewal_cycle_store']);
    Route::post('renewal-cycle-update', [RenewalCycleController::class, 'renewal_cycle_update']);
    Route::post('renewal-cycle-delete', [RenewalCycleController::class, 'renewal_cycle_delete']);
    Route::post('renewal-cycle-view', [RenewalCycleController::class, 'renewal_cycle_view']);

    Route::post('service-questionnaire-store', [ServiceQuestionnaireController::class, 'service_questionnaire_store']);
    Route::post('service-questionnaire-update', [ServiceQuestionnaireController::class, 'service_questionnaire_update']);
    Route::post('service-questionnaire-delete', [ServiceQuestionnaireController::class, 'service_questionnaire_delete']);
    Route::post('service-questionnaire-view', [ServiceQuestionnaireController::class, 'service_questionnaire_view']);

    Route::post('service-fee-rule-store', [ServiceFeeRuleController::class, 'service_fee_rule_store']);
    Route::post('service-fee-rule-update', [ServiceFeeRuleController::class, 'service_fee_rule_update']);
    Route::post('service-fee-rule-view', [ServiceFeeRuleController::class, 'service_fee_rule_view']);
    Route::post('service-fee-rule-delete', [ServiceFeeRuleController::class, 'service_fee_rule_delete']);

    Route::post('service-approval-flow-store', [ServiceApprovalFlowController::class, 'service_approval_flow_store']);
    Route::post('service-approval-flow-update', [ServiceApprovalFlowController::class, 'service_approval_flow_update']);
    Route::post('service-approval-flow-view', [ServiceApprovalFlowController::class, 'service_approval_flow_view']);
    Route::post('service-approval-flow-delete', [ServiceApprovalFlowController::class, 'service_approval_flow_delete']);

    Route::post('user-service-application-store', [UserServiceApplicationController::class, 'user_service_application_store']);
    Route::post('user-service-application-update', [UserServiceApplicationController::class, 'user_service_application_update']);
    Route::post('user-service-application-view', [UserServiceApplicationController::class, 'user_service_application_view']);
    Route::post('user-service-application-delete', [UserServiceApplicationController::class, 'user_service_application_delete']);

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

        Route::post('/tripura/get-all-districts', [TripuraMasterDataController::class, 'get_districts']);
        Route::post('/tripura/get-sub-subdivisions', [TripuraMasterDataController::class, 'get_subdivisions']);
        Route::post('/tripura/get-block-names', [TripuraMasterDataController::class, 'get_ulbs']);
        Route::post('/tripura/get-gp-vc-wards', [TripuraMasterDataController::class, 'get_wards']);
    });

     Route::prefix('department')->group(function () {
        Route::post('/services', [ServiceController::class, 'get_services_by_department']);
        Route::post('/applications', [ServiceController::class, 'get_department_applications']);
        Route::post('/applications/{id}', [ServiceController::class, 'get_application_details']);

     });

     Route::post('table-columns', [SchemaController::class, 'get_table_columns']);
});
