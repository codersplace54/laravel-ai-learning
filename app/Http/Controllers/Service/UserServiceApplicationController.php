<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\UserServiceApplication;
use App\Models\ServiceFeeRule;
use App\Models\ServiceApprovalFlow;
use Carbon\Carbon;
use App\Models\Holiday;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\ServiceQuestionnaire;
use App\Models\ServiceMaster;
use App\Models\ThirdPartyStatusLog;
use App\Models\ApplicationWorkflowHistory;
use App\Models\RenewalFeeRule;
use App\Models\RenewalCycle;
use App\Models\PaymentOrder;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ApplicationsExport;
use App\Models\UnitDetail;
use Illuminate\Http\UploadedFile;
use App\Services\ApplicationDataFormatter;
use App\Services\SmsService;
use App\Jobs\SendWhatsAppNotification;
use App\Models\Department;
use App\Models\IndustrialEstate;
use App\Models\User;
use App\Traits\LogsActivity;
use App\Traits\PaymentMapTrait;
use App\Http\Controllers\Service\CertificateController;
use App\Models\LabourDeposit;
use Illuminate\Support\Facades\Log;

class UserServiceApplicationController extends Controller
{
    use LogsActivity, PaymentMapTrait;

    public function user_service_application_store(Request $request)
    {
        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }


            if ($request->save_data != 1) {
                $request->validate([
                    'service_id'            => 'required|integer|exists:service_masters,id',
                    'renewal_cycle_id'      => 'nullable|integer|exists:renewal_cycles,id',
                    'previous_application_id' => 'nullable|integer',
                    'renewal'               => 'nullable|in:yes,no',
                    'renewalYear'           => 'nullable|integer|min:1|max:10',
                    'applicationId'         => 'nullable|string|max:255',
                    'application_date'      => 'nullable|date',
                    'status'                => 'nullable|in:draft,submitted,under_review,approved,rejected,re_submitted,send_back,saved, expired',
                    'application_data'      => 'nullable|array',
                    'applied_fee'           => 'nullable|numeric',
                    'approved_fee'          => 'nullable|numeric',
                    'payment_status'        => 'nullable|string',
                    'remarks'               => 'nullable|string',
                    'NOC_application_date'  => 'nullable|date',
                    'NOC_expiry_date'       => 'nullable|date',
                    'PreviousNOCexpiryDate' => 'nullable|date',
                    'payment_transId'       => 'nullable|string|max:255',
                    'GRN_number'            => 'nullable|string|max:255',
                    'payment_time'          => 'nullable|date',
                    'extra_payment'         => 'nullable|numeric',
                    'comments'              => 'nullable|string',
                    'NOC_certificate'       => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                    'NOC_rejection_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                    'NOC_generationDate'    => 'nullable|date',
                    'NOC_penalty_amount'    => 'nullable|numeric',
                    'NOC_letter_number'     => 'nullable|string|max:255',
                    'NOC_letter_date'       => 'nullable|date',
                    'NSW_Application_Save_ID' => 'nullable|string|max:255',
                    'NSW_license_status'    => 'nullable|in:pending,approved,rejected,expired',
                    'NSW_Push_Document_ID'  => 'nullable|string|max:255',
                    'final_fee'             => 'nullable|string',
                    'total_fee'             => 'nullable|string',
                    'current_step_number'   => 'nullable|date',
                    'max_processing_date'   => 'nullable|string',

                    'external_application_id'   => 'nullable|string',
                    'external_status'   => 'nullable|string',
                    'external_payment_status'   => 'nullable|string|in:pending,paid,failed',
                    'external_max_processing_date'   => 'nullable|string',
                    'external_noc_number'   => 'nullable|string',
                    'external_valid_till'   => 'nullable|date',
                    'external_remarks'   => 'nullable|string',
                    'is_third_party'   => 'nullable|integer|in:0,1',
                    'removed_question_ids'   => 'nullable|array',
                    'land_allotment_estimated_amount' => 'required_if:service_id,64',
                ]);

                $this->validate_questionnaire_file_inputs($request);
                $request->merge([
                    'status' => 'saved',
                ]);
            } else {

                $request->validate([
                    'service_id' => 'required|integer|exists:service_masters,id',
                    'id' => 'nullable|integer|exists:user_service_applications,id',
                ]);

                $request->merge([
                    'status' => 'draft',
                ]);
            }

            DB::beginTransaction();

            $noc_certificate = null;
            if ($request->hasFile('NOC_certificate')) {
                $file = $request->file('NOC_certificate');
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $noc_certificate = $file->storeAs("uploads/$user->id/noc_certificates", $filename, 'public');
            }

            $noc_rejection_certificate = null;
            if ($request->hasFile('NOC_rejection_certificate')) {
                $file = $request->file('NOC_rejection_certificate');
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $noc_rejection_certificate = $file->storeAs("uploads/$user->id/noc_rejection_certificates", $filename, 'public');
            }

            $service_data = ServiceMaster::where('id', $request->service_id)
                ->first(['noc_name', 'service_mode',  'target_days', 'allow_repeat_application', 'caf_depends', 'service_title_or_description']);

            $is_caf_filled = UnitDetail::where('user_id', $user->id)->exists();
            $service_depends_and_caf_filled = ($service_data->caf_depends === 'yes') ? $is_caf_filled : true;

