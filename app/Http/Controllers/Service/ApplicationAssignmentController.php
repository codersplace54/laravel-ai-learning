<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\ApplicationWorkflowAssignment;
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
                'application_id' => 'required|exists:user_service_applications,id'
            ]);

            $assignments = ApplicationWorkflowAssignment::where('application_id', $request->application_id)
                ->with(['department:id,name', 'actionTaker:id,authorized_person_name,email_id'])
                ->orderBy('step_number')
                ->get();

            return response()->json([
                'status'  => 1,
                'message' => 'Application assignments fetched successfully',
                'data'    => $assignments
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
            ]);

            $assignment = ApplicationWorkflowAssignment::create([
                'application_id'  => $request->application_id,
                'step_number'     => $request->step_number,
                'step_type'       => $request->step_type,
                'department_id'   => $request->department_id,
                'hierarchy_level' => $request->hierarchy_level,
                'status'          => $request->status,
                'remarks'         => $request->remarks,
                'action_taken_by' => $request->action_taken_by ?? null,
                'action_taken_at' => $request->action_taken_by ? now() : null,
            ]);

            UserServiceApplication::where('id', $request->application_id)->update([
                'current_step_number' => $request->step_number,
                //status also can be updated, check if latest assignment is being updated
            ]);

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
                'hierarchy_level' => 'nullable|string',
                'status'          => 'nullable|in:pending,approved,rejected,send_back,extra_payment,re_submitted',
                'remarks'         => 'nullable|string',
                'action_taken_by' => 'nullable|exists:users,id',
            ]);

            $assignment = ApplicationWorkflowAssignment::findOrFail($request->assignment_id);

            $assignment->update(array_filter([
                'step_number'     => $request->step_number,
                'step_type'       => $request->step_type,
                'department_id'   => $request->department_id,
                'hierarchy_level' => $request->hierarchy_level,
                'status'          => $request->status,
                'remarks'         => $request->remarks,
                'action_taken_by' => $request->action_taken_by,
                'action_taken_at' => $request->action_taken_by ? now() : null,
            ], fn($v) => !is_null($v)));

            if ($request->filled('step_number')) {
                UserServiceApplication::where('id', $assignment->application_id)->update([
                    'current_step_number' => $request->step_number,
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
}
