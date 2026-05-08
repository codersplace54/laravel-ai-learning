<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\DepartmentUser;
use App\Models\UserServiceApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApplicationAssignmentController extends Controller
{
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
                'user:id,name_of_enterprise,authorized_person_name,mobile_no,user_name',
                'service:id,service_title_or_description'
            ])
                ->where('id', $request->application_id)
                ->orWhere('applicationId', $request->application_id)
                ->first();

            if(!$application){
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
                ],
                'user'    => [
                    'name_of_enterprise' => $application->user->name_of_enterprise,
                    'authorized_person_name' => $application->user->authorized_person_name,
                    'mobile_no' => $application->user->mobile_no,
                    'user_name' => $application->user->user_name,
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

            $application = UserServiceApplication::where('id',$request->application_id)->first();

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

            $assignment = ApplicationWorkflowAssignment::where('id',$request->assignment_id)->first();

            $application = UserServiceApplication::find($assignment->application_id);

            if (!$application) {
                return response()->json(['status' => 0, 'message' => 'Application not found'], 404);
            }

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

    public function get_department_user_for_assignment(Request $request)
    {
        $request->validate([
            'department_id'   => 'required|exists:departments,id',
            'hierarchy_level' => 'required|in:block,subdivision1,subdivision2,subdivision3,district1,district2,district3,state1,state2,state3',
        ]);

        try {
            $department_users = DepartmentUser::where('department_id',$request->department_id)
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
                'message' => 'Failed to fetch department users', 'error' => $e->getMessage()
            ], 500);

        }
    }
}
