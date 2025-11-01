<?php

namespace App\Http\Controllers\Inspection;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Inspection;
use App\Models\Department;
use App\Models\DepartmentUser;
use App\Models\User;

class InspectionController extends Controller
{

    public function inspection_store(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
                'proposed_date'     => 'required|date',
                'reason_for_request'  => 'nullable|string',
                'remarks'             => 'nullable|string'
            ]);

            DB::beginTransaction();

            $inspection = Inspection::create([
                'user_id'            => $user->id,
                'department_id'      => $request->department_id,
                'proposed_date'      => $request->proposed_date,
                'reason_for_request' => $request->reason_for_request,
                'remarks'            => $request->remarks,
                'inspection_type'    => 'On Request',
                'status'             => 'pending',
                'created_by'         => $user->email_id,
            ]);

            $department_code = str_pad($inspection->department_id, 2, '0', STR_PAD_LEFT);
            $formatted_id = str_pad($inspection->id, 6, '0', STR_PAD_LEFT);
            $request_id = "REQ-{$department_code}-{$formatted_id}";
            $inspection->update(['request_id' => $request_id]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Inspection created successfully.',
                'data'    => $inspection,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'error'   => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to add Inspection Request.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function get_inspection_departments()
    {
        $departments = Department::whereIn('name', [
            'Factories & Boilers Organisation',
            'Directorate of Labour',
            'Legal Metrology',
            'Tripura State Pollution Control Board'
        ])->get();

        return response()->json([
            'status' => 1,
            'data' => $departments
        ]);
    }

    public function inspection_list()
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $inspections = Inspection::where('user_id', $user->id)
                ->where('status', '!=', 'Date Confirmed')
                ->orderBy('inspection_date', 'desc')
                ->get()
                ->map(function ($inspection) {


                    return [
                        'request_id'                => $inspection->request_id,
                        'proposed_inspection_date'  => $inspection->inspection_date,
                        'inspection_type'           => 'On Request',
                        'industry_name'             => $inspection->user->name_of_enterprise ?? 'N/A',
                        'inspector'                 => null,
                        'status'                    => $inspection->status,
                        'created_by'                => $inspection->created_by,
                        'updated_by'                => $inspection->updated_by,
                    ];
                });

            if ($inspections->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No inspections found.',
                    'data'    => []
                ], 200);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Inspections fetched successfully.',
                'data'    => $inspections
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function inspection_view(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'inspection_id' => 'required|integer|exists:inspections,id',
            ]);

            $inspection = Inspection::where('id', $request->inspection_id)->first();

            $data = [
                'request_id'                  => $inspection->request_id,
                'proposed_date'               => $inspection->proposed_date,
                'inspection_date'             => $inspection->inspection_date ?? 'N/A',
                'department_name'             => $inspection->department->name ?? 'N/A',
                'inspector'                   => $inspection->inspectorUser ? $inspection->inspectorUser->authorized_person_name : 'N/A',
                'reason_for_request'          => $inspection->reason_for_request ?? '',
                'status'                      => $inspection->status,
                'remarks'                     => $inspection->remarks ?? '',
                'created_at'                  => $inspection->created_at,
                'updated_at'                  => $inspection->updated_at,
                'created_by'                  => $inspection->created_by,
                'updated_by'                  => $inspection->updated_by,
            ];

            return response()->json([
                'status'  => 1,
                'message' => 'Inspection details fetched successfully.',
                'data'    => $data
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function inspection_update(Request $request)
    {


        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }


            $request->validate([
                'id'                => 'required|integer|exists:inspections,id',
                'department_id'     => 'nullable|integer|exists:departments,id',
                'proposed_date'     => 'nullable|date',
                'reason_for_request' => 'nullable|string|max:255',
                'remarks'           => 'nullable|string|max:255',
                'status'            => 'nullable|string|in:pending,approved,rejected,completed',
            ]);

            DB::beginTransaction();

            $inspection = Inspection::where('id', $request->id)->first();

            if (!$inspection) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Inspection not found.',
                ], 404);
            }


            $inspection->update([
                'department_id'      => $request->department_id ?? $inspection->department_id,
                'proposed_date'       => $request->proposed_date ?? $inspection->proposed_date,
                'reason_for_request' => $request->reason_for_request ?? $inspection->reason_for_request,
                'remarks'            => $request->remarks ?? $inspection->remarks,
                'status'             => $request->status ?? $inspection->status,
                'updated_by'         => $user->email_id,
            ]);

            $department_code = str_pad($inspection->department_id, 2, '0', STR_PAD_LEFT);
            $formatted_id = str_pad($inspection->id, 6, '0', STR_PAD_LEFT);
            $request_id = "REQ-{$department_code}-{$formatted_id}";
            $inspection->update(['request_id' => $request_id]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Inspection updated successfully.',
                'data'    => $inspection
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'error'   => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to update.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function inspection_delete(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:inspections,id',
            ]);

            DB::beginTransaction();

            $inspection = Inspection::where('id', $request->id)->first();

            if (!$inspection) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Inspection not found.'
                ], 404);
            }

            $inspection->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Inspection deleted successfully.',
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
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function inspections_by_department(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
            ]);

            $inspections = Inspection::where('department_id', $request->department_id)
                ->where('status', 'pending')
                ->orderBy('proposed_date', 'desc')
                ->get();

            if ($inspections->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No inspections found for this department.',
                    'data'    => []
                ], 200);
            }

            $data = $inspections->map(function ($inspection) {

                return [
                    'id'               => $inspection->id,
                    'request_id'       => $inspection->request_id,
                    'proposed_date'    => $inspection->proposed_date,
                    'inspection_type'  => $inspection->inspection_type ?? 'On Request',
                    'industry_name'    => $inspection->user->name_of_enterprise ?? 'N/A',
                    'inspector'        => $inspection->inspectorUser ? $inspection->inspectorUser->authorized_person_name : 'Not Assigned',
                    'status'           => $inspection->status,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Inspections fetched successfully.',
                'data'    => $data
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function inspections_status_update(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id'     => 'required|integer|exists:inspections,id',
                'inspector' => 'nullable|integer|exists:users,id',
                //'inspection_date' => 'nullable|date',
                'status' => 'required|string',
            ]);

            DB::beginTransaction();

            $inspection = Inspection::where('id', $request->id)->first();

            if (!$inspection) {

                DB::rollBack();

                return response()->json([
                    'status'  => 0,
                    'message' => 'Inspection not found.',
                ], 404);
            }

            $inspection->update([
                'inspector'     => $request->inspector,
                // 'inspection_date'  => $request->inspection_date,
                'status'     => $request->status,
                'updated_by' => $user->email_id,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Inspection status updated successfully.',
                'data'    => [
                    'id'          => $inspection->id,
                    'request_id'  => $inspection->request_id,
                    'status'  => $inspection->status,
                    'inspector'  => $inspection->inspectorUser ? $inspection->inspectorUser->authorized_person_name : 'N/A',
                    // 'inspection_date'  => $inspection->inspection_date,
                    'updated_by'  => $inspection->updated_by,
                    'updated_at'  => $inspection->updated_at,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


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

    public function approved_inspections_list()
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }


            $is_inspector = DepartmentUser::where('user_id', $user->id)
                ->where('inspector', 'yes')
                ->exists();

            if (!$is_inspector) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Access denied. You are not authorized to view this list.',
                ], 403);
            }

            $inspections = Inspection::with([
                'user:id,name_of_enterprise,authorized_person_name',
                'department:id,name'
            ])
                ->where('status', 'approved')
                ->where('inspector', $user->id)
                ->orderByDesc('updated_at')
                ->get([
                    'id',
                    'request_id',
                    'department_id',
                    'user_id',
                    'proposed_date',
                    'reason_for_request',
                    'remarks',
                    'status',
                    'updated_at'
                ]);


            $data = $inspections->map(function ($item) {
                return [
                    'id'               => $item->id,
                    'user_name'        => $item->user->authorized_person_name,
                    'request_id'       => $item->request_id,
                    'department_name'  => $item->department->name ?? null,
                    'industry_name'    => $item->user->name_of_enterprise ?? null,
                    'proposed_date'    => $item->proposed_date,
                    'reason'           => $item->reason_for_request,
                    'remarks'          => $item->remarks,
                    'status'           => $item->status,
                    'updated_at'       => $item->updated_at,
                ];
            });

            return response()->json([
                'status' => 1,
                'message' => 'Approved inspection list fetched successfully.',
                'total' => $data->count(),
                'data' => $data
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function inspection_date_update_by_inspector(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }


            $is_inspector = DepartmentUser::where('user_id', $user->id)
                ->where('inspector', 'yes')
                ->exists();

            if (!$is_inspector) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Access denied. Only inspectors can perform this action.',
                ], 403);
            }


            $request->validate([
                'id'               => 'required|integer|exists:inspections,id',
                'inspection_date'  => 'required|date',
                'remarks'          => 'nullable|string'
            ]);

            DB::beginTransaction();

            $inspection = Inspection::find($request->id);

            if (!$inspection) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Inspection not found.',
                ], 404);
            }


            $proposed_date = date('Y-m-d', strtotime($inspection->proposed_date));
            $inspection_date = date('Y-m-d', strtotime($request->inspection_date));

            if ($inspection_date === $proposed_date) {
                $status = 'Date Confirmed';
                $current_request_id = $inspection->request_id;

                if (str_starts_with($current_request_id, 'REQ-')) {
                    $new_request_id = preg_replace('/^REQ-/', 'INS-', $current_request_id);
                } else {
                    $new_request_id = 'INS-' . $current_request_id;
                }

                $inspection->update([
                    'inspection_date' => $inspection_date,
                    'status'          => $status,
                    'request_id'      => $new_request_id,
                    'updated_by'      => $user->email_id,
                ]);
            } else {
                $status = 'Date Changed';
                $inspection->update([
                    'inspection_date' => $inspection_date,
                    'status'          => $status,
                    'updated_by'      => $user->email_id,
                    'remarks'         => $request->remarks,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Inspection date and status updated successfully.',
                'data'    => [
                    'id'               => $inspection->id,
                    'request_id'       => $inspection->request_id,
                    'proposed_date'    => $proposed_date,
                    'inspection_date'  => $inspection_date,
                    'updated_status'   => $status,
                    'remarks'          => $inspection->remarks,
                ]
            ], 200);
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

    public function date_confirmed_inspections_list_per_user()
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $inspections = Inspection::where('user_id', $user->id)
                ->where('status', 'Date Confirmed')
                ->orderBy('inspection_date', 'desc')
                ->get()
                ->map(function ($inspection) {


                    return [
                        'id'                        => $inspection->id,
                        'inspection_id'             => $inspection->request_id,
                        'inspection_date'           => $inspection->inspection_date,
                        'inspection_type'           => $inspection->inspection_type,
                        'deprtment'                 => $inspection->department->name,
                        'inspector'                 => $inspection->inspectorUser ? $inspection->inspectorUser->authorized_person_name : 'N/A',
                        'status'                    => $inspection->status,
                        'created_by'                => $inspection->created_by,
                        'updated_by'                => $inspection->updated_by,
                    ];
                });

            if ($inspections->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No inspections found.',
                    'data'    => []
                ], 200);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Inspections fetched successfully.',
                'data'    => $inspections
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function inspectors_by_department(Request $request)
    {

        try {

            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
            ]);


            $inspector_ids = DepartmentUser::where('department_id', $request->department_id)
                ->where('inspector', 'yes')
                ->pluck('user_id');

            if ($inspector_ids->isEmpty()) {
                return response()->json([
                    'status' => 1,
                    'message' => 'No inspectors found for this department.',
                    'data' => []
                ], 200);
            }

            $inspectors = User::whereIn('id', $inspector_ids)
                ->select('id', 'authorized_person_name')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status'  => 1,
                'message' => 'Inspectors fetched successfully.',
                'data'    => $inspectors,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function inspection_date_update_by_user(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id'                  => 'required|integer|exists:inspections,id',
                'proposed_date'       => 'required|date',
                'remarks'             => 'nullable|string'

            ]);

            DB::beginTransaction();

            $inspection = Inspection::find($request->id);

            if (!$inspection) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Inspection not found.',
                ], 404);
            }


            $inspection_date = date('Y-m-d', strtotime($inspection->inspection_date));
            $proposed_date = date('Y-m-d', strtotime($request->proposed_date));

            if ($inspection_date === $proposed_date) {
                $status = 'Date Confirmed';
                $current_request_id = $inspection->request_id;

                if (str_starts_with($current_request_id, 'REQ-')) {
                    $new_request_id = preg_replace('/^REQ-/', 'INS-', $current_request_id);
                } else {
                    $new_request_id = 'INS-' . $current_request_id;
                }

                $inspection->update([
                    'proposed_date'   => $proposed_date,
                    'status'          => $status,
                    'request_id'      => $new_request_id,
                    'updated_by'      => $user->email_id,
                ]);
            } else {
                $status = 're_submitted';
                $inspection->update([
                    'proposed_date'   => $proposed_date,
                    'status'          => $status,
                    'updated_by'      => $user->email_id,
                    'remarks'      => $request->remarks,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Inspection date and status updated successfully.',
                'data'    => [
                    'id'               => $inspection->id,
                    'request_id'       => $inspection->request_id,
                    'proposed_date'    => $proposed_date,
                    'inspection_date'  => $inspection_date,
                    'updated_status'   => $status,
                    'remarks'          => $inspection->remarks,
                ]
            ], 200);
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
}
