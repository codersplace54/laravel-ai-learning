<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\DepartmentUser;
use App\Models\UserServiceApplication;
use App\Models\PaymentOrder;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApplicationAssignmentController extends Controller
{
    use LogsActivity;
    public function get_application_assignments(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || $user->user_type !== 'admin') {
                return response()->json(['status' => 0, 'message' => 'Unauthorized. Admin access only.'], 403);
            }

            $request->validate([
                'application_id' => 'required',
            ]);

            $application = UserServiceApplication::with([
                'user:id,name_of_enterprise,authorized_person_name,mobile_no,user_name,district_id,subdivision_id,ulb_id,ward_id',
                'user.district:id,district_name,district_code',
                'user.subdivision:id,sub_division,sub_lgd_code',
                'user.ulb:id,ulb_name,ulb_lgd_code',
                'user.ward:id,name_of_gp_vc_or_ward,gp_vc_ward_lgd_code',
                'service:id,service_title_or_description',
            ])
                ->where('id', $request->application_id)
                ->orWhere('applicationId', $request->application_id)
                ->first();

            if (!$application) {
                return response()->json(['status' => 0, 'message' => 'Application not found'], 404);
            }

            $assignments = ApplicationWorkflowAssignment::where('application_id', $application->id)
                ->with(['department:id,name', 'actionTaker:id,authorized_person_name,email_id'])
                ->orderBy('step_number')
                ->get();

            $data = [
                'assignments' => $assignments,
                'application' => [
                    'id'                  => $application->id,
                    'applicationId'       => $application->applicationId,
                    'service'             => $application->service?->service_title_or_description,
                    'current_step_number' => $application->current_step_number,
                    'status'              => $application->status,
                    'payment_status'      => $application->payment_status,
                    'final_fee'           => $application->final_fee,
                    'paid_fee'            => $application->paid_amount
                ],
                'user'    => [
                    'name_of_enterprise'     => $application->user->name_of_enterprise,
                    'authorized_person_name' => $application->user->authorized_person_name,
                    'mobile_no'        => $application->user->mobile_no,
                    'user_name'        => $application->user->user_name,
                    'district'         => $application->user->district->district_name ?? null,
                    'subdivision_name' => $application->user->subdivision->sub_division ?? null,
                    'ulb_name'         => $application->user->ulb->ulb_name ?? null,
                    'ward_name'        => $application->user->ward->name_of_gp_vc_or_ward ?? null,
                ]
            ];
            return response()->json([
                'status'  => 1,
                'message' => 'Application assignments fetched successfully',
                'data'    => $data,

            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to fetch assignments', 'error' => $e->getMessage()], 500);
        }
    }

    public function create_application_assignment(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || $user->user_type !== 'admin') {
                return response()->json(['status' => 0, 'message' => 'Unauthorized. Admin access only.'], 403);
            }

            $request->validate([
                'application_id'  => 'required|exists:user_service_applications,id',
                'step_number'     => 'required|integer',
                'step_type'       => 'required|in:validation,review,screening,scrutiny,approval',
                'department_id'   => 'required|exists:departments,id',
                'hierarchy_level' => 'required|in:block,subdivision1,subdivision2,subdivision3,district1,district2,district3,state1,state2,state3',
                'status'          => 'required|in:pending,approved,rejected,send_back,extra_payment,re_submitted,saved,in_progress',
                'action_taken_by' => 'nullable|exists:users,id',
            ]);

            $application = UserServiceApplication::where('id', $request->application_id)->first();

            if (!$application) {
                return response()->json(['status' => 0, 'message' => 'Application not found'], 404);
            }

            $assignment = ApplicationWorkflowAssignment::create([
                'application_id'  => $application->id,
                'service_id'      => $application->service_id,
                'step_number'     => $request->step_number,
                'step_type'       => $request->step_type,
                'department_id'   => $request->department_id,
                'hierarchy_level' => $request->hierarchy_level,
                'status'          => $request->status,
                'remarks'         => $request->remarks,
                'action_taken_by' => $request->action_taken_by ?? Auth::id(),
                'action_taken_at' => now(),
            ]);

            $status_map = [
                'pending' => 'under_review',
                'approved' => 'approved',
                'rejected' => 'rejected',
                'send_back' => 'send_back',
                'extra_payment' => 'extra_payment',
                're_submitted' => 're_submitted',
                'saved' => 'saved',
                'in_progress' => 'under_review'
            ];

            $application_status = $status_map[$request->status];

            if ($application->current_step_number < $request->step_number) {
                $application->update([
                    'current_step_number' => $request->step_number,
                    'status' => $application_status,
                ]);
            }

            $this->logActivity(
                $user->user_name . ' created assignment for application #' . $application->applicationId,
                $assignment,
                $user,
                [
                    'new' => [
                        'application_id'  => $assignment->application_id,
                        'step_number'     => $assignment->step_number,
                        'step_type'       => $assignment->step_type,
                        'department_id'   => $assignment->department_id,
                        'hierarchy_level' => $assignment->hierarchy_level,
                        'status'          => $assignment->status,
                        'remarks'         => $assignment->remarks,
                        'action_taken_by' => $assignment->action_taken_by,
                    ],
                ],
                'Assignment Created'
            );

            return response()->json([
                'status'  => 1,
                'message' => 'Assignment created successfully',
                'data'    => $assignment
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to create assignment', 'error' => $e->getMessage()], 500);
        }
    }

    public function update_application_assignment(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || $user->user_type !== 'admin') {
                return response()->json(['status' => 0, 'message' => 'Unauthorized. Admin access only.'], 403);
            }

            $request->validate([
                'assignment_id'   => 'required|exists:application_workflow_assignments,id',
                'step_number'     => 'nullable|integer',
                'step_type'       => 'nullable|string',
                'department_id'   => 'nullable|exists:departments,id',
                'hierarchy_level' => 'nullable|in:block,subdivision1,subdivision2,subdivision3,district1,district2,district3,state1,state2,state3',
                'status'          => 'nullable|in:pending,approved,rejected,send_back,extra_payment,re_submitted',
                'remarks'         => 'nullable|string',
                'action_taken_by' => 'nullable|exists:users,id',
            ]);

            $assignment = ApplicationWorkflowAssignment::where('id', $request->assignment_id)->first();

            $application = UserServiceApplication::find($assignment->application_id);

            if (!$application) {
                return response()->json(['status' => 0, 'message' => 'Application not found'], 404);
            }

            $old_assignment = $assignment->only([
                'step_number',
                'step_type',
                'department_id',
                'hierarchy_level',
                'status',
                'remarks',
                'action_taken_by',
            ]);

            $assignment->update([
                'step_number'     => $request->step_number ?? $assignment->step_number,
                'step_type'       => $request->step_type ?? $assignment->step_type,
                'department_id'   => $request->department_id ?? $assignment->department_id,
                'hierarchy_level' => $request->hierarchy_level ?? $assignment->hierarchy_level,
                'status'          => $request->status ?? $assignment->status,
                'remarks'         => $request->remarks ?? $assignment->remarks,
                'action_taken_by' => $request->action_taken_by ?? Auth::id(),
                'action_taken_at' => now(),
            ]);

            $status_map = [
                'pending' => 'under_review',
                'approved' => 'approved',
                'rejected' => 'rejected',
                'send_back' => 'send_back',
                'extra_payment' => 'extra_payment',
                're_submitted' => 're_submitted',
                'saved' => 'saved',
                'in_progress' => 'under_review'
            ];

            $application_status = $status_map[$request->status];

            if ($application->current_step_number < $request->step_number) {
                $application->update([
                    'current_step_number' => $request->step_number,
                    'status' => $application_status,
                ]);
            }

            $this->logActivity(
                $user->user_name . ' updated assignment #' . $assignment->id . ' for application #' . $application->applicationId,
                $assignment,
                $user,
                [
                    'old' => $old_assignment,
                    'new' => [
                        'step_number'     => $assignment->step_number,
                        'step_type'       => $assignment->step_type,
                        'department_id'   => $assignment->department_id,
                        'hierarchy_level' => $assignment->hierarchy_level,
                        'status'          => $assignment->status,
                        'remarks'         => $assignment->remarks,
                        'action_taken_by' => $assignment->action_taken_by,
                    ],
                ],
                'Assignment Updated'
            );

            return response()->json([
                'status'  => 1,
                'message' => 'Assignment updated successfully',
                'data'    => $assignment->fresh()
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to update assignment', 'error' => $e->getMessage()], 500);
        }
    }


    public function delete_application_assignment(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || $user->user_type !== 'admin') {
                return response()->json(['status' => 0, 'message' => 'Unauthorized. Admin access only.'], 403);
            }

            $request->validate([
                'assignment_id'   => 'required|exists:application_workflow_assignments,id',
            ]);

            $assignment = ApplicationWorkflowAssignment::find($request->assignment_id);

            $deleted_snapshot = $assignment->only([
                'application_id',
                'step_number',
                'step_type',
                'department_id',
                'hierarchy_level',
                'status',
                'remarks',
                'action_taken_by',
            ]);

            $assignment->delete();

            $this->logActivity(
                $user->user_name . ' deleted assignment #' . $request->assignment_id,
                null,
                $user,
                ['old' => $deleted_snapshot],
                'Assignment Deleted'
            );

            return response()->json([
                'status'  => 1,
                'message' => 'Assignment deleted successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to delete assignment', 'error' => $e->getMessage()], 500);
        }
    }

    public function update_user_service_application(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || $user->user_type !== 'admin') {
                return response()->json(['status' => 0, 'message' => 'Unauthorized. Admin access only.'], 403);
            }

            $request->validate([
                'application_id'  => 'required|exists:user_service_applications,id',
                'status'          => 'nullable|in:draft,submitted,under_review,approved,rejected,saved,extra_payment,re_submitted,send_back,noc_issued',
                'payment_status'  => 'nullable|in:pending,paid,failed',
                'current_step_number' => 'nullable|integer|min:1',
                'final_fee'       => 'nullable|numeric|min:0',
                'paid_amount'     => 'nullable|numeric|min:0',
            ]);

            $application = UserServiceApplication::where('id',$request->application_id)->first();

            $old_values = [
                'status'              => $application->status,
                'payment_status'      => $application->payment_status,
                'current_step_number' => $application->current_step_number,
                'final_fee'           => $application->final_fee,
                'paid_amount'         => $application->paid_amount,
            ];

            $new_values = [];

            if ($request->status !== null) {
                $new_values['status'] = $request->status;
            }

            if ($request->payment_status !== null) {
                $new_values['payment_status'] = $request->payment_status;
            }

            if ($request->current_step_number !== null) {
                $new_values['current_step_number'] = $request->current_step_number;
            }

            if ($request->final_fee !== null) {
                $new_values['final_fee'] = $request->final_fee;
            }

            if ($request->paid_amount !== null) {
                $new_values['paid_amount'] = $request->paid_amount;
            }

            $application->update($new_values);

            $this->logActivity(
                $user->user_name . ' updated application #' . $application->applicationId,
                $application,
                $user,
                [
                    'old' => array_intersect_key($old_values, $new_values),
                    'new' => $new_values,
                ],
                'Application Updated'
            );

            return response()->json([
                'status'  => 1,
                'message' => 'Application updated successfully',
                'data'    => $application->fresh(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to update application', 'error' => $e->getMessage()], 500);
        }
    }

    public function get_department_user_for_assignment(Request $request)
    {
        $request->validate([
            'department_id'   => 'required|exists:departments,id',
            'hierarchy_level' => 'required|in:block,subdivision1,subdivision2,subdivision3,district1,district2,district3,state1,state2,state3',
        ]);

        try {
            $department_users = DepartmentUser::where('department_id', $request->department_id)
                ->where('hierarchy_level', $request->hierarchy_level)
                ->with('user:id,authorized_person_name')
                ->get()->map(function ($dpt_user) {
                    return [
                        'id'      => $dpt_user->id,
                        'user_id' => $dpt_user->user_id,
                        'authorized_person_name'   => $dpt_user->user->authorized_person_name ?? null,
                    ];
                });

            return response()->json([
                'status'  => 1,
                'message' => 'Department users fetched successfully',
                'data'    => $department_users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch department users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_user_payment_orders(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || $user->user_type !== 'admin') {
                return response()->json(['status' => 0, 'message' => 'Unauthorized. Admin access only.'], 403);
            }

            $request->validate([
                'application_id' => 'required',
            ]);

            $order = PaymentOrder::whereJsonContains('application_id', $request->application_id)->get();

            if (!$order) {
                return response()->json(['status' => 0, 'message' => 'Application not found'], 404);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Application Payment orders fetched successfully',
                'data'    => $order,

            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to fetch assignments', 'error' => $e->getMessage()], 500);
        }
    }

    public function delete_payment_orders(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || $user->user_type !== 'admin') {
                return response()->json(['status' => 0, 'message' => 'Unauthorized. Admin access only.'], 403);
            }

            $request->validate([
                'payment_order_id' => 'required|exists:payment_orders,id',
            ]);

            $payment_order = PaymentOrder::where('id',$request->payment_order_id)->first();

            $deleted_snapshot = $payment_order->only([
                'application_id',
                'order_id',
                'user_id',
                'payment_amount',
                'payment_status',
                'gateway',
                'transaction_id',
                'GRN_number',
            ]);

            $payment_order->delete();

            $this->logActivity(
                $user->user_name . ' deleted payment order #' . $request->payment_order_id,
                null,
                $user,
                ['old' => $deleted_snapshot],
                'Payment Order Deleted'
            );

            return response()->json([
                'status'  => 1,
                'message' => 'Payment order deleted successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to delete payment order', 'error' => $e->getMessage()], 500);
        }
    }
}
