<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceApprovalFlow;
use App\Models\ServiceMaster;

class ServiceApprovalFlowController extends Controller
{
    public function service_approval_flow_store(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

            $request->validate([
                'flows' => 'required|array',
                'flows.*.service_id' => 'required|integer|exists:service_masters,id',
                'flows.*.step_type' => 'required|in:validation,review,screening,scrutiny,approval',
                'flows.*.department_id' => 'required|integer|exists:departments,id',
                'flows.*.hierarchy_level' => 'required|in:block,subdivision1,subdivision2,subdivision3,district1,district2,district3,state1,state2,state3',
            ]);

            DB::beginTransaction();

            $service_approval_flows = [];

            foreach ($request->flows as $flow) {

                $last_step = ServiceApprovalFlow::where('service_id', $flow['service_id'])
                    ->max('step_number');

                $next_step = $last_step ? $last_step + 1 : 1;

                $service_approval_flow = ServiceApprovalFlow::create([
                    'service_id'      => $flow['service_id'],
                    'step_number'     => $next_step,
                    'step_type'       => $flow['step_type'],
                    'department_id'   => $flow['department_id'],
                    'hierarchy_level' => $flow['hierarchy_level'],
                    'created_by'      => $admin->email_id
                ]);

                $service_approval_flows[] = $service_approval_flow;
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service approval flows saved successfully.',
                'data' => $service_approval_flows
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
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

    public function service_approval_flow_update(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

            $request->validate([
                'flows' => 'required|array',
                'flows.*.id' => 'nullable|integer|exists:service_approval_flows,id',
                'flows.*.service_id' => 'required|integer|exists:service_masters,id',
                'flows.*.step_type' => 'required|in:validation,review,screening,scrutiny,approval',
                'flows.*.department_id' => 'required|integer|exists:departments,id',
                'flows.*.hierarchy_level' => 'required|in:block,subdivision1,subdivision2,subdivision3,district1,district2,district3,state1,state2,state3',
            ]);

            DB::beginTransaction();

            $service_approval_flows = [];

            foreach ($request->flows as $flow) {

                $service = ServiceMaster::where('id', $flow['service_id'])
                    ->where('department_id', $flow['department_id'])
                    ->first();

                $service_approval_flow = ServiceApprovalFlow::findOrFail($flow['id']);

                $step_number = $flow['step_number'] ?? $service_approval_flow->step_number;
                if (!$step_number) {
                    $last_step = ServiceApprovalFlow::where('service_id', $flow['service_id'])
                        ->max('step_number');
                    $step_number = $last_step ? $last_step + 1 : 1;
                }

                $service_approval_flow->update([
                    'service_id'      => $flow['service_id'],
                    'step_number'     => $step_number,
                    'step_type'       => $flow['step_type'],
                    'department_id'   => $flow['department_id'],
                    'hierarchy_level' => $flow['hierarchy_level'],
                    'updated_by'      => $admin->email_id
                ]);

                $service_approval_flows[] = $service_approval_flow;
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service approval flows updated successfully.',
                'data' => $service_approval_flows
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
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

    public function service_approval_flow_view(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service_approval_flows = ServiceApprovalFlow::where('service_id', $request->service_id)
                ->orderBy('id', 'asc')
                ->get();

            if ($service_approval_flows->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No service approval flow found for the given service_id.',
                ], 404);
            }

            $data = $service_approval_flows->map(function ($flow) {
                return [
                    'id' => $flow->id,
                    'service_id' => $flow->service_id,
                    'step_number' => $flow->step_number,
                    'step_type' => $flow->step_type,
                    'department_id' => $flow->department_id,
                    'department_name' => $flow->department->name,
                    'hierarchy_level' => $flow->hierarchy_level,
                    'created_by' => $flow->created_by,
                    'updated_by' => $flow->updated_by,
                    'created_at' => $flow->created_at,
                    'updated_at' => $flow->updated_at,
                ];
            });

            return response()->json([
                'status' => 1,
                'message' => 'Service approval flow fetched successfully.',
                'data' => $data,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function service_approval_flow_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:service_approval_flows,id',
            ]);

            DB::beginTransaction();

            $service_approval_flow = ServiceApprovalFlow::where('id', $request->id)->first();

            if (!$service_approval_flow) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Service Approval Flow not found.'
                ], 404);
            }

            $service_approval_flow->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service Approval Flow deleted successfully.',
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
}