            if (!$service_depends_and_caf_filled) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Please complete your CAF (Common Application Form) details before proceeding.',
                ], 200);
            }

            if ($service_data->service_mode === "native") {

                $approval_flow = ServiceApprovalFlow::where('service_id', $request->service_id)
                    ->orderBy('step_number', 'asc')
                    ->first();

                // if (!$approval_flow) {
                //     return response()->json([
                //         'status'  => 0,
                //         'message' => 'You cannot submit an application for this particular service; please contact the administrator.'
                //     ], 404);
                // }

                $department_name = null;

                if ($approval_flow && $approval_flow->department_id) {
                    $department_name = Department::where('id', $approval_flow->department_id)
                        ->value('name');
                }

                $has_approval_flow = !is_null($approval_flow);


                $application_date = Carbon::parse($request->application_date ?? now());
                $target_days = $service_data->target_days ?? 0;

                $max_processing_date = $this->add_working_days($application_date, $target_days);

                if ($service_data->allow_repeat_application  === 'no' && !$request->filled('id')) {
                    $existing = UserServiceApplication::where('user_id', $user->id)
                        ->where('service_id', $request->service_id)
                        ->latest()
                        ->select('id', 'status')
                        ->first();

                    if ($existing) {
                        if (in_array($existing->status, ['draft', 're_submitted', 'send_back', 'extra_payment', 'saved'])) {
                            $request->merge(
                                [
                                    'id' => $existing->id
                                ]
                            );
                        } else {
                            return response()->json([
                                'status'  => 0,
                                'message' => 'You have already applied for this service. Repeat application is not allowed.',
                            ], 409);
                        }
                    }
                }

                if ($request->id) {
                    $user_service_application = UserServiceApplication::where('id', $request->id)->first();
                } else {
                    $user_service_application = null;
                    // $user_service_application = UserServiceApplication::where('user_id', $user->id)
                    // ->where('service_id', $request->service_id)
                    // ->latest()
                    // ->first();
                }

                $application_id =  $user_service_application->id ?? null;

                if ($request->service_id == 37) {
                    $fee_data = $this->calculate_labour_fee_breakdown(
                        $request->service_id,
                        $request->application_data,
                        $application_id
                    );
                } else {

                    $fee_data = $this->calculate_final_fee($request->service_id, $request->application_data, $application_id);
                }
                $final_fee = $fee_data['final_fee'];
                $blc_fee   = $fee_data['effective_fee'];
                $previous_paid = $fee_data['previous_paid'] ?? (float)$user_service_application->paid_amount;
                $total_fee =  $final_fee;
                $status = $request->status ?? 'saved';
                $payment_status = $request->payment_status ?? 'pending';
                $paid_amount = null;
                $payment_time = null;

                if ($status === 'draft') {
                    $status = 'draft';
                    $payment_status = 'pending';
                    $paid_amount = null;
                    $payment_time = null;
                } elseif ((float) $total_fee === 0.0 && $has_approval_flow) {
                    $status = 'submitted';
                    $payment_status = 'paid';
                    $paid_amount = 0;
                    $payment_time = now();
                } elseif ((float) $total_fee === 0.0 && !$has_approval_flow) {
                    $status = 'approved';
                    $payment_status = 'paid';
                    $paid_amount = 0;
                    $payment_time = now();
                } elseif ((float) $total_fee <= (float) $previous_paid) {
                    $status = 're_submitted';
                    $payment_status = 'paid';
                    $paid_amount = $user_service_application->paid_amount;
                }


                if ($user_service_application) {

                    $total_fee =  $final_fee;
                    $previous_paid = $user_service_application->paid_amount ?? 0;
                    $effective_fee = max($total_fee - $previous_paid, 0);

                    if (!in_array($user_service_application->status, ['draft', 'submitted', 're_submitted', 'send_back', 'extra_payment', 'saved'])) {
                        return response()->json([
                            'status' => 0,
                            'message' => "You can't update the application. It's under " . $user_service_application->status . "."
                        ], 403);
                    }

                    if ($user_service_application->extra_payment != null && $user_service_application->payment_status == "pending") {
                        if ($request->extra_payment == null || $request->extra_payment != $user_service_application->extra_payment) {
                            return response()->json([
                                'status' => 0,
                                'message' => "An extra payment of Rs. $user_service_application->extra_payment has been raised. Please make the payment."
                            ], 403);
                        }
                    }

                    $application_data = is_array($user_service_application->application_data)
                        ? $user_service_application->application_data
                        : (json_decode($user_service_application->application_data ?? '[]', true) ?: []);


                    $removed_question_ids = json_decode((string)($request->input('remove_file_question_ids') ?? '[]'), true) ?: [];

                    if (!empty($removed_question_ids)) {
                        $application_data = $this->remove_questions_from_application_data($application_data, $removed_question_ids);
                    }

                    $new_data = $request->input('application_data', []);

                    foreach ($new_data as $key => $value) {
                        if (is_numeric($key)) {
                            $key = (string) $key;
                        }
                        $application_data[$key] = $value;
                    }

                    $files = $request->file('application_data', []);

                    if (!empty($files)) {
                        $application_data = $this->merge_application_files($application_data, $files, $user->id);
                    }

                    $user_service_application->application_data = json_encode($application_data);

                    if ((float) $total_fee === 0.0 && $request->save_data != 1 && $user_service_application->status != "draft") {
                        $status = 're_submitted';
                    }

                    $application_number = null;
                    if ($user_service_application->status === 'draft' && $status !== 'draft') {
                        $application_number = $this->generate_application_number($request->service_id, $user_service_application->id);
                    }

                    $is_resubmission = $user_service_application->status === 'send_back';

                    if ($is_resubmission && $user_service_application->max_processing_date) {
                        $send_back_assignment = ApplicationWorkflowAssignment::where('application_id', $user_service_application->id)
                            ->where('status', 'send_back')
                            ->latest('action_taken_at')
                            ->first();

                        $send_back_at = $send_back_assignment?->action_taken_at ?? $user_service_application->updated_at;
                        $days_taken_by_user = (int) Carbon::parse($send_back_at)->diffInDays(now());
                        $max_processing_date = Carbon::parse($user_service_application->max_processing_date)->addDays($days_taken_by_user);
                    }

                    $user_service_application->update([
                        'renewal_cycle_id'      => $request->renewal_cycle_id ?? $user_service_application->renewal_cycle_id,
                        'renewal'               => $request->renewal ?? $user_service_application->renewal,
                        'renewalYear'           => $request->renewalYear ?? $user_service_application->renewalYear,
                        'applicationId'         => $application_number ?? $user_service_application->applicationId,
                        'application_date'      => in_array($user_service_application->status, ['draft', 'saved'])
                            ? ($request->application_date ?? now())
                            : $user_service_application->application_date,
                        'status'                => $status,
                        'application_data'      => $user_service_application->application_data,
                        // 'applied_fee'           => $request->applied_fee,
                        'approved_fee'          => $request->approved_fee,
                        'payment_status'        => $payment_status,
                        'remarks'               => $request->remarks,
                        'NOC_application_date'  => $request->NOC_application_date,
                        'NOC_expiry_date'       => $request->NOC_expiry_date,
                        'PreviousNOCexpiryDate' => $request->PreviousNOCexpiryDate,
                        'payment_transId'       => $request->payment_transId ?? $user_service_application->payment_transId,
                        'GRN_number'            => $request->GRN_number ?? $user_service_application->GRN_number,
                        'payment_time'          => $payment_time,
                        'extra_payment'         => $request->extra_payment,
                        'comments'              => $request->comments,
                        'NOC_certificate'       => $noc_certificate ?? $user_service_application->NOC_certificate,
                        'NOC_rejection_certificate' => $noc_rejection_certificate ?? $user_service_application->NOC_rejection_certificate,
                        'NOC_generationDate'    => $request->NOC_generationDate,
                        'NOC_penalty_amount'    => $request->NOC_penalty_amount,
                        'NOC_letter_number'     => $request->NOC_letter_number,
                        'NOC_letter_date'       => $request->NOC_letter_date,
                        'NSW_Application_Save_ID' => $request->NSW_Application_Save_ID,
                        'NSW_license_status'    => $request->NSW_license_status,
                        'NSW_Push_Document_ID'  => $request->NSW_Push_Document_ID,
                        'final_fee'             => $final_fee,
                        'total_fee'             => $total_fee,
                        'effective_fee'         => $effective_fee,
                        'current_step_number'   => $is_resubmission
                            ? $user_service_application->current_step_number
                            : ($approval_flow->step_number ?? 0),
                        'max_processing_date'   => $has_approval_flow ? $max_processing_date : null,
                        'paid_amount'           => $paid_amount ?? $user_service_application->paid_amount,
                        'applied_fee'           => $request->land_allotment_estimated_amount ?? null,
                    ]);

                    if ($request->service_id == "37") {
                        $this->store_labour_deposits($request->service_id, $application_data, $user_service_application->id);
                    }

                    if ($request->status != 'draft' && $has_approval_flow) {

                        if ($is_resubmission) {
                            $existing_step = ApplicationWorkflowAssignment::where('application_id', $user_service_application->id)
                                ->where('step_number', $user_service_application->current_step_number)
                                ->latest('id')
                                ->first();

                            if ($existing_step) {
                                $existing_step->update([
                                    'status' => 'pending',
                                    'action_taken_by' => null,
                                    'action_taken_at' => null,
                                    'remarks' => null,
                                ]);
                            }
                        } else {

                            ApplicationWorkflowAssignment::create([
                                'application_id'     => $user_service_application->id,
                                'service_id'         => $request->service_id,
                                'step_number'        => $approval_flow->step_number,
                                'step_type'          => $approval_flow->step_type,
                                'department_id'      => $approval_flow->department_id,
                                'hierarchy_level'    => $approval_flow->hierarchy_level,
                                'assigned_to_group'  => true,
                                'status'             => 'pending',
                                'action_taken_by'    => null,
                                'action_taken_at'    => null,
                                'remarks'            => null,
                            ]);
                        }
                    }

                    $user_service_application->application_data = is_array($user_service_application->application_data)
                        ? $user_service_application->application_data
                        : json_decode($user_service_application->application_data ?? '[]', true);

                    DB::commit();

                    if ($status === 're_submitted') {
                        $user_service_application->logAs($user->user_name . ' re-submitted application', 'Application Re-submitted');
                        $sms = SmsService::buildSmsMessage('application_resubmitted', [
                            'APP_NO' => $user_service_application->applicationId,
                            'DEPT_NAME' => $department_name ?? 'the department',
                        ]);

                        SmsService::send(
                            $user->mobile_no,
                            $sms['message'],
                            $sms['template_id']
                        );
                    }

                    $message = $status === 'draft' ? 'Application saved as draft successfully.' : 'Application updated successfully.';

                    $this->send_application_whatsapp_notification($user, $user_service_application, $service_data, $status, $total_fee, $has_approval_flow);

                    return response()->json([
                        'status'  => 1,
                        'message' => $message,
                        'data' => $user_service_application
                    ], 200);
                } else {

                    $application_data = (array) $request->input('application_data', []);
                    $files = $request->file('application_data', []);

                    if (!empty($files)) {
                        $application_data = $this->merge_application_files($application_data, $files, $user->id);
                    }

                    $request->merge(['application_data' => $application_data]);

                    // Carry forward paid amount from any previous paid application for same user+service
                    $previous_paid_application = UserServiceApplication::where('user_id', $user->id)
                        ->where('service_id', $request->service_id)
                        ->where('payment_status', 'paid')
                        ->whereNotNull('paid_amount')
                        ->where('paid_amount', '>', 0)
                        ->latest('id')
                        ->first();

                    $carried_paid_amount = $previous_paid_application ? (float) $previous_paid_application->paid_amount : 0;
                    $previous_application_id = $previous_paid_application?->id;

                    if ($carried_paid_amount > 0) {
                        $effective_fee = max($total_fee - $carried_paid_amount, 0);
                        if ($effective_fee == 0) {
                            $status = 're_submitted';
                            $payment_status = 'paid';
                            $paid_amount = $carried_paid_amount;
                            $payment_time = now();
                        }
                    }

                    $user_service_application = UserServiceApplication::create([
                        'user_id'                 => $user->id,
                        'service_id'              => $request->service_id,
                        'renewal_cycle_id'        => $request->renewal_cycle_id,
                        'renewal'                 => $request->renewal,
                        'renewalYear'             => $request->renewalYear,
                        'application_date'        => $request->application_date ?? now(),
                        'status'                  => $status,
                        'application_data'        => json_encode($application_data ?: null),
                        'approved_fee'            => $request->approved_fee,
                        'payment_status'          => $payment_status,
                        'remarks'                 => $request->remarks,
                        'NOC_application_date'    => $request->NOC_application_date,
                        'NOC_expiry_date'         => $request->NOC_expiry_date,
                        'PreviousNOCexpiryDate'   => $request->PreviousNOCexpiryDate,
                        'payment_transId'         => $request->payment_transId,
                        'GRN_number'              => $request->GRN_number,
                        'payment_time'            => $payment_time,
                        'extra_payment'           => $request->extra_payment,
                        'comments'                => $request->comments,
                        'NOC_certificate'         => $noc_certificate,
                        'NOC_rejection_certificate' => $noc_rejection_certificate,
                        'NOC_generationDate'      => $request->NOC_generationDate,
                        'NOC_penalty_amount'      => $request->NOC_penalty_amount,
                        'NOC_letter_number'       => $request->NOC_letter_number,
                        'NOC_letter_date'         => $request->NOC_letter_date,
                        'NSW_Application_Save_ID' => $request->NSW_Application_Save_ID,
                        'NSW_license_status'      => $request->NSW_license_status,
                        'NSW_Push_Document_ID'    => $request->NSW_Push_Document_ID,
                        'final_fee'               => $final_fee,
                        'total_fee'               => $total_fee,
                        'effective_fee'           => $carried_paid_amount > 0 ? max($total_fee - $carried_paid_amount, 0) : 0,
                        'paid_amount'             => $carried_paid_amount > 0 ? $carried_paid_amount : $paid_amount,
                        'previous_application_id' => $previous_application_id,
                        'current_step_number'     => $approval_flow->step_number ?? 0,
                        'max_processing_date'     => $has_approval_flow ? $max_processing_date : null,
                        'applied_fee'             => $request->land_allotment_estimated_amount ?? null,
                    ]);

                    if ($request->service_id == "37") {
                        $this->store_labour_deposits($request->service_id, $application_data, $user_service_application->id);
                    }

                    if ($user_service_application->status !== "draft") {
                        $application_number = $this->generate_application_number($request->service_id, $user_service_application->id);
                    } else {
                        $application_number = null;
                    }

                    $user_service_application->update([
                        'applicationId' => $application_number
                    ]);

                    if ($request->status != 'draft' && $has_approval_flow) {
                        ApplicationWorkflowAssignment::create([
                            'application_id'     => $user_service_application->id,
                            'service_id'         => $request->service_id,
                            'step_number'        => $approval_flow->step_number,
                            'step_type'          => $approval_flow->step_type,
                            'department_id'      => $approval_flow->department_id,
                            'hierarchy_level'    => $approval_flow->hierarchy_level,
                            'assigned_to_group'  => true,
                            'status'             => 'pending',
                            'action_taken_by'    => null,
                            'action_taken_at'    => null,
                            'remarks'            => null,
                        ]);
                    }

                    if (!$has_approval_flow && $status === 'approved') {
                        app(CertificateController::class)->auto_generate_certificate($user_service_application);
                    }

                    DB::commit();

                    if ($status === 'saved' || ($status === 'submitted' && $user_service_application->total_fee == 0)) {
                        $sms = SmsService::buildSmsMessage('application_saved', [
                            'APP_NO' => $user_service_application->applicationId,
                            'DEPT_NAME' => $department_name ?? 'the department',
                        ]);

                        SmsService::send(
                            $user->mobile_no,
                            $sms['message'],
                            $sms['template_id']
                        );
                    }

                    $this->send_application_whatsapp_notification($user, $user_service_application, $service_data, $status, $total_fee, $has_approval_flow);

                    $message = $status === 'draft' ? 'Application saved as draft successfully.' : 'Application created successfully.';

                    return response()->json([
                        'status'  => 1,
                        'message' => $message,
                        'data' => [
                            'id' => $user_service_application->id,
                            'applicationId' => $user_service_application->applicationId,
                            'service_id' => $user_service_application->service_id,
                            'user_id' => $user_service_application->user_id,
                            'status' => $user_service_application->status,
                            'final_fee' => $final_fee,
                            'extra_payment' => $user_service_application->extra_payment,
                            'total_fee' => $total_fee,
                            'current_step_number' => $has_approval_flow ? $approval_flow->step_number : null,
                            'assigned_department_id' => $has_approval_flow ? $approval_flow->department_id : null,
                            'assigned_hierarchy_level' => $has_approval_flow ? $approval_flow->hierarchy_level : null,
                            'max_processing_date' => $max_processing_date->format('Y-m-d'),
                            'payment_status' => $user_service_application->payment_status,
                        ]
                    ], 201);
                }
            } else {
                $this->store_third_party_application($request, $user);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    private function validate_questionnaire_file_inputs(Request $request): void
    {
        $service_id = $request->service_id;

        $file_questions = ServiceQuestionnaire::where('service_id', $service_id)
            ->whereIn('question_type', ['file', 'image'])
            ->where('status', 1)
            ->get(['id', 'question_type', 'validation_rule', 'is_required']);

        if ($file_questions->isEmpty()) {
            return;
        }

        $all_app_data = $request->input('application_data', []);

        $rules = [];
        $existing_application = null;

        if ($request->filled('id')) {
            $existing_application = UserServiceApplication::find($request->id);
        }

        foreach ($file_questions as $question) {
            $field_key = 'application_data.' . $question->id;
            $existing_value = $request->input($field_key);
            $has_file_upload = $request->hasFile($field_key);

            if (!$existing_value && !$has_file_upload) {
                $existing_value = $this->find_nested_value($all_app_data, $question->id);

                if (!$existing_value) {
                    $has_file_upload = $this->has_nested_file_upload($request, $question->id);
                }
            }

            $is_existing_file = is_string($existing_value) && (
                str_starts_with($existing_value, 'uploads/') ||
                str_starts_with($existing_value, 'http://') ||
                str_starts_with($existing_value, 'https://')
            );

            $has_existing_file = false;
            if ($existing_application && $existing_application->application_data) {
                $app_data = is_array($existing_application->application_data)
                    ? $existing_application->application_data
                    : json_decode($existing_application->application_data, true);
                $has_existing_file = $this->find_nested_value($app_data, $question->id) !== null;
            }

            if ($is_existing_file || $has_file_upload || (!$has_file_upload && $has_existing_file)) {
                continue;
            }

            $rule_string = ($question->is_required === 'yes') ? 'required|file' : 'nullable|file';

            $validation_rule = $question->validation_rule ? json_decode($question->validation_rule, true) : [];

            if (!empty($validation_rule['mimes']) && is_array($validation_rule['mimes'])) {
                $rule_string .= '|mimes:' . implode(',', $validation_rule['mimes']);
            }

            $max_mb = (int) ($validation_rule['max_size_mb'] ?? 0);
            if ($max_mb > 0) {
                $rule_string .= '|max:' . ($max_mb * 1024);
            }

            $rules[$field_key] = $rule_string;
        }

        if (!empty($rules)) {
            $request->validate($rules);
        }
    }

    private function find_nested_value($data, $question_id)
    {
        if (!is_array($data)) {
            return null;
        }

        foreach ($data as $key => $value) {
            if ($key == $question_id) {
                return $value;
            }
            if (is_array($value)) {
                $result = $this->find_nested_value($value, $question_id);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    private function has_nested_file_upload($request, $question_id)
    {
        $files = $request->file('application_data', []);
        return $this->find_nested_file($files, $question_id);
    }

    private function find_nested_file($files, $question_id)
    {
        if (!is_array($files)) {
            return false;
        }

        foreach ($files as $key => $value) {
            if ($key == $question_id && $value !== null) {
                return true;
            }
            if (is_array($value)) {
                $result = $this->find_nested_file($value, $question_id);
                if ($result) {
                    return true;
                }
            }
        }

        return false;
    }


    public function user_service_application_update(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id'                   => 'required|integer|exists:user_service_applications,id',
                'service_id'           => 'sometimes|integer|exists:service_masters,id',
                'renewal_cycle_id'     => 'nullable|integer|exists:renewal_cycles,id',
                'renewal'              => 'nullable|in:yes,no',
                'renewalYear'          => 'nullable|integer|min:1|max:10',
                'applicationId'        => 'nullable|string|max:255',
                'application_date'     => 'nullable|date',
                'status'                => 'in:submitted,under_review,approved,rejected,re_submitted,send_back,saved, expired',
                'application_data'     => 'nullable|array',
                'applied_fee'          => 'nullable|numeric',
                'approved_fee'         => 'nullable|numeric',
                'payment_status'       => 'nullable|string',
                'remarks'              => 'nullable|string',
                'NOC_application_date' => 'nullable|date',
                'NOC_expiry_date'      => 'nullable|date',
                'PreviousNOCexpiryDate' => 'nullable|date',
                'payment_transId'      => 'nullable|string|max:255',
                'GRN_number'           => 'nullable|string|max:255',
                'payment_time'         => 'nullable|date',
                'extra_payment'        => 'nullable|numeric',
                'comments'             => 'nullable|string',
                'NOC_certificate'      => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'NOC_rejection_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'NOC_generationDate'   => 'nullable|date',
                'NOC_penalty_amount'   => 'nullable|numeric',
                'NOC_letter_number'    => 'nullable|string|max:255',
                'NOC_letter_date'      => 'nullable|date',
                'NSW_Application_Save_ID' => 'nullable|string|max:255',
                'NSW_license_status'   => 'nullable|in:pending,approved,rejected,expired',
                'NSW_Push_Document_ID' => 'nullable|string|max:255',
                'final_fee'             => 'nullable|string',
                'total_fee'             => 'nullable|string',
                'current_step_number'   => 'nullable|integer',
                'max_processing_date'   => 'nullable|string',
            ]);

            DB::beginTransaction();

            $user_service_application = UserServiceApplication::where('id', $request->id)->first();

            $noc_certificate = null;
            if ($request->hasFile('NOC_certificate')) {
                if ($user_service_application->NOC_certificate && Storage::disk('public')->exists($user_service_application->NOC_certificate)) {
                    Storage::disk('public')->delete($user_service_application->NOC_certificate);
                }
                $file = $request->file('NOC_certificate');
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $noc_certificate = $file->storeAs("uploads/{$user->id}/noc_certificates", $filename, 'public');
            }

            $noc_rejection_certificate = null;
            if ($request->hasFile('NOC_rejection_certificate')) {
                if ($user_service_application->NOC_rejection_certificate && Storage::disk('public')->exists($user_service_application->NOC_rejection_certificate)) {
                    Storage::disk('public')->delete($user_service_application->NOC_rejection_certificate);
                }
                $file = $request->file('NOC_rejection_certificate');
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $noc_rejection_certificate = $file->storeAs("uploads/{$user->id}/noc_rejection_certificates", $filename, 'public');
            }

            $original_application_date = $user_service_application->application_date;
            $original_status = $user_service_application->status;

            $user_service_application->fill($request->except(['id', 'NOC_certificate', 'NOC_rejection_certificate']));

            $existing_data = is_array($user_service_application->application_data)
                ? $user_service_application->application_data
                : json_decode($user_service_application->application_data ?? '[]', true);

            $existing_data = $existing_data ?: [];


            $removed_question_ids = json_decode((string)($request->input('remove_file_question_ids') ?? '[]'), true) ?: [];

            if (!empty($removed_question_ids)) {
                $existing_data = $this->remove_questions_from_application_data($existing_data, $removed_question_ids);
            }

            $new_data = $request->input('application_data', []);

            foreach ($new_data as $key => $value) {
                if (is_numeric($key)) {
                    $key = (string) $key;
                }
                $existing_data[$key] = $value;
            }

            $files = $request->file('application_data', []);

            if (!empty($files)) {
                $existing_data = $this->merge_application_files($existing_data, $files, $user->id);
            }

            $encoded_application_data = json_encode($existing_data ?: null);
            $user_service_application->application_data = $encoded_application_data;



            $application_id =  $user_service_application->id;
            if ($request->service_id == 37) {
                $fee_data = $this->calculate_labour_resubmission_breakdown($request->service_id, $request->application_data, $application_id);
            } else {
                $fee_data = $this->calculate_final_fee($request->service_id, $request->application_data, $application_id);
            }
            $final_fee = $fee_data['final_fee'];
            $blc_fee = $fee_data['effective_fee'];
            $previous_paid = $fee_data['previous_paid'];
            $approval_flow = ServiceApprovalFlow::where('service_id', $request->service_id)
                ->orderBy('step_number', 'asc')
                ->first();

            $current_step = ApplicationWorkflowAssignment::where('application_id', $application_id)
                ->where('step_number', $user_service_application->current_step_number)
                ->latest('id')
                ->first();

            $service_data = ServiceMaster::where('id', $request->service_id ?? $user_service_application->service_id)
                ->first(['target_days']);
            $application_date = Carbon::parse($request->application_date ?? now());
            $target_days = $service_data->target_days ?? 0;
            $max_processing_date = $this->add_working_days($application_date, $target_days);

            if ($user_service_application->extra_payment != null && $user_service_application->payment_status == "pending") {
                if ($request->extra_payment == null || $request->extra_payment != $user_service_application->extra_payment) {
                    return response()->json([
                        'status' => 0,
                        'message' => "An extra payment of Rs. $user_service_application->extra_payment has been raised. Please make the payment."
                    ], 403);
                }
            }

            $total_fee =  (float) $final_fee;
            $previous_paid = (float) $user_service_application->paid_amount ?? 0;
            $effective_fee = max($total_fee - $previous_paid, 0);
            if ($effective_fee < 0) {
                $effective_fee = 0;
            }

            $status = $request->status ?? 'saved';
            $payment_status = $request->payment_status ?? 'pending';
            $paid_amount = null;
            $payment_time = null;

            if ((float) $total_fee === 0.0) {
                $status = 're_submitted';
                $payment_status = 'paid';
                $paid_amount = 0;
                $payment_time = now();
            }

            if ((float) $total_fee === (float) $previous_paid) {
                $status = 're_submitted';
                $payment_status = 'paid';
                $paid_amount = $previous_paid;
                $payment_time = $user_service_application->payment_time;
            }

            $is_resubmission = $user_service_application->status === 'send_back';

            if ($is_resubmission && $user_service_application->max_processing_date) {
                $send_back_assignment = ApplicationWorkflowAssignment::where('application_id', $user_service_application->id)
                    ->where('status', 'send_back')
                    ->latest('action_taken_at')
                    ->first();

                $send_back_at = $send_back_assignment?->action_taken_at ?? $user_service_application->updated_at;
                $days_taken_by_user = (int) Carbon::parse($send_back_at)->diffInDays(now());
                $max_processing_date = Carbon::parse($user_service_application->max_processing_date)->addDays($days_taken_by_user);
            }

            $user_service_application->update([
                'user_id'               => $user->id,
                'service_id'            => $request->service_id,
                'renewal_cycle_id'      => $request->renewal_cycle_id ?? $user_service_application->renewal_cycle_id,
                'renewal'               => $request->renewal ?? $user_service_application->renewal,
                'renewalYear'           => $request->renewalYear ?? $user_service_application->renewalYear,
                // 'applicationId'         => $request->applicationId,
                'application_date'      => in_array($original_status, ['draft', 'saved'])
                    ? ($request->application_date ?? now())
                    : $original_application_date,
                'status'                => $status,
                'application_data'      => $encoded_application_data,
                'applied_fee'           => $request->applied_fee,
                'approved_fee'          => $request->approved_fee,
                'payment_status'        => $payment_status,
                'remarks'               => $request->remarks,
                'NOC_application_date'  => $request->NOC_application_date ?? $user_service_application->NOC_expiry_date,
                'NOC_expiry_date'       => $request->NOC_expiry_date ?? $user_service_application->NOC_expiry_date,
                'PreviousNOCexpiryDate' => $request->PreviousNOCexpiryDate,
                'payment_transId'       => $request->payment_transId ?? $user_service_application->GRN_number,
                'GRN_number'            => $request->GRN_number ?? $user_service_application->GRN_number,
                'payment_time'          => $payment_time,
                'extra_payment'         => $request->extra_payment,
                'comments'              => $request->comments,
                'NOC_certificate'       => $noc_certificate,
                'NOC_rejection_certificate' => $noc_rejection_certificate,
                'NOC_generationDate'    => $request->NOC_generationDate,
                'NOC_penalty_amount'    => $request->NOC_penalty_amount,
                'NOC_letter_number'     => $request->NOC_letter_number,
                'NOC_letter_date'       => $request->NOC_letter_date,
                'NSW_Application_Save_ID' => $request->NSW_Application_Save_ID,
                'NSW_license_status'    => $request->NSW_license_status,
                'NSW_Push_Document_ID'  => $request->NSW_Push_Document_ID,
                'final_fee'             => $final_fee,
                'total_fee'             => $total_fee,
                'effective_fee'         => $effective_fee,
                'current_step_number'   => $is_resubmission
                    ? $user_service_application->current_step_number
                    : ($approval_flow->step_number ?? 0),
                'max_processing_date'   => $max_processing_date,
                'paid_amount'           => $paid_amount ?? $user_service_application->paid_amount,
            ]);

            if ($is_resubmission) {

                $existing_step = ApplicationWorkflowAssignment::where('application_id', $user_service_application->id)
                    ->where('step_number', $user_service_application->current_step_number)
                    ->latest('id')
                    ->first();

                if ($existing_step) {
                    $existing_step->update([
                        'status' => 'pending',
                        'action_taken_by' => null,
                        'action_taken_at' => null,
                        'remarks' => null,
                    ]);
                }
            } else {

                ApplicationWorkflowAssignment::create([
                    'application_id'     => $user_service_application->id,
                    'service_id'         => $request->service_id,
                    'step_number'        => $approval_flow->step_number,
                    'step_type'          => $approval_flow->step_type,
                    'department_id'      => $approval_flow->department_id,
                    'hierarchy_level'    => $approval_flow->hierarchy_level,
                    'assigned_to_group'  => true,
                    'status'             => 'pending',
                    'action_taken_by'    => null,
                    'action_taken_at'    => null,
                    'remarks'            => null,
                ]);
            }

            if ($user_service_application->service_id == "37") {
                $this->update_labour_deposits_latest($user_service_application->service_id, $user_service_application->application_data, $user_service_application->id);
            }

            $user_service_application->logAs($user->user_name . ' updated application', 'Application Updated');

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Application updated successfully.',
                'data' => [
                    'id' => $user_service_application->id,
                    'applicationId' => $user_service_application->applicationId,
                    'service_id' => $user_service_application->service_id,
                    'user_id' => $user_service_application->user_id,
                    'status' => $user_service_application->status,
                    'final_fee' => $final_fee,
                    'current_step_number' => $is_resubmission
                        ? $user_service_application->current_step_number
                        : ($approval_flow->step_number ?? 0),
                    'assigned_department_id' => $is_resubmission
                        ? ($current_step->department_id ?? null)
                        : ($approval_flow->department_id ?? null),
                    'assigned_hierarchy_level' => $is_resubmission
                        ? ($current_step->hierarchy_level ?? null)
                        : ($approval_flow->hierarchy_level ?? null),
                    'max_processing_date' => $max_processing_date->format('Y-m-d'),
                    'payment_status' => $user_service_application->payment_status,
                ]
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
                'line' => $e->getLine()
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function calculate_final_fee($service_id, $application_data, $application_id = null, $request_extra_payment = null)
    {

        $rules = ServiceFeeRule::where('service_id', $service_id)->get();
        $final_fee = 0;
        $minimum_fee = 0;

        foreach ($rules as $rule) {

            if ($rule->fee_type === 'hardcoded') {
                if (!empty($rule->fixed_calculated_fee)) {
                    $final_fee += (float) $rule->fixed_calculated_fee;
                }

                if (!empty($rule->minimum_fee) && $rule->minimum_fee > $minimum_fee) {
                    $minimum_fee = (float) $rule->minimum_fee;
                }

                continue;
            }

            if ($rule->condition_label_question_id) {
                $pre_value = $application_data[$rule->condition_label_question_id] ?? null;

                if ($pre_value === null) {
                    continue;
                }

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pre_value)) {
                    $pre_value = date('md', strtotime($pre_value));
                }

                if (is_numeric($pre_value)) {
                    $pre_value = (float) $pre_value;
                }

                if ($rule->pre_condition_operator === 'between') {

                    $start = (int) $rule->pre_start_value;
                    $end   = (int) $rule->pre_end_value;

                    $pre_match = ($pre_value >= $start && $pre_value <= $end);
                } else {

                    $pre_match = match ($rule->pre_condition_operator) {
                        '='  => $pre_value == $rule->pre_condition_value,
                        '!=' => $pre_value != $rule->pre_condition_value,
                        '<'  => $pre_value <  $rule->pre_condition_value,
                        '<=' => $pre_value <= $rule->pre_condition_value,
                        '>'  => $pre_value >  $rule->pre_condition_value,
                        '>=' => $pre_value >= $rule->pre_condition_value,
                        default => true,
                    };
                }

                if (!$pre_match) {
                    continue;
                }
            }

            $user_answer = $application_data[$rule->question_id] ?? null;
            if ($user_answer === null) continue;

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $user_answer)) {
                $user_answer = date('md', strtotime($user_answer));
            }

            if (is_numeric($user_answer)) {
                $user_answer = (float) $user_answer;
            }

            $match = match ($rule->condition_operator) {
                '='  => $user_answer == $rule->condition_value_start,
                '!=' => $user_answer != $rule->condition_value_start,
                '<'  => $user_answer <  $rule->condition_value_start,
                '<=' => $user_answer <= $rule->condition_value_start,
                '>'  => $user_answer >  $rule->condition_value_start,
                '>=' => $user_answer >= $rule->condition_value_start,
                'between' => $user_answer >= $rule->condition_value_start &&
                    $user_answer <= $rule->condition_value_end,
                default => true,
            };

            if (!$match) continue;
            $temp_fee = 0;

            if (!empty($rule->per_unit_fee)) {
                $temp_fee += $user_answer * (float) $rule->per_unit_fee;
            }

            if (!empty($rule->fixed_calculated_fee)) {
                $temp_fee += (float) $rule->fixed_calculated_fee;
            }

            $final_fee += $temp_fee;

            if (!empty($rule->minimum_fee) && $rule->minimum_fee > $minimum_fee) {
                $minimum_fee = (float) $rule->minimum_fee;
            }
        }

        if ($minimum_fee > 0 && $final_fee < $minimum_fee) {
            return round($minimum_fee, 2);
        }

        $extra_payment = $application_data['extra_payment'] ?? 0;
        if (is_numeric($extra_payment)) {
            $final_fee += (float) $extra_payment;
        }

        $previous_paid = 0;
        $db_extra_payment = 0;
        $effective_fee = 0;
        $late_fee = 0;

        if ($application_id) {
            $existing_application = UserServiceApplication::find($application_id);

            $cycle = RenewalCycle::where('id', $existing_application->renewal_cycle_id)
                ->where('service_id', $existing_application->service_id)
                ->first();

            if ($existing_application) {
                $previous_paid = $existing_application->paid_amount ?? 0;
                $db_extra_payment   = $existing_application->extra_payment ?? 0;

                //  to check application with null data is resubmiting without full payment  -- start
                $application_data_db = is_array($existing_application->application_data)
                    ? $existing_application->application_data
                    : json_decode($existing_application->application_data ?? 'null', true);

                $paid_amount = (float) $existing_application->paid_amount;
                $final_fee_db = (float) $existing_application->final_fee;
                $total_fee_db = (float) $existing_application->final_fee;

                $is_corrupted_paid_case =
                    $existing_application->status === 'send_back' &&
                    $existing_application->payment_status === 'paid' &&
                    $final_fee_db > 0 &&
                    empty($application_data_db) &&
                    $paid_amount <= 0;

                if ($is_corrupted_paid_case) {
                    $existing_application->paid_amount = $final_fee_db;
                    $existing_application->total_fee = $final_fee_db;

                    if (empty($existing_application->current_step_number)) {
                        $last_step = ApplicationWorkflowAssignment::where('application_id', $existing_application->id)
                            ->latest('id')
                            ->first();

                        if ($last_step) {
                            $existing_application->current_step_number = $last_step->step_number;
                        }
                    }
                    $previous_paid = (float) $final_fee_db;
                    $existing_application->save();
                }
                // to check application with null data is resubmiting without full payment  -- end
                $late_fee = $this->calculate_late_fee($existing_application, $cycle, $final_fee);
                $late_fee  = 300;
            }
        }

        $extra_payment = is_numeric($request_extra_payment)
            ? (float)$request_extra_payment
            : (float)$db_extra_payment;

        $total_fee = $final_fee + $extra_payment + $late_fee;



        if (!empty($previous_paid)) {
            $effective_fee = max($total_fee - $previous_paid, 0);
        }

        return [
            'late_fee'      => round($late_fee, 2),
            'final_fee'     => round($total_fee, 2),
            'previous_paid' => round($previous_paid, 2),
            'effective_fee' => round($effective_fee, 2),
            'payable_fee'   => round($effective_fee > 0 ? $effective_fee : 0, 2),
        ];
    }



    public function calculate_fee(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
                'application_data' => 'required|array',
                'application_id' => 'nullable|integer',
                'extra_payment' => 'nullable|integer',
            ]);

            $service_id = $request->service_id;
            $application_data = $request->application_data;
            $application_id = $request->application_id;
            //   $request_extra_payment = $request->extra_payment;

            $extra_payment = UserServiceApplication::where('id', $application_id)->value('extra_payment');

            if ($service_id == 37) {
                if ($application_id) {
                    $data = $this->calculate_labour_resubmission_breakdown($service_id, $application_data, $application_id);
                } else {
                    $data = $this->calculate_labour_fee_breakdown($service_id, $application_data, $application_id);
                }

                return response()->json([
                    'status' => 1,
                    'message' => 'Fee calculated successfully.',
                    'data' => $data
                ]);
            } else {
                $final_fee = $this->calculate_final_fee($service_id, $application_data, $application_id, $extra_payment);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Fee calculated successfully.',
                'data' => array_merge(
                    ['service_id' => $service_id],
                    $final_fee
                )
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to calculate fee.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    function add_working_days(Carbon $startDate, int $workingDays)
    {

        $date = $startDate->copy();

        $addedDays = 0;

        $holidays = Holiday::pluck('holiday_date')->toArray();

        while ($addedDays < $workingDays) {

            $date->addDay();

            $dayOfWeek = $date->dayOfWeek;

            $day = $date->day;

            if (isset($holidays[$date->format('Y-m-d')])) continue;

            if ($dayOfWeek === Carbon::SUNDAY) continue;

            if ($dayOfWeek === Carbon::SATURDAY) {
                $weekOfMonth = ceil($day / 7);
                if ($weekOfMonth === 2 || $weekOfMonth === 4) continue;
            }

            $addedDays++;
        }

        return $date;
    }

    public function user_service_application_view(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service_user_application = UserServiceApplication::where('service_id', $request->service_id)->get();

            if ($service_user_application->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No service user application found for the given service_id.',
                ], 404);
            }

            foreach ($service_user_application as $service) {
                $service->application_data = json_decode($service->application_data, true);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service user application fetched successfully.',
                'data' => $service_user_application,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function user_service_application_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:user_service_applications,id',
            ]);

            DB::beginTransaction();

            $service_user_application = UserServiceApplication::where('id', $request->id)->first();

            if (!$service_user_application) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'User Service application not found.'
                ], 404);
            }

            if (!in_array($service_user_application->status, ['draft', 'saved'])) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Only draft or saved applications can be deleted.'
                ], 403);
            }

            $applicationid = $service_user_application->applicationId;

            ApplicationWorkflowAssignment::where('application_id', $service_user_application->id)->delete();

            $service_user_application->delete();

            $admin = Auth::user();

            $this->logActivity($admin->user_name . ' deleted the application', $service_user_application, User::find($service_user_application->user_id), [
                'application_id' => $service_user_application->applicationId,
            ], 'Admin deleted application');

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'User Service application deleted successfully.',
                'deleted_id' => $applicationid
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function admin_delete_user_service_application(Request $request)
    {
        try {

            $admin = Auth::user();
            if (!$admin) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            if (!in_array($admin->user_type, ['admin', 'support'])) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Only admin can delete applications.'
                ], 403);
            }

            $request->validate([
                'id' => 'required|integer|exists:user_service_applications,id',
            ]);

            DB::beginTransaction();

            $application = UserServiceApplication::find($request->id);

            if (!$application) {
                DB::rollBack();
                return response()->json([
                    'status' => 0,
                    'message' => 'Application not found.'
                ], 404);
            }

            ApplicationWorkflowAssignment::where('application_id', $application->id)->delete();
            ApplicationWorkflowHistory::where('application_id', $application->id)->delete();

            PaymentOrder::whereJsonContains('application_id', $application->id)->each(function ($order) use ($application) {
                $ids = array_values(array_filter(
                    json_decode($order->application_id, true) ?? [],
                    fn($id) => $id !== $application->id
                ));
                $ids ? $order->update(['application_id' => json_encode($ids)]) : $order->delete();
            });

            $application->delete();

            $this->logActivity($admin->user_name . ' deleted the application', $application, User::find($application->user_id), [
                'application_id' => $application->applicationId,
            ], 'Admin deleted application');

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Application deleted successfully.',
                'deleted_id' => $request->id
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function get_all_user_service_applications(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'per_page'  => 'nullable|integer'
            ]);

            $per_page = $request->per_page ?? 10;

            $query = UserServiceApplication::where('user_id', $request->user_id)
                ->with('my_feedback', 'service.department')
                ->orderBy('application_date', 'desc');

            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('application_date', [$request->date_from, $request->date_to]);
            } elseif ($request->filled('date_from')) {
                $query->whereDate('application_date', '>=', $request->date_from);
            } elseif ($request->filled('date_to')) {
                $query->whereDate('application_date', '<=', $request->date_to);
            }

            if ($request->filled('service_id')) {
                $query->where('service_id', $request->service_id);
            }

            if ($request->filled('department_id')) {
                $query->whereHas('service', function ($q) use ($request) {
                    $q->where('department_id', $request->department_id);
                });
            }

            if ($request->filled('application_type')) {
                $query->whereHas('service', function ($q) use ($request) {
                    $q->where('noc_type', $request->application_type);
                });
            }

            $service_user_application = $query->paginate($per_page);

            if ($service_user_application->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No service user applications found for the given user_id.',
                ], 404);
            }

            foreach ($service_user_application as $service) {
                $service->application_data = json_decode($service->application_data, true);
                $latest_workflow = $service->workflow()->latest('updated_at')->first();

                if ($service->status === 'approved') {
                    $appeal_for = 'approved';
                } elseif ($service->status === 'extra_payment') {
                    $appeal_for = 'extra_payment';
                } elseif (!empty($service->max_processing_date) && now()->gt($service->max_processing_date)) {
                    $appeal_for = 'max_processing_date_exceed';
                } else {
                    $appeal_for = null;
                }

                $appeal = $service->appeal;

                if ($appeal) {
                    if ($appeal->status === 'pending') {
                        $appeal_for = 'in_progress';
                    } elseif ($appeal->status === 'rejected') {
                        $appeal_for = 'rejected';
                    } elseif ($appeal->status === 'approved') {
                        $appeal_for = 'your appeal request approved';
                    }
                }

                $response_data[] = [
                    'application_id' => $service->id,
                    'service_id' => $service->service_id,
                    'application_data' => $service->application_data,
                    'service_title_or_description' => $service->service->service_title_or_description ?? null,
                    'application_type' => $service->service->noc_type ?? null,
                    'department' => $service->service->department_id ?? null,
                    'department_name' => $service->service->department->name ?? null,
                    'application_number' => $service->applicationId ?? null,
                    'application_date' => $service->application_date ?? null,
                    'noc_payment_type' => $service->noc_payment_type ?? null,
                    'NOC_expiry_date'  => $service->NOC_expiry_date ?? null,
                    'payment_status'  => $service->payment_status ?? null,
                    'status'  => $service->status ?? null,
                    'renewal_date'  => $service->renewalYear ?? null,
                    'allow_repeat_application' => $service->allow_repeat_application ?? null,
                    'latest_workflow_status' => $latest_workflow?->status ?? null,
                    'service_mode' => $service->service->service_mode ?? null,
                    'already_rated' => $service->my_feedback ? true : false,
                    'rating' => $service->my_feedback->satisfaction ?? null,
                    'feedback_id' => $service->my_feedback->id ?? null,
                    'is_certificate' => $service->NOC_certificate ? true : false,

                    'appeal_for' => $appeal_for
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service user application fetched successfully.',
                'data' => $response_data,
                'pagination' => [
                    'current_page' => $service_user_application->currentPage(),
                    'per_page'     => $service_user_application->count(),
                    'total'        => $service_user_application->total(),
                    'last_page'    => $service_user_application->lastPage(),
                    'next_page_url' => $service_user_application->nextPageUrl(),
                    'prev_page_url' => $service_user_application->previousPageUrl(),
                ]
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function get_details_user_service_applications(Request $request)
    {


        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id'     => 'required|integer|exists:service_masters,id',
                'application_id' => 'required|integer|exists:user_service_applications,id',
            ]);

            $application_ids = is_array($request->application_id)
                ? $request->application_id
                : [$request->application_id];

            $application = UserServiceApplication::where('service_id', $request->service_id)
                ->with(['my_feedback', 'appeal'])
                ->whereIn('id', $application_ids)
                ->first();

            $renewal_details = $this->get_renewal_details($application);

            if (!$application) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Application not found for this service.',
                ], 404);
            }

            $application_data = json_decode($application->application_data, true) ?: [];

            $question_ids = [];
            $this->collect_question_ids_recursive($application_data, $question_ids);
            if (!empty($question_ids)) {
                $file_questions = ServiceQuestionnaire::whereIn('id', $question_ids)
                    ->where('question_type', 'file')
                    ->pluck('id')
                    ->toArray();
                $this->convert_file_urls_recursive($application_data, $file_questions);
            }

            $application->application_data = $application_data;

            $application_data = $application->application_data;

            $history_data = ApplicationWorkflowHistory::where('application_id', $application->id)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($history) {
                    return [
                        'id'              => $history->id,
                        'step_number'     => $history->step_number,
                        'status'          => $history->status,
                        'step_type'       => $history->step_type,
                        'remarks'         => $history->remarks,
                        'status_file'     => $history->status_file ? asset('storage/' . $history->status_file) : null,
                        'action_taken_at' => $history->action_taken_at,
                        'action_taken_by' => optional($history->actionTaker)->authorized_person_name,
                    ];
                });

            $assignment_data = ApplicationWorkflowAssignment::where('application_id', $application->id)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($assignment) {
                    return [
                        'id'              => $assignment->id,
                        'step_number'     => $assignment->step_number,
                        'status'          => $assignment->status,
                        'step_type'       => $assignment->step_type,
                        'remarks'         => $assignment->remarks,
                        'status_file'     => $assignment->status_file ? asset('storage/' . $assignment->status_file) : null,
                        'action_taken_at' => $assignment->action_taken_at,
                        'action_taken_by' => optional($assignment->actionTaker)->authorized_person_name,
                    ];
                });

            if ($application->service && $application->service->service_mode === 'third_party') {

                $third_party_logs = ThirdPartyStatusLog::where('application_id', $application->id)
                    ->orderByDesc('id')
                    ->get()
                    ->map(function ($log) {
                        return [
                            'application_id'  => $log->application_id,
                            'service_status'  => $log->service_status,
                            'application_date' => $log->application_date,
                            'payment_amount'  => $log->payment_amount,
                            'payment_status'  => $log->payment_status,
                            'remarks'         => $log->remarks,
                            'noc_file'        => $log->file ? asset('storage/' . $log->file) : null,
                            'updated_at'      => $log->updated_at,
                        ];
                    });

                if ($third_party_logs->isNotEmpty()) {
                    $history_data = $third_party_logs;
                }
            }

            $payment_details = PaymentOrder::whereJsonContains('application_id', $application->id)
                ->get()
                ->map(function ($p) {
                    return [
                        'id'               => $p->id,
                        'payment_amount'   => $p->payment_amount,
                        'payment_status'   => $p->payment_status,
                        'gateway'          => $p->gateway,
                        'gateway_order_id' => $p->gateway_order_id,
                        'transaction_id'   => $p->transaction_id,
                        'GRN_number'       => $p->GRN_number,
                        'payment_datetime' => $p->payment_datetime,
                        'created_at'       => $p->created_at,
                    ];
                });

            $feedback = $application->my_feedback;

            $feedback_details = $feedback ? [
                'id'          => $feedback->id,
                'satisfaction' => $feedback->satisfaction,
                'feedback'    => $feedback->feedback,
                'suggestions' => $feedback->suggestions,
                'created_at'  => $feedback->created_at,
            ] : null;

            $formatter = new ApplicationDataFormatter();
            $formatted_application_data = $formatter->build_application_view_data($application);

            $appeal_details = null;

            if ($application->appeal) {
                $appeal = $application->appeal;

                $appeal_details = [
                    'appeal_id' => $appeal->id,
                    'status' => $appeal->status,
                    'remarks_from_user' => $appeal->remarks_from_user,
                    'remarks_by_dept' => $appeal->remarks_by_dept,
                    'appeal_file' => $appeal->appeal_file ? asset('storage/' . $appeal->appeal_file) : null,
                    'dept_file' => $appeal->dept_file ? asset('storage/' . $appeal->dept_file) : null,
                    'can_resubmit' => $appeal->status === 'rejected',
                ];
            }

            $user = $application->user;

            $user_details = $user ? [
                'id' => $user->id,
                'name' => $user->authorized_person_name,
                'phone' => $user->mobile_no,
                'email' => $user->email_id,
                'district_code' => $user->district->district_code,
                'district_name' => $user->district->district_name,
                'subdivision_code' => $user->subdivision->sub_lgd_code,
                'subdivision_name' => $user->subdivision->sub_division,
                'ulb_code' => $user->ulb->ulb_lgd_code,
                'ulb_name' => $user->ulb->ulb_name,
                'ward_code' => $user->ward->gp_vc_ward_lgd_code,
                'ward_name' => $user->ward->name_of_gp_vc_or_ward,
            ] : null;

            $application->makeHidden(['user']);

            if ($application->service) {
                $application->service->makeHidden(['renewal_cycles']);
            }

            return response()->json([
                'status'            => 1,
                'message'           => 'Service user application fetched successfully.',
                'data'              => $application,
                'application_data'  => $formatted_application_data,
                'assignment_data'   => $assignment_data,
                'history_data'    => $history_data,
                'service_name'    => $application->service->service_title_or_description,
                'renewal_details' => $renewal_details,
                'payment_details' => $payment_details,
                'feedback_details' => $feedback_details,
                'appeal_details'   => $appeal_details,
                'user'              => $user_details,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function store_third_party_status_logs(Request $request, $user_service_application)
    {

        $request->validate([
            'payment_url'            => 'nullable|string',
            'egras_account_head'     => 'nullable|string',
            'payment_amount'         => 'nullable|string',
        ]);

        $third_party_status_log = ThirdPartyStatusLog::where('application_id', $user_service_application->id)->first();

        if ($third_party_status_log) {

            $third_party_status_log->update([
                'service_id'         => $user_service_application->service_id,
                'application_id'     => $user_service_application->id,
                'swaagat_user_id'    => $user_service_application->user_id,
                'service_status'     => $user_service_application->status,
                'mobile_no'          => $user_service_application->user->mobile_no,
                'application_date'   => $user_service_application->application_date,
                'updation_date'      => $user_service_application->updated_at,
                'action_by'          => $user_service_application->action_taken_by,
                'remark'             => $user_service_application->remarks,
                'payment_amount'     => $request->payment_amount,
                'payment_status'     => $user_service_application->payment_status,
                'payment_url'        => $request->payment_url,
                'egras_account_head' => $request->egras_account_head,
                'noc_url'            => $user_service_application->NOC_certificate,
                'noc_file'           => $user_service_application->NOC_certificate,
            ]);
        } else {

            ThirdPartyStatusLog::create([
                'service_id'        => $user_service_application->service_id,
                'application_id'     => $user_service_application->id,
                'swaagat_user_id'    => $user_service_application->user_id,
                'service_status'     => $user_service_application->status,
                'mobile_no'          => $user_service_application->user->mobile_no,
                'application_date'   => $user_service_application->application_date,
                'updation_date'      => $user_service_application->updated_at,
                'action_by'          => $user_service_application->action_taken_by,
                'remark'             => $user_service_application->remarks,
                'payment_amount'     => $request->payment_amount,
                'payment_status'     => $user_service_application->payment_status,
                'payment_url'        => $request->payment_url,
                'egras_account_head' => $request->egras_account_head,
                'noc_url'            => $user_service_application->NOC_certificate,
                'noc_file'           => $user_service_application->NOC_certificate,
            ]);
        }
    }

    public function store_third_party_application($request, $user)
    {
        $application_date = Carbon::parse($request->application_date ?? now());
        $target_days = $service->target_days ?? 0;
        $max_processing_date = $this->add_working_days($application_date, $target_days);

        $user_service_application = UserServiceApplication::where('user_id', $user->id)
            ->where('service_id', $request->service_id)
            ->first();

        if ($user_service_application) {

            $user_service_application->update([
                'renewal_cycle_id'      => $request->renewal_cycle_id,
                'renewal'               => $request->renewal,
                'renewalYear'           => $request->renewalYear,
                'applicationId'         => $request->applicationId,
                'application_date'      => $request->application_date ?? now(),
                'status'                => $request->status ?? 'submitted',
                'application_data'      => json_encode($request->application_data ?? null),
                'applied_fee'           => $request->applied_fee,
                'approved_fee'          => $request->approved_fee,
                'payment_status'        => $request->payment_status ?? 'pending',
                'remarks'               => $request->remarks,
                'NOC_application_date'  => $request->NOC_application_date,
                'NOC_expiry_date'       => $request->NOC_expiry_date,
                'PreviousNOCexpiryDate' => $request->PreviousNOCexpiryDate,
                'payment_transId'       => $request->payment_transId,
                'GRN_number'            => $request->GRN_number,
                'payment_time'          => $request->payment_time,
                'extra_payment'         => $request->extra_payment,
                'comments'              => $request->comments,
                'NOC_certificate'       =>  $user_service_application->NOC_certificate,
                'NOC_rejection_certificate' =>  $user_service_application->NOC_rejection_certificate,
                'NOC_generationDate'    => $request->NOC_generationDate,
                'NOC_penalty_amount'    => $request->NOC_penalty_amount,
                'NOC_letter_number'     => $request->NOC_letter_number,
                'NOC_letter_date'       => $request->NOC_letter_date,
                'NSW_Application_Save_ID' => $request->NSW_Application_Save_ID,
                'NSW_license_status'    => $request->NSW_license_status,
                'NSW_Push_Document_ID'  => $request->NSW_Push_Document_ID,
                'current_step_number'   => $approval_flow->step_number ?? null,
                'max_processing_date'   => $max_processing_date,

                'external_application_id'   => $request->external_application_id,
                'external_status'   => $request->external_status,
                'external_payment_status'   => $request->external_payment_status,
                'external_max_processing_date'   => $request->external_max_processing_date,
                'external_noc_number'   => $request->external_noc_number,
                'external_valid_till'   => $request->external_valid_till,
                'external_remarks'   => $request->external_remarks,
                'is_third_party'   => 1,
            ]);

            $this->store_third_party_status_logs($request, $user_service_application);

            DB::commit();

            ApplicationWorkflowHistory::where('application_id', $user_service_application->id)
                ->update([
                    'status'          => $request->external_payment_status,
                    'remarks'         => $request->remarks,
                    'action_taken_at' => now(),
                ]);


            ApplicationWorkflowHistory::create([
                'application_id'            =>  $user_service_application->id,
                'service_id'                =>  $user_service_application->service_id,
                'external_status'           =>  $user_service_application->external_status,
                'external_payment_amount'   =>  $request->external_payment_amount,
                'external_payment_status'   =>  $request->external_payment_status,
                'external_noc_url'          =>  $user_service_application->external_noc_url,
                'external_noc_file'         =>  $user_service_application->external_noc_file,
                'source'                    =>  "third_party",
                'action_taken_at' => now(),
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Third-party application data stored successfully.',
                'data' => [
                    "id" => $user_service_application->applicationId,
                    "service_id" => $user_service_application->service_id,
                    'external_application_id' => $request->external_application_id,
                    'status' => $request->external_status,
                    'max_processing_date' => $request->external_max_processing_date,
                    'payment_status' => $request->external_payment_status,
                    'bin' => $user_service_application->user->bin,
                ]
            ], 200);
        } else {

            $user_service_application = UserServiceApplication::create([
                'user_id'               => $user->id,
                'service_id'            => $request->service_id,
                'renewal_cycle_id'      => $request->renewal_cycle_id,
                'renewal'               => $request->renewal,
                'renewalYear'           => $request->renewalYear,
                'applicationId'         => $request->applicationId,
                'application_date'      => $request->application_date ?? now(),
                'status'                => $request->status ?? 'saved',
                'application_data'      => json_encode($request->application_data ?? null),
                'applied_fee'           => $request->applied_fee,
                'approved_fee'          => $request->approved_fee,
                'payment_status'        => $request->payment_status ?? 'pending',
                'remarks'               => $request->remarks,
                'NOC_application_date'  => $request->NOC_application_date,
                'NOC_expiry_date'       => $request->NOC_expiry_date,
                'PreviousNOCexpiryDate' => $request->PreviousNOCexpiryDate,
                'payment_transId'       => $request->payment_transId,
                'GRN_number'            => $request->GRN_number,
                'payment_time'          => $request->payment_time,
                'extra_payment'         => $request->extra_payment,
                'comments'              => $request->comments,
                'NOC_certificate'       => null,
                'NOC_rejection_certificate' => null,
                'NOC_generationDate'    => $request->NOC_generationDate,
                'NOC_penalty_amount'    => $request->NOC_penalty_amount,
                'NOC_letter_number'     => $request->NOC_letter_number,
                'NOC_letter_date'       => $request->NOC_letter_date,
                'NSW_Application_Save_ID' => $request->NSW_Application_Save_ID,
                'NSW_license_status'    => $request->NSW_license_status,
                'NSW_Push_Document_ID'  => $request->NSW_Push_Document_ID,
                'max_processing_date'   => $max_processing_date,

                'external_application_id'   => $request->external_application_id,
                'external_status'   => $request->external_status,
                'external_payment_status'   => $request->external_payment_status,
                'external_max_processing_date'   => $request->external_max_processing_date,
                'external_noc_number'   => $request->external_noc_number,
                'external_valid_till'   => $request->external_valid_till,
                'external_remarks'   => $request->external_remarks,
                'is_third_party'   => $request->is_third_party,
            ]);

            $this->store_third_party_status_logs($request, $user_service_application);

            DB::commit();

            ApplicationWorkflowHistory::create([
                'application_id'            =>  $user_service_application->id,
                'service_id'                =>  $request->service_id,
                'external_status'           =>  $user_service_application->external_status,
                'external_payment_amount'   =>  $request->external_payment_amount,
                'external_payment_status'   =>  $user_service_application->external_payment_status,
                'external_noc_url'          =>  $request->external_noc_url,
                'external_noc_file'         =>  $request->external_noc_file,
                'source'                    =>  "third_party",
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Redirect to third-party portal.',
                'data' => [
                    'applicationId' => $user_service_application->applicationId ?? null,
                    'redirect_url' => $user_service_application->service->redirect_url ?? null,
                    'params'  => [
                        'swaagat_user_id' => $user_service_application->user_id,
                        'bin'             => $user_service_application->user->bin,
                        'mobile_no'             => $user_service_application->user->mobile_no,
                        'email'             => $user_service_application->user->email_id,
                    ]
                ]
            ], 200);
        }
    }

    public function get_user_applications_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service_user_application = UserServiceApplication::where('user_id', $request->user_id)
                ->where('service_id', $request->service_id)
                ->orderBy('id', 'desc')
                ->get();

            if ($service_user_application->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No service user applications found for the given user_id.',
                ], 404);
            }

            foreach ($service_user_application as $service) {
                $service->application_data = json_decode($service->application_data, true);
                $latest_workflow = $service->workflow()->latest('updated_at')->first();

                $response_data[] = [
                    'application_id' => $service->id,
                    'application_data' => $service->application_data,
                    'service_title_or_description' => $service->service->service_title_or_description ?? null,
                    'application_type' => $service->service->noc_type ?? null,
                    'department' => $service->service->department_id ?? null,
                    'department_name' => $service->service->department->name ?? null,
                    'application_number' => $service->applicationId ?? null,
                    'application_date' => $service->application_date ?? null,
                    'noc_payment_type' => $service->noc_payment_type ?? null,
                    'NOC_expiry_date'  => $service->NOC_expiry_date ?? null,
                    'payment_status'  => $service->payment_status ?? null,
                    'status'  => $service->status ?? null,
                    'renewal_date'  => $service->renewalYear ?? null,
                    'allow_repeat_application' => $service->allow_repeat_application ?? null,
                    'latest_workflow_status' => $latest_workflow?->status ?? null,
                    'service_mode' => $service->service->service_mode ?? null,
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service user application fetched successfully.',
                'data' => $response_data
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function third_party_return(Request $request)
    {
        Log::info("Third party return called" . json_encode($request->all()));

        try {
            $request->validate([
                'applicationId'        => 'required|string',
                'status'               => 'required|string|in:draft,submitted,under_review,approved,rejected,re_submitted,send_back,saved,expired,pending,noc_issued,extra_payment',
                'payment_status'       => 'nullable|string|in:pending,paid,failed,initiated,success',
                'max_processing_date'  => 'nullable|date',
                'noc_number'           => 'nullable|string|max:255',
                'noc_valid_till'       => 'nullable|date',
                'remarks'              => 'nullable|string',
                'service_id'           => 'required|integer|exists:service_masters,id',
                'user_id'              => 'required|integer|exists:users,id',
                'approved_fee'         => 'nullable|numeric|min:0',
                'extra_payment'        => 'nullable|numeric|min:0',
                'application_date'     => 'nullable|date',
                'updation_date'        => 'nullable|date',
                'egras_account_head'   => 'nullable|string|max:255',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $raw_payment_status = $request->payment_status;
        $normalized_payment_status = match ($raw_payment_status) {
            'success'          => 'paid',
            'initiated'        => 'pending',
            default            => $raw_payment_status,
        };

        $external_id         = $request->input('applicationId');
        $max_processing_date = $request->input('max_processing_date');
        $noc_number          = $request->input('noc_number');
        $noc_valid_till      = $request->input('noc_valid_till');
        $remarks             = $request->input('remarks');
        $service_id          = $request->input('service_id');
        $user_id             = $request->input('user_id');
        $approved_fee        = $request->input('approved_fee');
        $extra_payment       = $request->input('extra_payment');
        $application_date    = $request->input('application_date');
        $updation_date       = $request->input('updation_date');
        $egras_account_head  = $request->input('egras_account_head');
        $status              = $this->map_to_application_status($request->input('status'));
        DB::beginTransaction();

        try {
            $data = UserServiceApplication::where('external_application_id', $external_id)->first();

            if ($data) {
                $data->update([
                    'status'              => $status,
                    'payment_status'      => $normalized_payment_status ?? $data->payment_status,
                    'max_processing_date' => $max_processing_date ?? $data->max_processing_date,
                    'license_id'          => $noc_number ?? $data->noc_number,
                    'NOC_expiry_date'     => $noc_valid_till ?? $data->noc_valid_till,
                    'remarks'             => $remarks ?? $data->remarks,
                    'approved_fee'        => $approved_fee ?? $data->approved_fee,
                    'total_fee'           => $approved_fee ?? $data->approved_fee,
                    'extra_payment'       => $extra_payment ?? $data->extra_payment,

                    'external_status'              => $status,
                    'external_payment_status'      => $normalized_payment_status ?? $data->external_payment_status,
                    'external_max_processing_date' => $max_processing_date ?? $data->external_max_processing_date,
                    'external_noc_number'          => $noc_number ?? $data->external_noc_number,
                    'external_valid_till'          => $noc_valid_till ?? $data->external_valid_till,
                    'external_remarks'             => $remarks ?? $data->external_remarks,
                    'egras_scheme_code'            => $egras_account_head,
                ]);
            } else {

                $data = UserServiceApplication::create([
                    'user_id'                 => $user_id,
                    'service_id'              => $service_id,
                    'external_application_id' => $external_id,
                    'applicationId'           => $external_id,
                    'status'                  => $status,
                    'payment_status'          => $normalized_payment_status ?? 'pending',
                    'max_processing_date'     => $max_processing_date,
                    'license_id'              => $noc_number,
                    'NOC_expiry_date'         => $noc_valid_till,
                    'remarks'                 => $remarks,
                    // 'bin'                  => $request->input('bin'), // no column named bin in user_service_applications
                    'approved_fee'            => $approved_fee,
                    'total_fee'               => $approved_fee,
                    'extra_payment'           => $extra_payment ?? null,

                    'external_status'              => $status,
                    'external_payment_status'      => $normalized_payment_status ?? 'pending',
                    'external_max_processing_date' => $max_processing_date,
                    'external_noc_number'          => $noc_number,
                    'external_valid_till'          => $noc_valid_till,
                    'external_remarks'             => $remarks,
                    'is_third_party'               => 1,
                    'egras_scheme_code'            => $egras_account_head,
                ]);
            }

            if ((float) $approved_fee > 0) {
                $payment_order = PaymentOrder::where('user_id', $user_id)
                    ->where('application_id', json_encode([$data->id]))
                    ->first();

                if (!$payment_order) {
                    $payment_order = PaymentOrder::create([
                        'user_id'            => $user_id,
                        'application_id'     => json_encode([$data->id]),
                        'payment_amount'     => $approved_fee,
                        'payment_created_on' => now(),
                        'payment_updated_on' => now(),
                        'payment_status'     => 'pending',
                        'transaction_id'     => null,
                    ]);
                } else {
                    $payment_order->update([
                        'payment_created_on' => now(),
                        'payment_updated_on' => now(),
                        'payment_amount'     => $approved_fee > 0 ? $approved_fee : $payment_order->payment_amount,
                        'payment_status'     => $normalized_payment_status,
                        'transaction_id'     => $request->transaction_id ?? $payment_order->transaction_id,
                    ]);
                }
            }

            DB::commit();

            $user_obj = User::find($user_id);
            if ($user_obj) {
                $this->logActivity('Third party application callback', $data, $user_obj, [
                    'external_application_id' => $external_id,
                    'status'                  => $status,
                    'payment_status'          => $normalized_payment_status,
                ], 'Third party callback');
            }

            try {
                ThirdPartyStatusLog::create([
                    'service_id'         => $service_id,
                    'application_id'     => $external_id,
                    'swaagat_user_id'    => $user_id,
                    'service_status'     => $status,
                    'mobile_no'          => $request->input('mobile_no'),
                    'application_date'   => $application_date,
                    'updation_date'      => $updation_date,
                    'action_by'          => $request->input('action_by'),
                    'remark'             => $remarks,
                    'payment_amount'     => $approved_fee,
                    'payment_status'     => $normalized_payment_status, // pending/paid/failed
                    'payment_url'        => $request->input('payment_url'),
                    'egras_account_head' => $request->input('egras_account_head'),
                    'noc_url'            => $request->input('noc_url'),
                    'noc_file'           => $request->input('noc_file'),
                ]);
            } catch (\Exception $e) {
                // ignore
            }

            // $redirectUrl = config('app.app_frontendurl') . "/dashboard/user-app-view/{$service_id}/{$data->id}?service=third_party";            // return redirect()->away($redirectUrl);
            // return redirect()->away($redirectUrl);
            return response()->json([
                'success' => 1,
                'message' => 'Callback processed successfully',
                // 'data'    => $data,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => 0,
                'message' => 'Failed to process callback',
                'error'   => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }


    public function update_third_party_status_log(Request $request)
    {
        try {

            $request->validate([
                'application_id'       => 'required|string',
                'swaagat_user_id'      => 'required|integer',
                'service_id'           => 'required|integer',
                'service_status'       => 'required|string',
                'mobile_no'            => 'required|string',
                'application_date'     => 'required|date',
                'updation_date'        => 'required|date',
                'action_by'            => 'nullable|string',
                'remark'               => 'nullable|string',
                'payment_amount'       => 'nullable',
                'payment_status'       => 'nullable|string',
                'payment_url'          => 'nullable|string',
                'egras_account_head'   => 'nullable|string',
                'noc_url'              => 'nullable|string',
                'noc_file'             => 'nullable|string',
                'transaction_id'       => 'nullable|string',
            ]);

            $incoming_service_status = strtolower((string) $request->service_status);

            if ($incoming_service_status === 'approved') {
                $application_status = 'approved';
            } elseif ($incoming_service_status === 'pending') {
                $application_status = 'submitted';
            } elseif ($incoming_service_status === 'Completed') {
                $application_status = 'noc_issued';
            } else {
                $application_status = 'under_review';
            }

            $raw_payment_status = $request->payment_status;
            $normalized_payment_status = match ($raw_payment_status) {
                'success'          => 'paid',
                'initiated'        => 'pending',
                default            => $raw_payment_status,
            };

            $application = UserServiceApplication::where('external_application_id', $request->application_id)->first();

            if ($application) {
                $application->update([
                    'status'                  => $application_status,
                    'payment_status'          => $normalized_payment_status ?? $application->payment_status,
                    'external_application_id' => $request->application_id,
                    'external_status'         => $request->service_status,
                    'external_payment_status' => $external_payment_status ?? $application->external_payment_status,
                    'external_remarks'        => $request->remark,
                    'NOC_certificate'         => $request->noc_url,
                    'egras_scheme_code'       => $request->egras_account_head,
                ]);
            }

            if ($application) {

                ThirdPartyStatusLog::create([
                    'service_id'         => $request->service_id,
                    'application_id'     => $application->id,
                    'swaagat_user_id'    => $request->swaagat_user_id,
                    'service_status'     => $request->service_status,
                    'mobile_no'          => $request->mobile_no,
                    'application_date'   => $request->application_date,
                    'updation_date'      => $request->updation_date,
                    'action_by'          => $request->action_by,
                    'remark'             => $request->remark,
                    'payment_amount'     => $request->payment_amount,
                    'payment_status'     => $normalized_payment_status,
                    'payment_url'        => $request->payment_url,
                    'egras_account_head' => $request->egras_account_head,
                    'noc_url'            => $request->noc_url,
                    'noc_file'           => $request->noc_file,
                ]);

                $app_json = json_encode([$application->id]);
                $amount  = (float) ($request->payment_amount ?? 0);

                $payment_order = PaymentOrder::where('user_id', $request->swaagat_user_id)
                    ->where('application_id', $app_json)
                    ->first();

                if ($request->payment_status === 'pending' && $amount > 0) {

                    if (!$payment_order) {
                        $payment_order = PaymentOrder::create([
                            'user_id'            => $request->swaagat_user_id,
                            'application_id'     => $app_json,
                            'payment_amount'     => $amount,
                            'payment_created_on' => now(),
                            'payment_updated_on' => now(),
                            'payment_status'     => 'pending',
                            'transaction_id'     => $request->transaction_id ?? null,
                        ]);

                        $payment_order->update([
                            'order_id' => 'SW' . $payment_order->id
                        ]);
                    }
                } else {

                    if ($payment_order) {
                        $payment_order->update([
                            'payment_amount'     => $amount > 0 ? $amount : $payment_order->payment_amount,
                            'payment_updated_on' => now(),
                            'payment_status'     => $normalized_payment_status,
                            'transaction_id'     => $request->transaction_id ?? $payment_order->transaction_id,
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'Status log saved successfully',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'success' => 0,
                'message' => 'Validation Error',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'success' => 0,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
                'line'    => $e->getLine()
            ], 500);
        }
    }

    private function map_to_application_status(?string $status): string
    {
        $valid = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 're_submitted', 'send_back', 'saved', 'expired', 'noc_issued', 'extra_payment'];
        $status = strtolower(trim((string) $status));
        return in_array($status, $valid) ? $status : 'submitted';
    }


    public function get_all_applications_list(Request $request)
    {


        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $per_page = $request->per_page ?? 10;
            $query = UserServiceApplication::with('user')->orderBy('id', 'DESC');

            if ($request->search) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('applicationId', 'LIKE', "%{$search}%")
                        ->orWhereHas('user', function ($u) use ($search) {
                            $u->where('name_of_enterprise', 'LIKE', "%{$search}%")
                                ->orWhere('email_id', 'LIKE', "%{$search}%");
                        });
                });
            }

            if ($request->GRN_number) {
                $query->where('GRN_number', 'LIKE', "%{$request->GRN_number}%");
            }

            if ($request->mobile_no) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('mobile_no', 'LIKE', "%{$request->mobile_no}%");
                });
            }

            if ($request->status) {
                $query->where('payment_status', $request->status);
            }

            if ($request->min_amount) {
                $query->whereRaw("COALESCE(effective_fee, total_fee) >= ?", [$request->min_amount]);
            }

            if ($request->max_amount) {
                $query->whereRaw("COALESCE(effective_fee, total_fee) <= ?", [$request->max_amount]);
            }

            if ($request->from_date) {
                $query->whereDate('payment_time', '>=', $request->from_date);
            }

            if ($request->to_date) {
                $query->whereDate('payment_time', '<=', $request->to_date);
            }

            if ($request->export && $request->export === 'excel') {

                $exportApps = $query->get();

                $exportData = [];

                foreach ($exportApps as $app) {
                    $amount = !empty($app->effective_fee)
                        ? $app->effective_fee
                        : ($app->total_fee ?? 0);

                    $exportData[] = [
                        'id'                 => $app->id,
                        'application_number' => $app->applicationId,
                        'business'           => $app->user->name_of_enterprise ?? null,
                        'email_id'           => $app->user->email_id ?? null,
                        'mobile_no'          => $app->user->mobile_no ?? null,
                        'amount'             => $amount,
                        'payment_time'       => $app->payment_time ?? null,
                        'expiry_date'        => $app->NOC_expiry_date ?? null,
                        'payment_status'     => $app->payment_status,
                        'GRN_number'         => $app->GRN_number,
                        'method'             => null,
                        'comments'           => $app->comments,
                    ];
                }

                return Excel::download(new ApplicationsExport($exportData), 'applications.xlsx');
            }

            $applications = $query->paginate($per_page);

            if ($applications->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No applications found.',
                ], 404);
            }

            $application_ids = $applications->pluck('id')->toArray();
            $payment_map = $this->payment_map_for_applications($application_ids);

            $response_data = [];

            foreach ($applications as $app) {
                $amount = !empty($app->effective_fee)
                    ? $app->effective_fee
                    : ($app->total_fee ?? 0);

                $response_data[] = [
                    'id'                 => $app->id,
                    'application_number' => $app->applicationId,
                    'business'           => $app->user->name_of_enterprise ?? null,
                    'email_id'           => $app->user->email_id ?? null,
                    'mobile_no'          => $app->user->mobile_no ?? null,
                    'amount'             => $amount,
                    'payment_time'       => $app->payment_time ?? null,
                    'expiry_date'        => $app->NOC_expiry_date ?? null,
                    'status'             => $app->payment_status,
                    'GRN_number'         => $app->GRN_number,
                    'method'             => null,
                    'comments'           => $app->comments,
                    'payment_details'    => $payment_map[$app->id] ?? [],
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Applications fetched successfully.',
                'data' => $response_data,
                'pagination' => [
                    'current_page' => $applications->currentPage(),
                    'last_page'    => $applications->lastPage(),
                    'per_page'     => $applications->count(),
                    'total'        => $applications->total(),
                ]
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function export_filtered_applications(Request $request)
    {


        try {

            $query = UserServiceApplication::query()->with('user');

            if ($request->search) {
                $search = $request->search;

                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name_of_enterprise', 'LIKE', "%$search%")
                        ->orWhere('email_id', 'LIKE', "%$search%");
                });
            }

            if ($request->grn_number) {
                $query->where('applicationId', 'LIKE', "%{$request->grn_number}%");
            }

            if ($request->mobile_no) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('mobile_no', 'LIKE', "%{$request->mobile_no}%");
                });
            }

            if ($request->status) {
                $query->where('payment_status', $request->status);
            }

            if ($request->min_amount) {
                $query->whereRaw("COALESCE(effective_fee, total_fee) >= ?", [$request->min_amount]);
            }

            if ($request->max_amount) {
                $query->whereRaw("COALESCE(effective_fee, total_fee) <= ?", [$request->max_amount]);
            }

            if ($request->from_date) {
                $query->whereDate('payment_time', '>=', $request->from_date);
            }

            if ($request->to_date) {
                $query->whereDate('payment_time', '<=', $request->to_date);
            }

            $applications = $query->orderBy('id', 'DESC')->get();

            return Excel::download(new ApplicationsExport($applications), 'filtered_applications.xlsx');
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Export failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function export_all_applications()
    {
        $applications = UserServiceApplication::with('user')
            ->orderBy('id', 'DESC')
            ->get();

        return Excel::download(new ApplicationsExport($applications), 'all_applications.xlsx');
    }


    public function mark_application_paid(Request $request)
    {


        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'application_id' => 'required|integer|exists:user_service_applications,id',
                'GRN_number'     => 'required|string',
                'comments'       => 'nullable|string',
            ]);

            $application = UserServiceApplication::where('id', $request->application_id)->first();

            if ($application->payment_status === 'paid') {
                return response()->json([
                    'status' => 0,
                    'message' => 'This application is already marked as paid.',
                ], 400);
            }

            $effective_fee = $application->effective_fee;
            $total_fee     = $application->total_fee ?? 0;

            $current_payment = !empty($effective_fee) ? $effective_fee : $total_fee;
            $previous_paid = (float) $application->paid_amount ?? 0;
            $final_paid_amount = $previous_paid + $current_payment;
            $is_extra_payment = $application->status === 'extra_payment';

            if ($is_extra_payment) {
                $status = 're_submitted';
            } elseif ($previous_paid > 0) {
                $status = 're_submitted';
            } else {
                $status = $application->current_step_number == 0 ? 'approved' : 'submitted';
            }

            $application->update([
                'GRN_number'     => $request->GRN_number,
                'comments'       => $request->comments,
                'payment_status' => 'paid',
                'payment_time'   => now(),
                'paid_amount'    => $final_paid_amount,
                'status'         => $status,
            ]);

            if ($is_extra_payment) {

                $current_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                    ->where('step_number', $application->current_step_number)
                    ->latest('id')
                    ->first();

                if ($current_step) {
                    $current_step->update([
                        'status' => 'pending',
                        'action_taken_by' => null,
                        'action_taken_at' => null,
                        'remarks' => null,
                    ]);
                }
            }

            if ($application->service_id == 37) {
                LabourDeposit::where('application_id', $application->id)
                    ->update([
                        'payment_status' => 'paid',
                        'grn_number'     => $request->GRN_number,
                        'payment_time'   => now(),
                    ]);
            }

            PaymentOrder::create([
                'application_id'    => json_encode([(int) $request->application_id]),
                'user_id'           => $application->user_id,
                'payment_status'    => 'paid',
                'payment_amount'    => $current_payment,
                'gateway'           => 'offline',
                'GRN_number'        => $request->GRN_number,
                'payment_datetime'  => now(),
                'gateway_response'  => null,
                'updated_at' => now()
            ]);

            $admin = Auth::user();
            $application->logAs($admin->user_name . ' marked application as paid', 'Admin Marked Paid');

            return response()->json([
                'status' => 1,
                'message' => 'Application marked as paid successfully.',
                'data' => [
                    'application_id' => $application->id,
                    'payment_status' => $application->payment_status,
                    'GRN_number'     => $application->GRN_number,
                    'comments'       => $application->comments,
                    'payment_time'   => $application->payment_time,
                    'paid_amount'    => $final_paid_amount,
                    'status'         => $status
                ]
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function get_renewal_details($application)
    {
        $service = $application->service;

        if (!empty($service->noc_validity) && !empty($application->NOC_expiry_date)) {
            $expiry_date = Carbon::parse($application->NOC_expiry_date);
        } elseif (!empty($application->previous_application_id)) {
            $previous_app = UserServiceApplication::find($application->previous_application_id);

            if ($previous_app && !empty($previous_app->NOC_expiry_date)) {
                $expiry_date = Carbon::parse($previous_app->NOC_expiry_date);
            }
        } elseif (!empty($service->fixed_expiry_date)) {
            $expiry_date = Carbon::parse($service->fixed_expiry_date);
        } else {
            $expiry_date = null;
        }

        $today = Carbon::today();
        $renewal_data = [];
        $renewal_cycles = $service->renewalCycles;

        foreach ($renewal_cycles as $cycle) {

            $renewal_start = null;
            $renewal_end = null;

            if (!empty($cycle->fixed_renewal_start_date) && !empty($cycle->fixed_renewal_end_date)) {
                $renewal_start = Carbon::parse($cycle->fixed_renewal_start_date);
                $renewal_end   = Carbon::parse($cycle->fixed_renewal_end_date);
            } else {

                if (!empty($cycle->renewal_window_days) && $expiry_date) {
                    $window_days = (int)$cycle->renewal_window_days;

                    if ($window_days >= 0) {
                        $renewal_start = $expiry_date->copy()->subDays($window_days);
                        if ($renewal_end === null) {
                            $renewal_end = $expiry_date->copy();
                        }
                    } else {
                        $renewal_start = $expiry_date->copy()->addDays(abs($window_days));
                        if ($renewal_end === null) {
                            $renewal_end = $expiry_date->copy();
                        }
                    }
                }

                if (!empty($cycle->renewal_target_days) && $expiry_date) {
                    $target_days = (int)$cycle->renewal_target_days;

                    if ($target_days >= 0) {
                        if ($renewal_start === null) {
                            $renewal_start = $expiry_date->copy();
                        }
                        $renewal_end = $expiry_date->copy()->addDays($target_days);
                    } else {
                        if ($renewal_start === null) {
                            $renewal_start = $expiry_date->copy();
                        }
                        $renewal_end = $expiry_date->copy()->subDays(abs($target_days));
                    }
                }
            }
            $can_renew = false;

            if ($renewal_start && $renewal_end) {
                if ($today->between($renewal_start, $renewal_end)) {
                    $can_renew = true;
                }
            }

            $renewal_data[] = [
                'renewal_cycle_id'   => $cycle->id,
                'renewal_title'      => $cycle->renewal_title,
                'can_renew'          => $can_renew,
                'renewal_start_date' => optional($renewal_start)->toDateString(),
                'renewal_end_date'   => optional($renewal_end)->toDateString(),
                'post_days'          => $cycle->post_days,
            ];
        }

        return [
            'expiry_date'    => optional($expiry_date)->toDateString(),
            'renewal_cycles' => $renewal_data,
        ];
    }


    public function calculate_renewal_final_fee($service_id, $application, $application_data, $cycle)
    {

        $rules = RenewalFeeRule::where('service_id', $service_id)->get();

        if ($service_id == 37) {
            return $this->calculate_labour_renewal_fee($application, $application_data, $cycle);
        }

        $final_fee = 0;
        $minimum_fee = 0;

        foreach ($rules as $rule) {

            if ($rule->fee_type === 'hardcoded') {

                if (!empty($rule->fixed_calculated_fee)) {
                    $final_fee += (float) $rule->fixed_calculated_fee;
                }

                if (!empty($rule->minimum_fee) && $rule->minimum_fee > $minimum_fee) {
                    $minimum_fee = (float) $rule->minimum_fee;
                }

                continue;
            }

            if ($rule->condition_label_question_id) {
                $pre_value = $application_data[$rule->condition_label_question_id] ?? null;

                if ($pre_value === null) {
                    continue;
                }

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pre_value)) {
                    $pre_value = date('md', strtotime($pre_value));
                }

                if (is_numeric($pre_value)) {
                    $pre_value = (float) $pre_value;
                }

                if ($rule->pre_condition_operator === 'between') {

                    $start = (int) $rule->pre_start_value;
                    $end   = (int) $rule->pre_end_value;

                    $pre_match = ($pre_value >= $start && $pre_value <= $end);
                } else {

                    $pre_match = match ($rule->pre_condition_operator) {
                        '='  => $pre_value == $rule->pre_condition_value,
                        '!=' => $pre_value != $rule->pre_condition_value,
                        '<'  => $pre_value <  $rule->pre_condition_value,
                        '<=' => $pre_value <= $rule->pre_condition_value,
                        '>'  => $pre_value >  $rule->pre_condition_value,
                        '>=' => $pre_value >= $rule->pre_condition_value,
                        default => true,
                    };
                }

                if (!$pre_match) {
                    continue;
                }
            }

            $user_answer = $application_data[$rule->question_id] ?? null;
            if ($user_answer === null) continue;

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $user_answer)) {
                $user_answer = date('md', strtotime($user_answer));
            }

            if (is_numeric($user_answer)) {
                $user_answer = (float) $user_answer;
            }

            $match = match ($rule->condition_operator) {
                '='  => $user_answer == $rule->condition_value_start,
                '!=' => $user_answer != $rule->condition_value_start,
                '<'  => $user_answer <  $rule->condition_value_start,
                '<=' => $user_answer <= $rule->condition_value_start,
                '>'  => $user_answer >  $rule->condition_value_start,
                '>=' => $user_answer >= $rule->condition_value_start,
                'between' => $user_answer >= $rule->condition_value_start &&
                    $user_answer <= $rule->condition_value_end,
                default => true,
            };

            if (!$match) continue;
            $temp_fee = 0;

            if (!empty($rule->per_unit_fee)) {
                $temp_fee += $user_answer * (float) $rule->per_unit_fee;
            }

            if (!empty($rule->fixed_calculated_fee)) {
                $temp_fee += (float) $rule->fixed_calculated_fee;
            }

            $final_fee += $temp_fee;

            if (!empty($rule->minimum_fee) && $rule->minimum_fee > $minimum_fee) {
                $minimum_fee = (float) $rule->minimum_fee;
            }
        }

        if ($minimum_fee > 0 && $final_fee < $minimum_fee) {
            $final_fee = $minimum_fee;
        }

        $late_fee = $this->calculate_late_fee($application, $cycle, $final_fee);
        $total_fee = $final_fee + $late_fee;

        return [
            'base_fee'         => round($final_fee, 2),
            'late_fee'          => round($late_fee, 2),
            'renewal_fee'       => round($total_fee, 2)
        ];
    }

    public function calculate_late_fee($application, $renewal_cycle, $final_fee)
    {

        if (!$renewal_cycle) {
            return 0;
        }

        if ($renewal_cycle->late_fee_applicable != 'yes') {
            return 0;
        }

        $renewal = $this->get_renewal_details($application);

        if (empty($renewal['expiry_date'])) {
            return 0;
        }

        $expiry_date = Carbon::parse($renewal['expiry_date']);
        $today = Carbon::today();

        $late_fee_start_date = null;

        switch ($renewal_cycle->late_fee_start_type) {

            case 'date_of_expiry':
                $late_fee_start_date = $expiry_date->copy();
                break;

            case 'from_date_of_expiry':
                $days = (int) $renewal_cycle->before_date_of_expiry;

                if ($days >= 0) {
                    $late_fee_start_date = $expiry_date->copy()->addDays($days);
                } else {
                    $late_fee_start_date = $expiry_date->copy()->subDays(abs($days));
                }
                break;

            case 'fixed_date':
                if (!empty($renewal_cycle->late_fee_fixed_date)) {
                    $late_fee_start_date = Carbon::parse($renewal_cycle->late_fee_fixed_date);
                }
                break;

            default:
                $late_fee_start_date = $expiry_date->copy();
                break;
        }

        if (!$late_fee_start_date) {
            return 0;
        }

        if ($today->lt($late_fee_start_date)) {
            return 0;
        }

        $days_late = $today->diffInDays($late_fee_start_date);
        $months_late = $today->diffInMonths($late_fee_start_date);

        $fixed_fee  = $renewal_cycle->late_fee_fixed_amount ?? 0;
        $multiplier = $renewal_cycle->late_fee_calculated_amount ?? 0;

        $late_component = 0;

        if ($multiplier > 0) {
            $late_component += ($final_fee * $multiplier);
        }

        $late_component += $fixed_fee;

        switch ($renewal_cycle->late_fee_calculation_dynamic) {

            case 'fixed':
                return $late_component;

            case 'percentage':
                $percentage = $late_component;
                return ($late_component * $percentage) / 100;

            case 'per_day':
                return $days_late * $late_component;

            case 'per_month':
                $months_late = max($months_late, 1);
                return $months_late * $late_component;
        }

        return 0;
    }


    public function calculate_renewal_fee(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
        }

        $request->validate([
            'application_id'     => 'required|integer|exists:user_service_applications,id',
            'renewal_cycle_id'   => 'required|integer|exists:renewal_cycles,id',
            'application_data'   => 'nullable|array',
        ]);

        $application = UserServiceApplication::find($request->application_id);
        $application_data = $request->application_data ?? [];

        $cycle = RenewalCycle::where('id', $request->renewal_cycle_id)
            ->where('service_id', $application->service_id)
            ->first();

        if (!$cycle) {
            return response()->json([
                'status'  => 0,
                'message' => 'Selected renewal cycle does not belong to this service.'
            ], 400);
        }

        $renewal_details = $this->get_renewal_details($application);

        $selected_cycle_details = collect($renewal_details['renewal_cycles'])
            ->firstWhere('renewal_cycle_id', $cycle->id);

        if (!$selected_cycle_details) {
            return response()->json([
                'status' => 0,
                'message' => 'Renewal cycle not found in renewal details.'
            ], 400);
        }

        // if ($selected_cycle_details['can_renew'] === false) {
        //     return response()->json([
        //         'status' => 0,
        //         'message' => 'Renewal window is not active for selected cycle.',
        //         'details' => $selected_cycle_details
        //     ], 400);
        // }

        $final_fee = $this->calculate_renewal_final_fee(
            $application->service_id,
            $application,
            $application_data,
            $cycle
        );

        return response()->json([
            'status' => 1,
            'message' => 'Renewal fee calculated successfully.',
            'data' => [
                'final_fee' => $final_fee
            ]
        ]);
    }

    public function update_renewed_application(Request $request)
    {


        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'application_id'      => 'required|integer|exists:user_service_applications,id',
                'renewal_cycle_id'    => 'required|integer|exists:renewal_cycles,id',
                'application_data'    => 'nullable|array',
            ]);

            DB::beginTransaction();

            $old_application = UserServiceApplication::find($request->application_id);
            $renewal_cycle   = RenewalCycle::find($request->renewal_cycle_id);

            $old_data = json_decode($old_application->application_data, true) ?? [];
            $new_data = $request->input('application_data', []);

            $final_data = $old_data;
            foreach ($new_data as $key => $value) {
                $final_data[(string)$key] = $value;
            }

            $data_changed = ($old_data != $final_data);

            $status = $data_changed ? "re_submitted" : "approved";

            $calculated_fee = $this->calculate_renewal_final_fee(
                $old_application->service_id,
                $old_application,
                $final_data,
                $renewal_cycle
            );

            $late_fee = $calculated_fee['late_fee'];
            $service = $old_application->service;
            $today = Carbon::today();

            if (!empty($service->noc_validity)) {
                $noc_expiry_date = $today->copy()->addDays((int) $service->noc_validity);
            } elseif (!empty($service->fixed_expiry_date)) {
                $noc_expiry_date = Carbon::parse($service->fixed_expiry_date);
            } else {
                $noc_expiry_date = null;
            }

            $old_application_date = Carbon::parse($old_application->application_date);
            if (!empty($service->noc_validity)) {
                $previous_noc_expiry_date = $old_application_date->copy()->addDays((int) $service->noc_validity);
            } elseif (!empty($service->fixed_expiry_date)) {
                $previous_noc_expiry_date = Carbon::parse($service->fixed_expiry_date);
            } else {
                $previous_noc_expiry_date = null;
            }


            if ($status == "re_submitted") {
                $current_step_number = 1;
                $noc_certificate = null;
            } elseif ($status == "approved") {
                $noc_certificate = $old_application->NOC_certificate;
                $current_step_number = $old_application->current_step_number;
            }

            $approval_flow = ServiceApprovalFlow::where('service_id', $old_application->service_id)
                ->orderBy('step_number', 'asc')
                ->first();

            $latest_assignment = ApplicationWorkflowAssignment::where('application_id', $old_application->id)
                ->orderByDesc('id')
                ->first();

            $new_application = UserServiceApplication::create([
                'user_id'                   => $old_application->user_id,
                'service_id'                => $old_application->service_id,
                'renewal_cycle_id'          => $request->renewal_cycle_id,
                'remarks'                   => $request->remarks,
                'application_date'          =>  now(),
                'previous_application_id' => $old_application->id,
                'renewal'                 => 1,
                'renewalYear'            => now()->year,
                'application_data'        => json_encode($final_data),
                'PreviousNOCexpiryDate'   => $previous_noc_expiry_date,
                'total_fee'               => $calculated_fee['renewal_fee'],
                'final_fee'               => $calculated_fee['renewal_fee'],
                'effective_fee'           => 0,
                'paid_amount'             => 0,
                'payment_status'          => 'pending',
                'status'                  => $status,
                'payment_time'            => null,
                'NOC_expiry_date'         => $noc_expiry_date,
                'NOC_certificate'         => $noc_certificate,
                'max_processing_date'     => $old_application->max_processing_date,
                'current_step_number'     => $current_step_number,
            ]);

            $application_number = $this->generate_application_number($service->id, $new_application->id);

            $this->store_labour_deposit_renewal($old_application, $new_application, $final_data, $late_fee);

            $new_application->update([
                'applicationId' => $application_number
            ]);

            $old_application->update([
                'status' => 'expired'
            ]);

            $old_data_json = json_encode($old_data);
            $final_data_json = json_encode($final_data);
            $data_diff = ($old_data_json !== $final_data_json) ? 'Data modified' : 'No data changes';

            $this->logActivity('Application renewed', $new_application, Auth::user(), [
                'new_application_id' => $new_application->applicationId,
                'previous_application_id' => $old_application->applicationId,
                'renewal_year' => now()->year,
                'status' => $status,
                'data_changes' => $data_diff,
                'renewal_fee' => $calculated_fee['renewal_fee'],
            ], 'Application renewal');

            if ($status == "re_submitted") {
                ApplicationWorkflowAssignment::create([
                    'application_id'     => $new_application->id,
                    'service_id'         => $old_application->service_id,
                    'step_number'        => $approval_flow->step_number ?? null,
                    'step_type'          => $approval_flow->step_type ?? null,
                    'department_id'      => $approval_flow->department_id ?? null,
                    'hierarchy_level'    => $approval_flow->hierarchy_level ?? null,
                    'assigned_to_group'  => true,
                    'status'             => 'pending',
                    'action_taken_by'    => null,
                    'action_taken_at'    => null,
                    'remarks'            => null,
                ]);
            } elseif ($status == "approved") {
                ApplicationWorkflowAssignment::create([
                    'application_id'     => $new_application->id,
                    'service_id'         => $old_application->service_id,
                    'step_number'        => $latest_assignment->step_number ?? null,
                    'step_type'          => $latest_assignment->step_type ?? null,
                    'department_id'      => $latest_assignment->department_id ?? null,
                    'hierarchy_level'    => $latest_assignment->hierarchy_level ?? null,
                    'assigned_to_group'  => true,
                    'status'             => $latest_assignment->status,
                    'action_taken_by'    => $latest_assignment->action_taken_by,
                    'action_taken_at'    => $latest_assignment->action_taken_at,
                    'remarks'            => $latest_assignment->remarks,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Your Application has been renewed.',
                'data'    => [
                    'new_application_id'     => $new_application->id,
                    'previous_application_id' => $new_application->previous_application_id,
                    'status'                 => $new_application->status,
                    'application_data'       => json_decode($new_application->application_data, true),
                    'total_fee'              => $new_application->total_fee,
                    'final_fee'              => $new_application->final_fee,
                ]
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to renew application.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function generate_application_number($service_id, $application_id)
    {

        $service = ServiceMaster::find($service_id);

        if (!$service) {
            return null;
        }

        $short_name = strtoupper($service->noc_short_name);
        $padded_id = str_pad($application_id, 4, '0', STR_PAD_LEFT);
        $application_number =  $short_name . $padded_id;

        return $application_number;
    }

    public function get_applications_ready_for_renewal()
    {


        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }


            $user_id = Auth::id();

            $service = UserServiceApplication::where('user_id', $user_id)
                ->where('status', '!=', 'expired')
                ->whereHas('service')
                ->with(['service.renewalCycles']);

            $applications = $service->get();

            $renewable_applications = [];

            foreach ($applications as $app) {

                $renewal_details = $this->get_renewal_details($app);

                $active_cycle = collect($renewal_details['renewal_cycles'])
                    ->firstWhere('can_renew', true);

                if ($active_cycle) {
                    $renewable_applications[] = [
                        'application_id'     => $app->id,
                        'application_number' => $app->applicationId,
                        'service_id'         => $app->service_id,
                        'service_name'       => $app->service->service_title_or_description ?? null,
                        'department_name'    => $app->service->department->name,
                        'status'             => $app->status ?? null,
                        'expiry_date'        => $renewal_details['expiry_date'],
                        'renewal_start_date' => $active_cycle['renewal_start_date'] ?? null,
                        'renewal_end_date'   => $active_cycle['renewal_end_date'] ?? null,
                    ];
                }
            }

            return response()->json([
                'status' => 1,
                'message' => 'Applications eligible for renewal retrieved successfully.',
                'data' => $renewable_applications,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to retrieve renewable applications.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // public function get_service_renewal_cycles(Request $request)    // will remove later after checking the new function
    // {

    //     $request->validate([
    //         'service_id' => 'nullable|integer|exists:service_masters,id',
    //         'application_id' => 'nullable|integer|exists:user_service_applications,id'
    //     ]);

    //     $service = ServiceMaster::with('renewalCycles')->find($request->service_id);

    //     if (!$service) {
    //         return response()->json([
    //             'status' => 0,
    //             'message' => 'Service not found'
    //         ], 404);
    //     }

    //     $renewal_period_days = [
    //         'monthly'     => 30,
    //         'quarterly'   => 90,
    //         'half_yearly' => 182,
    //         'annually'    => 365,
    //         'biannual'    => 182,
    //         'biennial'    => 730,
    //         'triennial'   => 1095,
    //         '4year'       => 1460,
    //         '5year'       => 1825,
    //     ];

    //     $cycles = [];

    //     if ($request->filled('application_id')) {
    //         $application = UserServiceApplication::find($request->application_id);
    //         if ($application && !empty($application->NOC_expiry_date)) {
    //             $expiry_date = Carbon::parse($application->NOC_expiry_date);
    //         } else {
    //             $expiry_date = null;
    //         }
    //     } else {
    //         $expiry_date = null;
    //     }

    //     if (!$expiry_date) {
    //         if (!empty($service->noc_validity)) {
    //             $expiry_date = Carbon::today()->addDays((int) $service->noc_validity);
    //         } elseif (!empty($service->fixed_expiry_date)) {
    //             $expiry_date = Carbon::parse($service->fixed_expiry_date);
    //         }
    //     }

    //     foreach ($service->renewalCycles as $cycle) {

    //         $renewal_start = null;
    //         $renewal_end   = null;

    //         if ($expiry_date) {
    //             $renewal_start = $expiry_date->copy()->addDay();

    //             if ($cycle->renewal_period === 'custom' && !empty($cycle->renewal_period_custom)) {
    //                 $days = (int) preg_replace('/[^0-9]/', '', $cycle->renewal_period_custom);
    //                 if ($days > 0) {
    //                     $renewal_end = $renewal_start->copy()->addDays($days);
    //                 }
    //             } elseif (isset($renewal_period_days[$cycle->renewal_period])) {
    //                 $renewal_end = $renewal_start->copy()->addDays($renewal_period_days[$cycle->renewal_period]);
    //             }
    //         }

    //         $cycles[] = [
    //             'id'                  => $cycle->id,
    //             'renewal_title'       => $cycle->renewal_title ?? null,
    //             'application_expiry_date' => $expiry_date ? $expiry_date->toDateString() : null,
    //             'renewal_start_date' => optional($renewal_start)->toDateString(),
    //             'renewal_end_date'   => optional($renewal_end)->toDateString(),
    //             'pre_window_days'    => $cycle->renewal_window_days,
    //             'post_window_days'   => $cycle->renewal_target_days,
    //             'late_fee_type'      => $cycle->late_fee_calculation_dynamic,
    //             'late_fee_amount'    => $cycle->late_fee_fixed_amount,
    //             'late_fee_applicable' => $cycle->late_fee_applicable,
    //         ];
    //     }

    //     return response()->json([
    //         'status' => 1,
    //         'message' => 'Renewal cycles fetched successfully',
    //         'service_id' => $service->id,
    //         'service_name' => $service->service_title_or_description,
    //         'cycles' => $cycles
    //     ]);
    // }

    public function get_service_renewal_cycles(Request $request)
    {

        $request->validate([
            'service_id' => 'nullable|integer|exists:service_masters,id',
            'application_id' => 'nullable|integer|exists:user_service_applications,id'
        ]);

        $service = ServiceMaster::with('renewalCycles')->find($request->service_id);

        if (!$service) {
            return response()->json([
                'status' => 0,
                'message' => 'Service not found'
            ], 404);
        }

        $renewal_period_days = [
            'monthly'     => 30,
            'quarterly'   => 90,
            'half_yearly' => 182,
            'annually'    => 365,
            'biannual'    => 182,
            'biennial'    => 730,
            'triennial'   => 1095,
            '4year'       => 1460,
            '5year'       => 1825,
        ];

        $cycles = [];

        if ($request->filled('application_id')) {
            $application = UserServiceApplication::find($request->application_id);
            if ($application && !empty($application->NOC_expiry_date)) {
                $expiry_date = Carbon::parse($application->NOC_expiry_date);
            } else {
                $expiry_date = null;
            }
        } else {
            $expiry_date = null;
        }

        if (!$expiry_date) {
            if (!empty($service->noc_validity)) {
                $expiry_date = Carbon::today()->addDays((int) $service->noc_validity);
            } elseif (!empty($service->fixed_expiry_date)) {
                $expiry_date = Carbon::parse($service->fixed_expiry_date);
            }
        }

        foreach ($service->renewalCycles as $cycle) {

            $renewal_start = null;
            $renewal_end   = null;

            if (!empty($cycle->fixed_renewal_start_date) && !empty($cycle->fixed_renewal_end_date)) {

                $renewal_start = Carbon::parse($cycle->fixed_renewal_start_date);
                $renewal_end   = Carbon::parse($cycle->fixed_renewal_end_date);
            } elseif ($expiry_date && (
                !empty($cycle->renewal_window_days) ||
                !empty($cycle->renewal_target_days)
            )) {

                $pre_days  = (int) $cycle->renewal_window_days;
                $post_days = (int) $cycle->renewal_target_days;

                $renewal_start = $expiry_date->copy()->subDays($pre_days);
                $renewal_end   = $expiry_date->copy()->addDays($post_days);
            } elseif ($expiry_date) {

                $renewal_start = $expiry_date->copy()->addDay();

                if ($cycle->renewal_period === 'custom' && !empty($cycle->renewal_period_custom)) {

                    $days = (int) preg_replace('/[^0-9]/', '', $cycle->renewal_period_custom);

                    if ($days > 0) {
                        $renewal_end = $renewal_start->copy()->addDays($days);
                    }
                } elseif (isset($renewal_period_days[$cycle->renewal_period])) {

                    $renewal_end = $renewal_start->copy()->addDays(
                        $renewal_period_days[$cycle->renewal_period]
                    );
                }
            }

            $today = Carbon::today();

            if ($renewal_start && $renewal_end) {
                if ($today->lt($renewal_start) || $today->gt($renewal_end)) {
                    continue;
                }
            }
            $cycles[] = [
                'id'                  => $cycle->id,
                'renewal_title'       => $cycle->renewal_title ?? null,
                'application_expiry_date' => $expiry_date ? $expiry_date->toDateString() : null,
                'renewal_start_date' => optional($renewal_start)->toDateString(),
                'renewal_end_date'   => optional($renewal_end)->toDateString(),
                'pre_window_days'    => $cycle->renewal_window_days,
                'post_window_days'   => $cycle->renewal_target_days,
                'late_fee_type'      => $cycle->late_fee_calculation_dynamic,
                'late_fee_amount'    => $cycle->late_fee_fixed_amount,
                'late_fee_applicable' => $cycle->late_fee_applicable,
            ];
        }

        return response()->json([
            'status' => 1,
            'message' => 'Renewal cycles fetched successfully',
            'service_id' => $service->id,
            'service_name' => $service->service_title_or_description,
            'cycles' => $cycles
        ]);
    }

    protected function remove_questions_from_application_data(array $data, array $question_ids): array
    {
        $question_ids = array_map('strval', $question_ids);

        foreach ($data as $key => $value) {

            if (is_numeric($key) && in_array((string) $key, $question_ids, true)) {
                $this->delete_application_files_recursive($value);
                unset($data[$key]);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->remove_questions_from_application_data($value, $question_ids);
            }
        }

        return $data;
    }

    protected function delete_application_files_recursive($value): void
    {
        if (is_string($value) && $value !== '' && ! str_starts_with($value, 'http')) {
            Storage::disk('public')->delete($value);
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->delete_application_files_recursive($item);
            }
        }
    }

    protected function merge_application_files(array $application_data, array $files, int $user_id): array
    {
        foreach ($files as $key => $file_value) {

            $existing = $application_data[$key] ?? null;

            if ($file_value instanceof UploadedFile) {

                if ($existing !== null) {
                    $this->delete_application_files_recursive($existing);
                }

                $filename = uniqid() . '.' . $file_value->getClientOriginalExtension();
                $path = $file_value->storeAs("uploads/{$user_id}/applications", $filename, 'public');

                $application_data[$key] = $path;
                continue;
            }

            if (is_array($file_value)) {
                $existing_array = is_array($existing) ? $existing : [];
                $application_data[$key] = $this->merge_application_files($existing_array, $file_value, $user_id);
            }
        }

        return $application_data;
    }

    public function get_all_applications_details(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
                'per_page' => 'nullable|integer'
            ]);

            $per_page = $request->per_page ?? 10;

            $query = $query = UserServiceApplication::orderBy('id', 'DESC');

            if ($request->filled('service_id')) {
                $query->where('service_id', $request->service_id);
            }

            if ($request->filled('department_id')) {
                $query->whereHas('service', function ($q) use ($request) {
                    $q->where('department_id', $request->department_id);
                });
            }

            if ($request->filled('application_type')) {
                $query->whereHas('service', function ($q) use ($request) {
                    $q->where('noc_type', $request->application_type);
                });
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('applicationId', 'LIKE', "%{$search}%")
                        ->orWhereHas('user', function ($u) use ($search) {
                            $u->where('name_of_enterprise', 'LIKE', "%{$search}%")
                                ->orWhere('email_id', 'LIKE', "%{$search}%");
                        });
                });
            }

            if ($request->filled('mobile_no')) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('mobile_no', 'LIKE', "%{$request->mobile_no}%");
                });
            }

            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('application_date', [$request->date_from, $request->date_to]);
            } elseif ($request->filled('date_from')) {
                $query->whereDate('application_date', '>=', $request->date_from);
            } elseif ($request->filled('date_to')) {
                $query->whereDate('application_date', '<=', $request->date_to);
            }

            $applications = $query->paginate($per_page);

            if ($applications->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No applications found.'
                ], 404);
            }

            $response_data = [];

            foreach ($applications as $app) {
                $app->application_data = json_decode($app->application_data, true);

                $latest_workflow = $app->workflow()->latest('updated_at')->first();
                $history_data = $app->workflow()->orderBy('id', 'desc')->get()->map(function ($history) {
                    return [
                        'id'              => $history->id,
                        'step_number'     => $history->step_number,
                        'status'          => $history->status,
                        'step_type'       => $history->step_type,
                        'remarks'         => $history->remarks,
                        'status_file'     => $history->status_file ? asset('storage/' . $history->status_file) : null,
                        'action_taken_at' => $history->action_taken_at,
                        'action_taken_by' => optional($history->actionTaker)->authorized_person_name,
                    ];
                });

                $response_data[] = [
                    'application_id' => $app->id,
                    'service_id' => $app->service_id,
                    'application_data' => $app->application_data,
                    'service_title_or_description' => $app->service->service_title_or_description ?? null,
                    'application_type' => $app->service->noc_type ?? null,
                    'department_id' => $app->service->department_id ?? null,
                    'department_name' => $app->service->department->name ?? null,
                    'application_number' => $app->applicationId ?? null,
                    'application_date' => $app->application_date ?? null,
                    'noc_payment_type' => $app->noc_payment_type ?? null,
                    'NOC_expiry_date'  => $app->NOC_expiry_date ?? null,
                    'payment_status'  => $app->payment_status ?? null,
                    'status'  => $app->status ?? null,
                    'renewal_date'  => $app->renewalYear ?? null,
                    'allow_repeat_application' => $app->allow_repeat_application ?? null,
                    'latest_workflow_status' => $latest_workflow?->status ?? null,
                    'service_mode' => $app->service->service_mode ?? null,
                    'already_rated' => $app->my_feedback ? true : false,
                    'rating' => $app->my_feedback->satisfaction ?? null,
                    'is_certificate' => $app->NOC_certificate ? true : false,
                    'workflow_history' => $history_data,
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Applications fetched successfully.',
                'data' => $response_data,
                'pagination' => [
                    'current_page' => $applications->currentPage(),
                    'per_page'     => $applications->count(),
                    'total'        => $applications->total(),
                    'last_page'    => $applications->lastPage(),
                    'next_page_url' => $applications->nextPageUrl(),
                    'prev_page_url' => $applications->previousPageUrl(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function calculate_industrial_estate_amounts(Request $request)
    {
        try {

            $validated = $request->validate([
                'industrial_estate_id' => 'required|integer|exists:industrial_estates,id',
                'requested_land_area'  => 'nullable|numeric|min:0',
                'requested_shed_area'  => 'nullable|numeric|min:0',
            ]);

            $industrial_estate_id = (int) $validated['industrial_estate_id'];
            $requested_land_area  = (float) ($validated['requested_land_area'] ?? 0);
            $requested_shed_area  = (float) ($validated['requested_shed_area'] ?? 0);

            $estate = IndustrialEstate::findOrFail($industrial_estate_id);

            $available_land_area = (float) ($estate->available_land ?? 0);
            $available_shed_area = (float) ($estate->available_shed ?? 0);

            if ($requested_land_area > $available_land_area) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Requested land area cannot be greater than available land area.',
                    'data' => [
                        'available_land_area' => $available_land_area,
                        'available_land_unit' => $estate->available_land_unit,
                    ],
                ], 422);
            }

            if ($requested_shed_area > $available_shed_area) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Requested shed area cannot be greater than available shed area.',
                    'data' => [
                        'available_shed_area' => $available_shed_area,
                        'available_shed_unit' => $estate->available_shed_unit,
                    ],
                ], 422);
            }

            $land_advance_months = 12;
            $shed_advance_months = 6;
            $gst_rate = 0.18;

            $land_area = max(0, $requested_land_area);
            $shed_area = max(0, $requested_shed_area);

            $land_premium_rate = (float) ($estate->land_premium ?? 0);
            $shed_premium_rate = (float) ($estate->shed_premium ?? 0);
            $land_rent_rate    = (float) ($estate->land_rent ?? 0);
            $shed_rent_rate    = (float) ($estate->shed_rent ?? 0);

            $land_premium_amount = $land_premium_rate * $land_area;
            $shed_premium_amount = $shed_premium_rate * $shed_area;
            $total_non_refundable_lease_amount = $land_premium_amount + $shed_premium_amount;

            $advance_land_rent_amount = $land_rent_rate * $land_area * $land_advance_months;
            $advance_shed_rent_amount = $shed_rent_rate * $shed_area * $shed_advance_months;
            $total_advance_lease_rent_amount = $advance_land_rent_amount + $advance_shed_rent_amount;

            // GST only on advance rent
            $gst_amount = $total_advance_lease_rent_amount * $gst_rate;

            $total_payable_amount = $total_non_refundable_lease_amount + $total_advance_lease_rent_amount + $gst_amount;

            return response()->json([
                'status'  => 1,
                'message' => 'Calculation fetched successfully.',
                'data' => [
                    'land_premium_amount' => round($land_premium_amount, 2),
                    'shed_premium_amount' => round($shed_premium_amount, 2),
                    'total_non_refundable_lease_amount' => round($total_non_refundable_lease_amount, 2),

                    'advance_land_rent_amount' => round($advance_land_rent_amount, 2),
                    'advance_shed_rent_amount' => round($advance_shed_rent_amount, 2),
                    'total_advance_lease_rent_amount' => round($total_advance_lease_rent_amount, 2),

                    'gst_amount' => round($gst_amount, 2),
                    'total_payable_amount' => round($total_payable_amount, 2),

                    'meta' => [
                        'land_advance_months' => $land_advance_months,
                        'shed_advance_months' => $shed_advance_months,
                        'gst_rate' => $gst_rate,
                    ],
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    private function collect_question_ids_recursive(array $data, array &$question_ids): void
    {
        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                $question_ids[] = (int) $key;
            }
            if (is_array($value)) {
                $this->collect_question_ids_recursive($value, $question_ids);
            }
        }
    }

    private function convert_file_urls_recursive(array &$data, array $file_question_ids): void
    {
        foreach ($data as $key => &$value) {
            if (is_numeric($key) && in_array((int) $key, $file_question_ids) && is_string($value) && $value !== '' && !str_starts_with($value, 'http')) {
                $value = asset('storage/' . ltrim($value, '/'));
            } elseif (is_array($value)) {
                $this->convert_file_urls_recursive($value, $file_question_ids);
            }
        }
    }

    private function send_application_whatsapp_notification($user, $application, $service_data, $status, $total_fee, $has_approval_flow): void
    {
        $template_name = null;
        $params = [];

        if ((float) $total_fee === 0.0 && $has_approval_flow) {
            $template_name = 'application_submitted_v3';
            $params = [
                $application->applicationId ?? $application->id,
                $service_data->service_title_or_description ?? '',
                ucfirst($status),
                Carbon::parse($application->application_date)->format('d M Y, g:i A')
            ];
        } elseif ((float) $total_fee === 0.0 && !$has_approval_flow) {
            // sending template "certificate_generated_v1" from certificateController
        } elseif ((float) $total_fee > 0.0) {
            $template_name = 'payment_required_v1';
            $params = [
                $application->applicationId ?? $application->id,
                $service_data->service_title_or_description ?? '',
                number_format($total_fee, 2),
                Carbon::parse($application->application_date)->format('d M Y, g:i A')
            ];
        }

        if ($template_name) {
            SendWhatsAppNotification::dispatch(
                $user->mobile_no,
                $template_name,
                $params,
                "application_id={$application->id}"
            );
        }
    }

    public function get_application_tracking_details(Request $request)
    {


        try {

            if (!Auth::check()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $request->validate([
                'applicationId' => 'required|string|exists:user_service_applications,applicationId',
            ]);

            $app = UserServiceApplication::with(['service.department', 'workflow.actionTaker', 'workflow.department'])
                ->where('applicationId', $request->applicationId)
                ->first();

            if (!$app) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Application not found.'
                ], 404);
            }

            $app->application_data = json_decode($app->application_data, true);

            $history = $app->workflow()->orderBy('id', 'desc')->get();

            $latest_workflow = $app->latestWorkflow()
                ->latest('created_at')
                ->first();

            $last_approved = $history->where('status', 'approved')->first();

            $executed_steps = $app->workflow()->orderBy('step_number')->get();

            $all_steps = ServiceApprovalFlow::where('service_id', $app->service_id)
                ->orderBy('step_number')
                ->get();

            $last_completed_step = $executed_steps->max('step_number') ?? 0;

            $history_records = ApplicationWorkflowHistory::where('application_id', $app->id)
                ->get()
                ->groupBy(function ($item) {
                    return $item->step_number . '_' . $item->step_type . '_' . $item->department_id . '_' . $item->hierarchy_level;
                });

            $history_data = $history->map(function ($item) use ($history_records) {

                $key = $item->step_number . '_' . $item->step_type . '_' . $item->department_id . '_' . $item->hierarchy_level;

                $records = $history_records->get($key);

                $latest_history = $records
                    ? $records->sortByDesc('id')->first()
                    : null;
                return [
                    'id'              => $item->id,
                    'step_number'     => $item->step_number,
                    'status'          => $item->status,
                    'step_type'       => $item->step_type,
                    'hierarchy_level' => $item->hierarchy_level,
                    'department'      => $item->department->name,
                    'remarks'         => $item->remarks,
                    'status_file'     => $latest_history && $latest_history->status_file
                        ? asset('storage/' . $latest_history->status_file)
                        : null,
                    'action_taken_at' => $item->action_taken_at,
                    'action_taken_by' => optional($item->actionTaker)->authorized_person_name,
                    'action_taken_email_id' => optional($item->actionTaker)->email_id,
                ];
            });

            foreach ($all_steps as $step) {
                if ($step->step_number > $last_completed_step) {
                    $history_data[] = [
                        'step_number'      => $step->step_number,
                        'status'           => 'pending',
                        'step_type'        => $step->step_type,
                        'hierarchy_level' => $step->hierarchy_level,
                        'department'      => $step->department->name,
                        'department_id'    => $step->department_id,
                        'department_name'  => $step->department->name ?? null,
                        'action_taken_at'  => null,
                        'action_taken_by'  => null,
                    ];
                }
            }

            $history_data = collect($history_data)
                ->sortBy('step_number')
                ->values();

            $response = [
                'application_id' => $app->id,
                'service_id' => $app->service_id,
                'application_number' => $app->applicationId,
                'applicat_name' => $app->user->authorized_person_name,
                'application_date' => $app->application_date,
                'service_title_or_description' => $app->service->service_title_or_description ?? null,
                'application_type' => $app->service->noc_type ?? null,
                'department_id' => $app->service->department_id ?? null,
                'department_name' => $app->service->department->name ?? null,
                'payment_status' => $app->payment_status,
                'status' => $app->status,
                'latest_hierarchy' => $latest_workflow->hierarchy_level ?? null,
                'latest_status' => $latest_workflow->status ?? null,
                'latest_department' => $app->service->department->name ?? null,
                'last_approved_by' => optional($last_approved?->actionTaker)->authorized_person_name,
                'last_approved_dept_mail' => optional($last_approved?->actionTaker)->email_id,
                'last_approved_time' => $last_approved?->action_taken_at,
                'NOC_generationDate' => $app->NOC_generationDate,
                'NOC_expiry_date' => $app->NOC_expiry_date,
                'license_id' => $app->license_id,
                'NOC_mode' => $app->NOC_mode,
                'NOC_certificate' => $app->noc_certificate_url,
                'history_data' => $history_data,
            ];

            return response()->json([
                'status' => 1,
                'message' => 'Application details fetched successfully.',
                'data' => $response
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function store_labour_deposits($service_id, $application_data, $application_id)
    {
        if ($service_id != 37) {
            return;
        }

        $calculated = $this->calculate_labour_deposit($service_id, $application_data, [882, 883]);

        $contract_labour_deposit = $calculated[882]['deposit'] ?? 0;
        $contract_labour_fee     = $calculated[882]['fee'] ?? 0;
        $no_of_contract_labour   = $application_data[882] ?? 0;

        $ismw_labour_deposit     = $calculated[883]['deposit'] ?? 0;
        $ismw_labour_fee         = $calculated[883]['fee'] ?? 0;
        $no_of_ismw_labour       = $application_data[883] ?? 0;

        if (
            $contract_labour_deposit == 0 &&
            $ismw_labour_deposit == 0 &&
            $contract_labour_fee == 0 &&
            $ismw_labour_fee == 0
        ) {
            return;
        }

        $scheme_details = [
            ['scheme' => '8443-00-103-37-01', 'amount' => $contract_labour_deposit],
            ['scheme' => '8443-00-103-37-02', 'amount' => $ismw_labour_deposit],
            ['scheme' => '0230-00-106-37-02', 'amount' => $contract_labour_fee],
            ['scheme' => '0230-00-101-37-06', 'amount' => $ismw_labour_fee],
        ];

        $application = UserServiceApplication::find($application_id);
        if (!$application) return;

        if ($application->labourDeposit) {
            $application->labourDeposit->update([
                'contract_labour_deposit' => $contract_labour_deposit,
                'ismw_labour_deposit'     => $ismw_labour_deposit,
                'contract_labour_fee'     => $contract_labour_fee,
                'ismw_labour_fee'         => $ismw_labour_fee,
                'no_of_contract_labour'   => $no_of_contract_labour,
                'no_of_ismw_labour'       => $no_of_ismw_labour,
                'payment_status'          => "pending",
                'scheme_details'          => json_encode($scheme_details),
            ]);
        } else {
            $application->labourDeposit()->create([
                'application_id'          => $application_id,
                'contract_labour_deposit' => $contract_labour_deposit,
                'ismw_labour_deposit'     => $ismw_labour_deposit,
                'contract_labour_fee'     => $contract_labour_fee,
                'ismw_labour_fee'         => $ismw_labour_fee,
                'no_of_contract_labour'   => $no_of_contract_labour,
                'no_of_ismw_labour'       => $no_of_ismw_labour,
                'payment_status'          => "pending",
                'scheme_details'          => json_encode($scheme_details),
            ]);
        }
    }

    private function calculate_labour_deposit($service_id, $application_data, $target_question_ids = [])
    {
        $rules = ServiceFeeRule::where('service_id', $service_id)
            ->whereIn('question_id', $target_question_ids)
            ->orderBy('priority')
            ->get();

        $result = [];

        foreach ($target_question_ids as $qid) {

            $result[$qid] = [
                'deposit' => 0,
                'fee'     => 0,
            ];

            $user_answer = $application_data[$qid]
                ?? $application_data[(string)$qid]
                ?? null;

            if ($user_answer === null) continue;

            if (is_numeric($user_answer)) {
                $user_answer = (float) $user_answer;
            }

            foreach ($rules->where('question_id', $qid) as $rule) {

                if ($rule->condition_label_question_id) {

                    $pre_value = $application_data[$rule->condition_label_question_id] ?? null;
                    if ($pre_value === null) continue;

                    if (is_numeric($pre_value)) {
                        $pre_value = (float) $pre_value;
                    }

                    $pre_match = match ($rule->pre_condition_operator) {
                        '='  => $pre_value == $rule->pre_condition_value,
                        '!=' => $pre_value != $rule->pre_condition_value,
                        '<'  => $pre_value <  $rule->pre_condition_value,
                        '<=' => $pre_value <= $rule->pre_condition_value,
                        '>'  => $pre_value >  $rule->pre_condition_value,
                        '>=' => $pre_value >= $rule->pre_condition_value,
                        'between' => $pre_value >= $rule->pre_start_value &&
                            $pre_value <= $rule->pre_end_value,
                        default => true,
                    };

                    if (!$pre_match) continue;
                }

                $match = match ($rule->condition_operator) {
                    '='  => $user_answer == $rule->condition_value_start,
                    '!=' => $user_answer != $rule->condition_value_start,
                    '<'  => $user_answer <  $rule->condition_value_start,
                    '<=' => $user_answer <= $rule->condition_value_start,
                    '>'  => $user_answer >  $rule->condition_value_start,
                    '>=' => $user_answer >= $rule->condition_value_start,
                    'between' => $user_answer >= $rule->condition_value_start &&
                        $user_answer <= $rule->condition_value_end,
                    default => true,
                };

                if (!$match) continue;

                $temp = 0;

                if (!empty($rule->per_unit_fee)) {
                    $temp += $user_answer * (float) $rule->per_unit_fee;
                }

                if (!empty($rule->fixed_calculated_fee)) {
                    $temp += (float) $rule->fixed_calculated_fee;
                }

                if ($rule->condition_operator === 'between') {
                    $result[$qid]['fee'] = $temp;
                    break;
                } else {
                    $result[$qid]['deposit'] += $temp;
                }
            }
        }

        return $result;
    }

    private function calculate_labour_deposit_renewal($service_id, $application_data, $target_question_ids = [])
    {
        $rules = RenewalFeeRule::where('service_id', $service_id)
            ->whereIn('question_id', $target_question_ids)
            ->orderBy('priority')
            ->get();

        $result = [];

        foreach ($target_question_ids as $qid) {

            $result[$qid] = [
                'deposit' => 0,
                'fee'     => 0,
            ];

            $user_answer = $application_data[$qid]
                ?? $application_data[(string)$qid]
                ?? null;

            if ($user_answer === null) continue;

            if (is_numeric($user_answer)) {
                $user_answer = (float) $user_answer;
            }

            foreach ($rules->where('question_id', $qid) as $rule) {

                if ($rule->condition_label_question_id) {

                    $pre_value = $application_data[$rule->condition_label_question_id] ?? null;
                    if ($pre_value === null) continue;

                    if (is_numeric($pre_value)) {
                        $pre_value = (float) $pre_value;
                    }

                    $pre_match = match ($rule->pre_condition_operator) {
                        '='  => $pre_value == $rule->pre_condition_value,
                        '!=' => $pre_value != $rule->pre_condition_value,
                        '<'  => $pre_value <  $rule->pre_condition_value,
                        '<=' => $pre_value <= $rule->pre_condition_value,
                        '>'  => $pre_value >  $rule->pre_condition_value,
                        '>=' => $pre_value >= $rule->pre_condition_value,
                        'between' => $pre_value >= $rule->pre_start_value &&
                            $pre_value <= $rule->pre_end_value,
                        default => true,
                    };

                    if (!$pre_match) continue;
                }

                $match = match ($rule->condition_operator) {
                    '='  => $user_answer == $rule->condition_value_start,
                    '!=' => $user_answer != $rule->condition_value_start,
                    '<'  => $user_answer <  $rule->condition_value_start,
                    '<=' => $user_answer <= $rule->condition_value_start,
                    '>'  => $user_answer >  $rule->condition_value_start,
                    '>=' => $user_answer >= $rule->condition_value_start,
                    'between' => $user_answer >= $rule->condition_value_start &&
                        $user_answer <= $rule->condition_value_end,
                    default => true,
                };

                if (!$match) continue;

                $temp = 0;

                if (!empty($rule->per_unit_fee)) {
                    $temp += $user_answer * (float) $rule->per_unit_fee;
                }

                if (!empty($rule->fixed_calculated_fee)) {
                    $temp += (float) $rule->fixed_calculated_fee;
                }

                if ($rule->condition_operator === 'between') {
                    $result[$qid]['fee'] = $temp;
                    break;
                } else {
                    $result[$qid]['deposit'] += $temp;
                }
            }
        }

        return $result;
    }


    private function store_labour_deposit_renewal($old_application, $new_application, $final_data, $late_fee)
    {

        if ($old_application->service_id != 37) {
            return;
        }

        $old_deposit = LabourDeposit::where('application_id', $old_application->id)->first();

        $old_contract_count = (int) ($old_deposit->no_of_contract_labour ?? 0);
        $old_ismw_count     = (int) ($old_deposit->no_of_ismw_labour ?? 0);

        $old_data = [
            882 => $old_contract_count,
            883 => $old_ismw_count,
        ];

        $old_calculated = $this->calculate_labour_deposit_renewal(
            37,
            $old_data,
            [882, 883]
        );

        $old_contract_deposit = (float) ($old_calculated[882]['deposit'] ?? 0);
        $old_ismw_deposit     = (float) ($old_calculated[883]['deposit'] ?? 0);

        $calculated = $this->calculate_labour_deposit_renewal(
            $new_application->service_id,
            $final_data,
            [882, 883]
        );

        $new_contract_deposit_total = (float) ($calculated[882]['deposit'] ?? 0);
        $new_ismw_deposit_total     = (float) ($calculated[883]['deposit'] ?? 0);

        $new_contract_fee = (float) ($calculated[882]['fee'] ?? 0);
        $new_ismw_fee     = (float) ($calculated[883]['fee'] ?? 0);

        $total_base_fee = $new_contract_fee + $new_ismw_fee;

        if ($total_base_fee > 0) {
            $contract_late_share = ($new_contract_fee / $total_base_fee) * $late_fee;
            $ismw_late_share     = ($new_ismw_fee / $total_base_fee) * $late_fee;
        } else {
            $contract_late_share = $late_fee;
            $ismw_late_share     = 0;
        }

        $new_contract_fee += $contract_late_share;
        $new_ismw_fee     += $ismw_late_share;

        $new_contract_count = (int) ($final_data[882] ?? 0);
        $new_ismw_count     = (int) ($final_data[883] ?? 0);

        $contract_deposit_payable = max(0, $new_contract_deposit_total - $old_contract_deposit);
        $ismw_deposit_payable     = max(0, $new_ismw_deposit_total - $old_ismw_deposit);

        $scheme_details = [
            ['scheme' => '8443-00-103-37-01', 'amount' => $contract_deposit_payable],
            ['scheme' => '8443-00-103-37-02', 'amount' => $ismw_deposit_payable],
            ['scheme' => '0230-00-106-37-02', 'amount' => $new_contract_fee],
            ['scheme' => '0230-00-101-37-06', 'amount' => $new_ismw_fee],
        ];

        LabourDeposit::create([
            'application_id'            => $new_application->id,
            'old_application_id'        => $old_application->id,
            'old_user_id'               => $old_application->user_id,
            'contract_labour_deposit'   => $contract_deposit_payable,
            'ismw_labour_deposit'       => $ismw_deposit_payable,
            'contract_labour_fee'       => $new_contract_fee,
            'ismw_labour_fee'           => $new_ismw_fee,
            'no_of_contract_labour'     => $new_contract_count,
            'old_no_of_contract_labour' => $old_contract_count,
            'no_of_ismw_labour'         => $new_ismw_count,
            'old_no_of_ismw_labour'     => $old_ismw_count,
            'scheme_details'            => json_encode($scheme_details),
        ]);
    }

    private function calculate_labour_renewal_fee($application, $application_data, $cycle, $old_deposit = null)
    {

        if (!$old_deposit) {
            $old_deposit = LabourDeposit::where('application_id', $application->id)->first();
        }

        $old_contract_count = (int) (
            $old_deposit->no_of_contract_labour
            ?? ($application->application_data[882] ?? 0)
        );

        $old_ismw_count = (int) (
            $old_deposit->no_of_ismw_labour
            ?? ($application->application_data[883] ?? 0)
        );

        $old_data = [
            882 => $old_contract_count,
            883 => $old_ismw_count,
        ];

        $old_calculated = $this->calculate_labour_deposit_renewal(37, $old_data, [882, 883]);

        $old_contract_deposit = (float) ($old_calculated[882]['deposit'] ?? 0);
        $old_ismw_deposit     = (float) ($old_calculated[883]['deposit'] ?? 0);

        $calculated = $this->calculate_labour_deposit_renewal(37, $application_data, [882, 883]);

        $new_contract_deposit = (float) ($calculated[882]['deposit'] ?? 0);
        $new_ismw_deposit     = (float) ($calculated[883]['deposit'] ?? 0);

        $contract_fee = (float) ($calculated[882]['fee'] ?? 0);
        $ismw_fee     = (float) ($calculated[883]['fee'] ?? 0);

        $contract_deposit_payable = max(0, $new_contract_deposit - $old_contract_deposit);
        $ismw_deposit_payable     = max(0, $new_ismw_deposit - $old_ismw_deposit);

        $base_fee = $contract_fee + $ismw_fee;
        $late_fee = $this->calculate_late_fee($application, $cycle, $base_fee);

        $total_payable = $contract_deposit_payable
            + $ismw_deposit_payable
            + $base_fee
            + $late_fee;

        return [
            'base_fee'            => round($base_fee, 2),
            'deposit_difference'  => round($contract_deposit_payable + $ismw_deposit_payable, 2),
            'late_fee'            => round($late_fee, 2),
            'renewal_fee'         => round($total_payable, 2),
        ];
    }

    public function get_user_renewed_applications(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'per_page'  => 'nullable|integer'
            ]);

            $per_page = $request->per_page ?? 10;

            $query = UserServiceApplication::where('user_id', $request->user_id)
                ->whereNotNull('previous_application_id')
                ->where('status', '!=', 'expired')
                ->with('my_feedback', 'service.department')
                ->orderBy('application_date', 'desc');

            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('application_date', [$request->date_from, $request->date_to]);
            } elseif ($request->filled('date_from')) {
                $query->whereDate('application_date', '>=', $request->date_from);
            } elseif ($request->filled('date_to')) {
                $query->whereDate('application_date', '<=', $request->date_to);
            }

            if ($request->filled('service_id')) {
                $query->where('service_id', $request->service_id);
            }

            if ($request->filled('department_id')) {
                $query->whereHas('service', function ($q) use ($request) {
                    $q->where('department_id', $request->department_id);
                });
            }

            if ($request->filled('application_type')) {
                $query->whereHas('service', function ($q) use ($request) {
                    $q->where('noc_type', $request->application_type);
                });
            }

            $service_user_application = $query->paginate($per_page);

            if ($service_user_application->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No service user applications found for the given user_id.',
                ], 404);
            }

            foreach ($service_user_application as $service) {
                $service->application_data = json_decode($service->application_data, true);
                $latest_workflow = $service->workflow()->latest('updated_at')->first();

                if ($service->status === 'approved') {
                    $appeal_for = 'approved';
                } elseif ($service->status === 'extra_payment') {
                    $appeal_for = 'extra_payment';
                } elseif (!empty($service->max_processing_date) && now()->gt($service->max_processing_date)) {
                    $appeal_for = 'max_processing_date_exceed';
                } else {
                    $appeal_for = null;
                }

                $appeal = $service->appeal;

                if ($appeal) {
                    if ($appeal->status === 'pending') {
                        $appeal_for = 'in_progress';
                    } elseif ($appeal->status === 'rejected') {
                        $appeal_for = 'rejected';
                    } elseif ($appeal->status === 'approved') {
                        $appeal_for = 'your appeal request approved';
                    }
                }

                $response_data[] = [
                    'application_id' => $service->id,
                    'service_id' => $service->service_id,
                    'application_data' => $service->application_data,
                    'previous_application_id' => $service->previous_application_id,
                    'service_title_or_description' => $service->service->service_title_or_description ?? null,
                    'application_type' => $service->service->noc_type ?? null,
                    'department' => $service->service->department_id ?? null,
                    'department_name' => $service->service->department->name ?? null,
                    'application_number' => $service->applicationId ?? null,
                    'application_date' => $service->application_date ?? null,
                    'noc_payment_type' => $service->noc_payment_type ?? null,
                    'NOC_expiry_date'  => $service->NOC_expiry_date ?? null,
                    'payment_status'  => $service->payment_status ?? null,
                    'status'  => $service->status ?? null,
                    'renewal_date'  => $service->renewalYear ?? null,
                    'allow_repeat_application' => $service->allow_repeat_application ?? null,
                    'latest_workflow_status' => $latest_workflow?->status ?? null,
                    'service_mode' => $service->service->service_mode ?? null,
                    'already_rated' => $service->my_feedback ? true : false,
                    'rating' => $service->my_feedback->satisfaction ?? null,
                    'feedback_id' => $service->my_feedback->id ?? null,
                    'is_certificate' => $service->NOC_certificate ? true : false,

                    'appeal_for' => $appeal_for
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service user application fetched successfully.',
                'data' => $response_data,
                'pagination' => [
                    'current_page' => $service_user_application->currentPage(),
                    'per_page'     => $service_user_application->count(),
                    'total'        => $service_user_application->total(),
                    'last_page'    => $service_user_application->lastPage(),
                    'next_page_url' => $service_user_application->nextPageUrl(),
                    'prev_page_url' => $service_user_application->previousPageUrl(),
                ]
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function calculate_labour_fee_breakdown($service_id, $application_data, $application_id = null)
    {
        $target_question_ids = array_keys($application_data ?? []);

        $labour_data = $this->calculate_labour_deposit(
            $service_id,
            $application_data,
            $target_question_ids
        );

        $base_fee = 0;
        $deposit_fee = 0;

        foreach ($labour_data as $row) {
            $base_fee += ($row['fee'] ?? 0);
            $deposit_fee += ($row['deposit'] ?? 0);
        }

        $previous_paid = 0;
        if ($application_id) {
            $existing = UserServiceApplication::find($application_id);
            $previous_paid = $existing->paid_amount ?? 0;
        }

        $final_fee = $base_fee + $deposit_fee;

        $effective_fee = 0;
        if (!empty($previous_paid)) {
            $effective_fee = max($final_fee - $previous_paid, 0);
        }

        return [
            'base_fee'    => round($base_fee, 2),
            'deposit_fee'   => round($deposit_fee, 2),
            'final_fee'     => round($final_fee, 2),
            'previous_paid' => round($previous_paid, 2),
            'effective_fee' => round($effective_fee, 2),
            'payable_fee'   => round($effective_fee > 0 ? $effective_fee : 0, 2),
        ];
    }

    private function calculate_labour_resubmission_breakdown($service_id, $application_data, $application_id)
    {
        $application = UserServiceApplication::find($application_id);

        $application_data_db = is_array($application->application_data)
            ? $application->application_data
            : json_decode($application->application_data ?? 'null', true);

        $cycle = RenewalCycle::where('id', $application->renewal_cycle_id)
            ->where('service_id', $application->service_id)
            ->first();

        $paid_amount = (float) $application->paid_amount;
        $final_fee_db = (float) $application->final_fee;
        $previous_paid = (float) ($application->paid_amount ?? 0);

        $is_corrupted_paid_case =
            $application->status === 'send_back' &&
            $application->payment_status === 'paid' &&
            $final_fee_db > 0 && empty($application->application_data) && $paid_amount <= 0;

        if ($is_corrupted_paid_case) {
            $application->paid_amount = $final_fee_db;
            $application->total_fee = $final_fee_db;

            if (empty($application->current_step_number)) {
                $last_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                    ->latest('id')
                    ->first();

                if ($last_step) {
                    $application->current_step_number = $last_step->step_number;
                }
            }

            $previous_paid = (float) $final_fee_db;
            $application->save();
        }

        $old_deposit = LabourDeposit::where('application_id', $application->id)->first();
        $old_data = [
            882 => (int) ($old_deposit->no_of_contract_labour ?? ($application->application_data[882] ?? 0)),
            883 => (int) ($old_deposit->no_of_ismw_labour ?? ($application->application_data[883] ?? 0)),
        ];
        $old_calc = $this->calculate_labour_deposit($service_id, $old_data, [882, 883]);
        $old_base_fee = ($old_calc[882]['fee'] ?? 0) + ($old_calc[883]['fee'] ?? 0);
        $old_deposit_total = ($old_calc[882]['deposit'] ?? 0) + ($old_calc[883]['deposit'] ?? 0);

        $new_calc = $this->calculate_labour_deposit($service_id, $application_data, [882, 883]);
        $new_base_fee = ($new_calc[882]['fee'] ?? 0) + ($new_calc[883]['fee'] ?? 0);
        $new_deposit_total = ($new_calc[882]['deposit'] ?? 0) + ($new_calc[883]['deposit'] ?? 0);

        $deposit_difference = max(0, $new_deposit_total - $old_deposit_total);
        $base_fee = $new_base_fee;
        $late_fee = $this->calculate_late_fee($application, $cycle, $base_fee);
        $final_fee = $base_fee + $deposit_difference + $late_fee;
        $effective_fee = max($final_fee - $previous_paid, 0);

        return [
            'base_fee'      => round($base_fee, 2),
            'deposit_difference'   => round($deposit_difference, 2),
            'late_fee'      => round($late_fee, 2),
            'final_fee'     => round($final_fee, 2),
            'previous_paid' => round($previous_paid, 2),
            'effective_fee' => round($effective_fee, 2),
            'payable_fee'   => round($effective_fee > 0 ? $effective_fee : 0, 2),
        ];
    }

    private function update_labour_deposits_latest($service_id, $application_data, $application_id)
    {
        if ($service_id != 37) return;

        $application = UserServiceApplication::find($application_id);
        if (!$application) return;

        if (!is_array($application_data)) {
            $application_data = json_decode($application_data, true) ?? [];
        }

        $new_contract = (int) ($application_data['882'] ?? 0);
        $new_ismw     = (int) ($application_data['883'] ?? 0);

        $calculated = $this->calculate_labour_deposit($service_id, $application_data, ['882', '883']);

        $new_contract_deposit = $calculated[882]['deposit'] ?? 0;
        $new_contract_fee     = $calculated[882]['fee'] ?? 0;

        $new_ismw_deposit     = $calculated[883]['deposit'] ?? 0;
        $new_ismw_fee         = $calculated[883]['fee'] ?? 0;

        $deposit = $application->labourDeposit;

        if ($deposit) {

            $old_contract = (int) $deposit->no_of_contract_labour;
            $old_ismw     = (int) $deposit->no_of_ismw_labour;

            $deposit->update([
                'old_no_of_contract_labour' => $old_contract,
                'old_no_of_ismw_labour'     => $old_ismw,
                'no_of_contract_labour'     => $new_contract,
                'no_of_ismw_labour'         => $new_ismw,
                'contract_labour_deposit'   => $new_contract_deposit,
                'ismw_labour_deposit'       => $new_ismw_deposit,
                'contract_labour_fee'       => $new_contract_fee,
                'ismw_labour_fee'           => $new_ismw_fee,
                'payment_status'            => 'pending',
            ]);
        } else {

            $application->labourDeposit()->create([
                'application_id'            => $application_id,

                'old_no_of_contract_labour' => 0,
                'old_no_of_ismw_labour'     => 0,
                'no_of_contract_labour'     => $new_contract,
                'no_of_ismw_labour'         => $new_ismw,
                'contract_labour_deposit'   => $new_contract_deposit,
                'ismw_labour_deposit'       => $new_ismw_deposit,
                'contract_labour_fee'       => $new_contract_fee,
                'ismw_labour_fee'           => $new_ismw_fee,
                'payment_status'            => 'pending',
            ]);
        }
    }
}
