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
                'status'                => 'in:submitted,under_review,approved,rejected,re_submitted,send_back',
                'application_data'      => 'nullable|array',
                'applied_fee'           => 'nullable|numeric',
                'approved_fee'          => 'nullable|numeric',
                'payment_status'        => 'in:pending,paid,failed',
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
                'current_step_number'   => 'nullable|date',
                'max_processing_date'   => 'nullable|string',
            ]);

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

            $final_fee = $this->calculate_final_fee($request->service_id, $request->application_data);
            $approval_flow = ServiceApprovalFlow::where('service_id', $request->service_id)
                ->orderBy('step_number', 'asc')
                ->first();

            if (!$approval_flow) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'You cannot submit an application for this particular service; please contact the administrator.'
                ], 404);
            }

            $application_date = Carbon::parse($request->NOC_application_date ?? now());
            $target_days = $service->target_days ?? 0;
            $max_processing_date = $this->add_working_days($application_date, $target_days);

            $user_service_application = UserServiceApplication::where('user_id', $user->id)
                ->where('service_id', $request->service_id)
                ->first();

            if ($user_service_application) {

                if (!in_array($user_service_application->status, ['submitted', 're_submitted', 'send_back'])) {
                    return response()->json([
                        'status' => 0,
                        'message' => "You can't update the application. It's under " . $user_service_application->status . "."
                    ], 403);
                }

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
                    'current_step_number'   => $approval_flow->step_number,
                    'max_processing_date'   => $max_processing_date
                ]);

                ApplicationWorkflowAssignment::where('application_id', $user_service_application->id)
                    ->where('status', 'pending')
                    ->update([
                        'status'          => $request->status,
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

                DB::commit();

                return response()->json([
                    'status'  => 1,
                    'message' => 'Application updated successfully.',
                    'data' => $user_service_application
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
                    'message' => 'Application created successfully.',
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
                ], 201);
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
                'status'               => 'nullable|in:submitted,under_review,approved,rejected,re_submitted,send_back',
                'application_data'     => 'nullable|array',
                'applied_fee'          => 'nullable|numeric',
                'approved_fee'         => 'nullable|numeric',
                'payment_status'       => 'nullable|in:pending,paid,failed',
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

            $final_fee = $this->calculate_final_fee($request->service_id, $request->application_data);

            $approval_flow = ServiceApprovalFlow::where('service_id', $request->service_id)
                ->orderBy('step_number', 'asc')
                ->first();

            $application_date = Carbon::parse($request->NOC_application_date ?? now());
            $target_days = $service->target_days ?? 0;
            $max_processing_date = $this->add_working_days($application_date, $target_days);

            $user_service_application->update([
                'user_id'               => $user->id,
                'service_id'            => $request->service_id,
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


    public function calculate_final_fee($serviceId, $applicationData)
    {

        $rules = ServiceFeeRule::where('service_id', $serviceId)
            ->get();

        $final_fee = 0;

        foreach ($rules as $rule) {
            $user_answer = $applicationData[$rule->question_id] ?? null;

            if ($user_answer === null) {
                continue;
            }

            $match = false;

            switch ($rule->condition_operator) {
                case '=':
                    $match = ($user_answer == $rule->condition_value_start);
                    break;
                case '!=':
                    $match = ($user_answer != $rule->condition_value_start);
                    break;
                case '<':
                    $match = ($user_answer < $rule->condition_value_start);
                    break;
                case '<=':
                    $match = ($user_answer <= $rule->condition_value_start);
                    break;
                case '>':
                    $match = ($user_answer > $rule->condition_value_start);
                    break;
                case '>=':
                    $match = ($user_answer >= $rule->condition_value_start);
                    break;
                case 'between':
                    $match = ($user_answer >= $rule->condition_value_start && $user_answer <= $rule->condition_value_end);
                    break;
            }

            if ($match) {

                if ($rule->fee_type === 'hardcoded') {
                    $final_fee += (float) $rule->fixed_fee;
                } elseif (in_array($rule->fee_type, ['calculated', 'estimated'])) {
                    if (!empty($rule->per_unit_fee)) {
                        $final_fee += $user_answer * (float) $rule->per_unit_fee;
                    } elseif (!empty($rule->fixed_calculated_fee)) {
                        $final_fee += (float) $rule->fixed_calculated_fee;
                    } elseif (!empty($rule->calculated_fee)) {
                        $final_fee += $user_answer * (float) $rule->calculated_fee;
                    }
                }
            }
        }

        return $final_fee;
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
                    'application_data' => $service->application_data,
                    'service_title_or_description' => $service->service->service_title_or_description ?? null,
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
                    'latest_workflow_status' => $latest_workflow?->status ?? null
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
                'service_id' => 'required|integer|exists:service_masters,id',
                'application_id' => 'required|integer|exists:user_service_applications,id',
            ]);

            $service_user_application = UserServiceApplication::where('service_id', $request->service_id)
                ->where('id', $request->application_id)
                ->get();

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
}
