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


class UserServiceApplicationController extends Controller
{
    public function user_service_application_store(Request $request)
    {
        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id'            => 'required|integer|exists:service_masters,id',
                'renewal_cycle_id'      => 'nullable|integer|exists:renewal_cycles,id',
                'renewal'               => 'nullable|in:yes,no',
                'renewalYear'           => 'nullable|integer|min:1|max:10',
                'applicationId'         => 'nullable|string|max:255',
                'application_date'      => 'nullable|date',
                'status'                => 'nullable|in:submitted,under_review,approved,rejected,re_submitted,send_back,saved',
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
            ]);

            $this->validate_questionnaire_file_inputs($request);

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
                ->first(['noc_name', 'service_mode',  'target_days', 'allow_repeat_application']);

            if ($service_data->service_mode === "native") {

                $approval_flow = ServiceApprovalFlow::where('service_id', $request->service_id)
                    ->orderBy('step_number', 'asc')
                    ->first();

                $dateTime = now()->format('dmYHi');

                $application_number = strtoupper($service_data->noc_name) . $dateTime;

                if (!$approval_flow) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'You cannot submit an application for this particular service; please contact the administrator.'
                    ], 404);
                }

                $application_date = Carbon::parse($request->NOC_application_date ?? now());
                $target_days = $service_data->target_days ?? 0;


                $max_processing_date = $this->add_working_days($application_date, $target_days);



                $user_service_application = UserServiceApplication::where('user_id', $user->id)
                    ->where('service_id', $request->service_id)
                    ->latest()
                    ->first();

                $application_id =  $user_service_application->id ?? null;
                $fee_data = $this->calculate_final_fee($request->service_id, $request->application_data, $application_id);
                $final_fee = $fee_data['final_fee'];
                $blc_fee   = $fee_data['effective_fee'];
                $previous_paid = $fee_data['previous_paid'];
                $total_fee =  $final_fee;
                $effective_fee = 0;
                if ($user_service_application && $service_data->allow_repeat_application === 'no') {

                    $total_fee =  $final_fee;
                    $previous_paid = $user_service_application->paid_amount ?? 0;
                    if (!is_null($previous_paid) && $previous_paid > 0) {
                        $effective_fee = $total_fee - $previous_paid;
                    }

                    if (!in_array($user_service_application->status, ['submitted', 're_submitted', 'send_back', 'extra_payment', 'saved'])) {
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

                    $application_data = [];
                    if (!empty($user_service_application->application_data)) {
                        $decoded = json_decode($user_service_application->application_data, true);
                        if (is_array($decoded)) {
                            $application_data = $decoded;
                        }
                    }
                    $new_data = $request->input('application_data', []);

                    foreach ($new_data as $key => $value) {
                        if (is_numeric($key)) {
                            $key = (string) $key;
                        }
                        $application_data[$key] = $value;
                    }

                    $removed_question_ids = json_decode((string)($request->input('remove_file_question_ids') ?? '[]'), true) ?? [];
                    foreach ($removed_question_ids as $question_id) {
                        $old_file_path = $application_data[$question_id] ?? null;
                        if ($old_file_path && !str_starts_with($old_file_path, 'http')) {        //change
                            Storage::disk('public')->delete($old_file_path);
                        }
                        unset($application_data[$question_id]);
                    }

                    foreach ($request->file('application_data', []) as $question_id => $uploaded_file) {
                        if (!$uploaded_file) continue;
                        $old_file_path = $application_data[$question_id] ?? null;
                        if ($old_file_path && !str_starts_with($old_file_path, 'http')) {
                            Storage::disk('public')->delete($old_file_path);
                        }
                        $filename = uniqid() . '.' . $uploaded_file->getClientOriginalExtension();
                        $path = $uploaded_file->storeAs("uploads/$user->id/applications", $filename, 'public');
                        $new_data[$question_id] = $path;
                    }

                    $user_service_application->application_data = json_encode($application_data);

                    $user_service_application->update([
                        'renewal_cycle_id'      => $request->renewal_cycle_id,
                        'renewal'               => $request->renewal,
                        'renewalYear'           => $request->renewalYear,
                        'applicationId'         => $application_number,
                        'application_date'      => $request->application_date ?? now(),
                        'status'                => $request->status ?? 're_submitted',
                        'application_data'      => $user_service_application->application_data,
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
                        'current_step_number'   => $approval_flow->step_number ?? null,
                        'max_processing_date'   => $max_processing_date,
                    ]);

                    ApplicationWorkflowAssignment::where('application_id', $user_service_application->id)
                        ->where('status', 'pending')
                        ->update([
                            'status'          => 're_submitted',
                            'remarks'         => $request->remarks,
                            'action_taken_by' => $user->id,
                            'action_taken_at' => now(),
                        ]);



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

                    $user_service_application->application_data = json_decode($user_service_application->application_data);
                    DB::commit();

                    return response()->json([
                        'status'  => 1,
                        'message' => 'Application updated successfully.',
                        'data' => $user_service_application
                    ], 200);
                } else {

                    $application_data = (array) $request->input('application_data', []);

                    foreach ($request->file('application_data', []) as $question_id => $uploaded_file) {

                        if (!$uploaded_file) {
                            continue;
                        }

                        $filename = uniqid() . '.' . $uploaded_file->getClientOriginalExtension();
                        $path = $uploaded_file->storeAs("uploads/$user->id/applications", $filename, 'public');
                        $application_data[$question_id] = $path;
                    }
                    $request->merge(['application_data' => $application_data]);


                    $user_service_application = UserServiceApplication::create([
                        'user_id'               => $user->id,
                        'service_id'            => $request->service_id,
                        'renewal_cycle_id'      => $request->renewal_cycle_id,
                        'renewal'               => $request->renewal,
                        'renewalYear'           => $request->renewalYear,
                        'applicationId'         => $application_number,
                        'application_date'      => $request->application_date ?? now(),
                        'status'                => $request->status ?? 'saved',
                        'application_data'      => json_encode($application_data ?: null),
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
                        'current_step_number'   => $approval_flow->step_number,
                        'max_processing_date'   => $max_processing_date,
                    ]);


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

                    DB::commit();

                    return response()->json([
                        'status'  => 1,
                        'message' => 'Application created successfully.',
                        'data' => [
                            'id' => $user_service_application->id,
                            'applicationId' => $user_service_application->applicationId,
                            'service_id' => $user_service_application->service_id,
                            'user_id' => $user_service_application->user_id,
                            'status' => $user_service_application->status,
                            'final_fee' => $final_fee,
                            'extra_payment' => $user_service_application->extra_payment,
                            'total_fee' => $total_fee,
                            'current_step_number' => $approval_flow->step_number,
                            'assigned_department_id' => $approval_flow->department_id,
                            'assigned_hierarchy_level' => $approval_flow->hierarchy_level,
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
            ], 500);
        }
    }

    private function validate_questionnaire_file_inputs(Request $request): void
    {

        $service_id = $request->service_id;

        $file_questions = ServiceQuestionnaire::where('service_id', $service_id)
            ->whereIn('question_type', ['file', 'image'])
            ->where('status', 1)
            ->get(['id', 'question_type', 'validation_rule']);

        if ($file_questions->isEmpty()) {
            return;
        }

        $rules = [];

        foreach ($file_questions as $question) {
            $field_key = 'application_data.' . $question->id;

            $existing_value = $request->input($field_key);
            if (is_string($existing_value) && (
                str_starts_with($existing_value, 'uploads/') ||
                str_starts_with($existing_value, 'http://') ||
                str_starts_with($existing_value, 'https://')
            )) {
                continue;
            }

            $rule_string = 'nullable|file';
            $validation_rule = $question->validation_rule ? json_decode($question->validation_rule, true) : [];

            if (!empty($validation_rule['mimes'])) {
                $rule_string .= '|mimes:' . implode(',', $validation_rule['mimes']);
            }

            if (!empty($validation_rule['max_size_mb']) && $validation_rule['max_size_mb'] > 0) {
                $rule_string .= '|max:' . ($validation_rule['max_size_mb'] * 1024);
            }

            $rules[$field_key] = $rule_string;
        }

        if (!empty($rules)) {
            $request->validate($rules);
        }
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
                'status'                => 'in:submitted,under_review,approved,rejected,re_submitted,send_back,saved',
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
                'current_step_number'   => 'nullable|date',
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

            $user_service_application->fill($request->except(['id', 'NOC_certificate', 'NOC_rejection_certificate']));

            if ($request->has('application_data')) {
                $user_service_application->application_data = json_encode($request->application_data);
            }

            $application_id =  $user_service_application->id;
            $fee_data = $this->calculate_final_fee($request->service_id, $request->application_data, $application_id);
            $final_fee = $fee_data['final_fee'];
            $blc_fee = $fee_data['effective_fee'];
            $previous_paid = $fee_data['previous_paid'];
            $approval_flow = ServiceApprovalFlow::where('service_id', $request->service_id)
                ->orderBy('step_number', 'asc')
                ->first();

            $application_date = Carbon::parse($request->NOC_application_date ?? now());
            $target_days = $service->target_days ?? 0;
            $max_processing_date = $this->add_working_days($application_date, $target_days);

            $total_fee =  $final_fee;
            $previous_paid = $user_service_application->paid_amount ?? 0;
            $effective_fee = $total_fee - $previous_paid;
            if ($effective_fee < 0) {
                $effective_fee = 0;
            }

            $user_service_application->update([
                'user_id'               => $user->id,
                'service_id'            => $request->service_id,
                'renewal_cycle_id'      => $request->renewal_cycle_id,
                'renewal'               => $request->renewal,
                'renewalYear'           => $request->renewalYear,
                'applicationId'         => $request->applicationId,
                'application_date'      => $request->application_date ?? now(),
                'status'                => $request->status ?? 're_submitted',
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
                'current_step_number'   => $approval_flow->step_number,
                'max_processing_date'   => $max_processing_date
            ]);

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
                    'current_step_number' => $approval_flow->step_number,
                    'assigned_department_id' => $approval_flow->department_id,
                    'assigned_hierarchy_level' => $approval_flow->hierarchy_level,
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

            switch ($rule->fee_type) {
                case 'hardcoded':
                    if (!empty($rule->fixed_calculated_fee)) {
                        $final_fee += (float) $rule->fixed_calculated_fee;
                    }
                    break;

                case 'calculated':
                case 'estimated':
                    $temp_fee = 0;

                    if (!empty($rule->per_unit_fee)) {
                        $temp_fee += $user_answer * (float) $rule->per_unit_fee;
                    }

                    if (!empty($rule->fixed_calculated_fee)) {
                        $temp_fee += (float) $rule->fixed_calculated_fee;
                    }

                    $final_fee += $temp_fee;
                    break;
            }

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

        if ($application_id) {
            $existing_application = UserServiceApplication::find($application_id);
            if ($existing_application) {
                $previous_paid = $existing_application->paid_amount ?? 0;
                $db_extra_payment   = $existing_application->extra_payment ?? 0;
            }
        }

        $extra_payment = is_numeric($request_extra_payment)
            ? (float)$request_extra_payment
            : (float)$db_extra_payment;

        $final_fee += $extra_payment;

        $effective_fee = max($final_fee - $previous_paid, 0);

        return [
            'final_fee'     => round($final_fee, 2),
            'previous_paid' => round($previous_paid, 2),
            'effective_fee' => round($effective_fee, 2)
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
            $request_extra_payment = $request->extra_payment;

            $final_fee = $this->calculate_final_fee($service_id, $application_data, $application_id, $request_extra_payment);

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

            $service_user_application->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'User Service application deleted successfully.',
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
                'message' => $e->getMessage(),
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
            ]);

            $service_user_application = UserServiceApplication::where('user_id', $request->user_id)->get();

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

            $application = UserServiceApplication::where('service_id', $request->service_id)
                ->where('id', $request->application_id)
                ->first();

            if (!$application) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Application not found for this service.',
                ], 404);
            }

            $application->application_data = json_decode($application->application_data, true) ?: [];

            $application_data = $application->application_data;
            $formatted_data   = [];

            if (!empty($application_data)) {
                $question_ids = array_keys($application_data);

                $questions = ServiceQuestionnaire::whereIn('id', $question_ids)
                    ->get(['id', 'question_label', 'question_type'])
                    ->keyBy('id');

                foreach ($application_data as $question_id => $answer) {
                    $question = $questions->get($question_id);

                    if ($question && $question->question_type === 'file' && $answer) {
                        $answer = asset('storage/' . $answer);
                    }

                    $formatted_data[] = [
                        'id'       => $question_id,
                        'question' => $question->question_label ?? 'Question not found',
                        'answer'   => $answer ?? null,
                    ];
                }
            }

            $history_data = ApplicationWorkflowHistory::where('application_id', $application->id)
                ->orderByDesc('id')
                ->first();

            if ($application->service && $application->service->service_mode === 'third_party') {

                $third_party_logs = ThirdPartyStatusLog::where('application_id', $application->id)
                    ->orderByDesc('id')
                    ->first();
                if ($third_party_logs) {
                    $history_data =  [
                        'application_id'         => $third_party_logs->application_id,
                        'service_status'     => $third_party_logs->service_status,
                        'application_date'     => $third_party_logs->application_date,
                        'payment_amount'     => $third_party_logs->payment_amount,
                        'payment_status'     => $third_party_logs->payment_status,
                        'remarks'    => $third_party_logs->remarks,
                        'noc_file'       => !empty($third_party_logs->file)
                            ? asset('storage/' . $third_party_logs->file)
                            : null,
                        'updated_at' => $third_party_logs->updated_at,
                    ];
                } else {
                    $history = ApplicationWorkflowHistory::where('application_id', $application->id)
                        ->orderByDesc('id')
                        ->first();

                    if ($history) {
                        $history_data = [
                            'id'              => $history->id,
                            'step_number'     => $history->step_number,
                            'status'          => $history->status,
                            'remarks'         => $history->remarks,
                            'status_file'     => $history->status_file
                                ? asset('storage/' . $history->status_file)
                                : null,
                            'action_taken_at' => $history->action_taken_at,
                            'action_taken_by' => $history->action_taken_by,
                        ];
                    }
                }
            }

            return response()->json([
                'status'            => 1,
                'message'           => 'Service user application fetched successfully.',
                'data'              => $application,
                'application_data'  => $formatted_data,
                'history_data'    => $history_data,
                'service_name'    => $application->service->service_title_or_description,
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
        $application_date = Carbon::parse($request->NOC_application_date ?? now());
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
                'is_third_party'   => $request->is_third_party,
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
                'application_id'            =>  $user_service_application->external_application_id,
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
                    'applicationId' => $user_service_application->applicationId,
                    'redirect_url' => $user_service_application->service->redirect_url,
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

        try {


            $external_id        = $request->input('applicationId');
            $status            = $request->input('status');
            $payment_status     = $request->input('payment_status');
            $max_processing_date = $request->input('max_processing_date');
            $noc_number         = $request->input('noc_number');
            $noc_valid_till      = $request->input('noc_valid_till');
            $remarks           = $request->input('remarks');
            $service_id        = $request->input('service_id');
            $user_id          = $request->input('user_id');
            $approved_fee      = $request->input('approved_fee');
            $extra_payment          = $request->input('extra_payment');


            $data = UserServiceApplication::where('external_application_id', $external_id)->first();

            if ($data) {
                $data->update([
                    'status'              => $status,
                    'payment_status'      => $payment_status ?? $data->payment_status,
                    'max_processing_date' => $max_processing_date ?? $data->max_processing_date,
                    'noc_number'          => $noc_number ?? $data->noc_number,
                    'noc_valid_till'      => $noc_valid_till ?? $data->noc_valid_till,
                    'remarks'             => $remarks ?? $data->remarks,
                    'approved_fee'        => $approved_fee ?? $data->approved_fee,
                    'extra_payment'       => $extra_payment ?? $data->extra_payment,
                ]);
            } else {

                $data = UserServiceApplication::create([
                    'user_id'                 => $user_id,
                    'service_id'              => $service_id,
                    'external_application_id' => $external_id,
                    'applicationId'           => $external_id,
                    'status'                  => $status,
                    'payment_status'          => $payment_status ?? 'pending',
                    'max_processing_date'     => $max_processing_date,
                    'noc_number'              => $noc_number,
                    'noc_valid_till'          => $noc_valid_till,
                    'remarks'                 => $remarks,
                    'bin'                     => $request->input('bin'),
                    'approved_fee'            => $approved_fee,
                    'extra_payment'           => $extra_payment,
                ]);
            }

            //$redirectUrl = env('APP_FRONTEND_URL') . "/dashboard/user-app-view/{$service_id}/{$data->id}?service=third_party";
            $redirectUrl = "http://localhost:4200/dashboard/user-app-view/{$service_id}/{$data->id}?service=third_party";
            return redirect()->away($redirectUrl);

            // return response()->json([
            //     'status' => 1,
            //     'message' => 'Service user application fetched successfully.',
            //     'data' => $data
            // ]);
        } catch (\Exception $e) {


            return response()->json([
                'success' => 0,
                'message' => 'Failed to process callback',
                'error' => $e->getMessage(),
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
            ]);

            $log = ThirdPartyStatusLog::where('application_id', $request->application_id)->first();

            $data = [
                'service_id'         => $request->service_id,
                'application_id'     => $request->application_id,
                'swaagat_user_id'    => $request->swaagat_user_id,
                'service_status'     => $request->service_status,
                'mobile_no'          => $request->mobile_no,
                'application_date'   => $request->application_date,
                'updation_date'      => $request->updation_date,
                'action_by'          => $request->action_by,
                'remark'             => $request->remark,
                'payment_amount'     => $request->payment_amount,
                'payment_status'     => $request->payment_status,
                'payment_url'        => $request->payment_url,
                'egras_account_head' => $request->egras_account_head,
                'noc_url'            => $request->noc_url,
                'noc_file'           => $request->noc_file,
            ];

            if ($log) {
                $log->update([
                    'service_id'         => $request->service_id,
                    'application_id'     => $request->application_id,
                    'swaagat_user_id'    => $request->swaagat_user_id,
                    'service_status'     => $request->service_status,
                    'mobile_no'          => $request->mobile_no,
                    'application_date'   => $request->application_date,
                    'updation_date'      => $request->updation_date,
                    'action_by'          => $request->action_by,
                    'remark'             => $request->remark,
                    'payment_amount'     => $request->payment_amount,
                    'payment_status'     => $request->payment_status,
                    'payment_url'        => $request->payment_url,
                    'egras_account_head' => $request->egras_account_head,
                    'noc_url'            => $request->noc_url,
                    'noc_file'           => $request->noc_file,
                ]);
            } else {

                ThirdPartyStatusLog::create([
                    'service_id'         => $request->service_id,
                    'application_id'     => $request->application_id,
                    'swaagat_user_id'    => $request->swaagat_user_id,
                    'service_status'     => $request->service_status,
                    'mobile_no'          => $request->mobile_no,
                    'application_date'   => $request->application_date,
                    'updation_date'      => $request->updation_date,
                    'action_by'          => $request->action_by,
                    'remark'             => $request->remark,
                    'payment_amount'     => $request->payment_amount,
                    'payment_status'     => $request->payment_status,
                    'payment_url'        => $request->payment_url,
                    'egras_account_head' => $request->egras_account_head,
                    'noc_url'            => $request->noc_url,
                    'noc_file'           => $request->noc_file,
                ]);
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

    public function get_all_applications_list()
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }


            $applications = UserServiceApplication::orderBy('id', 'DESC')->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No applications found.',
                ], 404);
            }

            $response_data = [];

            foreach ($applications as $app) {
                $response_data[] = [
                    'id' => $app->id,
                    'application_number' => $app->applicationId,
                    'business' => $app->user->name_of_enterprise ?? null,
                    'email_id' => $app->user->email_id ?? null,
                    'mobile_no' => $app->user->mobile_no ?? null,
                    'amount' => $app->total_fee ?? 0,
                    'expiry_date' => $app->NOC_expiry_date ?? null,
                    'status' => $app->payment_status,
                    'method' => null,
                    'comments' => $app->comments,
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Applications fetched successfully.',
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

            $application->update([
                'GRN_number'     => $request->GRN_number,
                'comments'       => $request->comments,
                'payment_status' => 'paid',
                'payment_time'   => now()
            ]);

            return response()->json([
                'status' => 1,
                'message' => 'Application marked as paid successfully.',
                'data' => [
                    'application_id' => $application->id,
                    'payment_status' => $application->payment_status,
                    'GRN_number'     => $application->GRN_number,
                    'comments'       => $application->comments,
                    'payment_time'   => $application->payment_time,
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
}
