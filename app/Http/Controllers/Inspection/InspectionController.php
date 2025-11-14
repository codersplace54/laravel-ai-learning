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
use App\Models\UnitDetail;

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
                'department_id'       => 'nullable|integer|exists:departments,id',
                'proposed_date'       => 'nullable|array',
                'proposed_date.*'     => 'date',
                'reason_for_request'  => 'nullable|string',
                'remarks'             => 'nullable|string',


                'inspection_date'     => 'nullable|date',
                'unit_name'           => 'nullable|string',
                'inspection_type'     => 'nullable|string',
                'inspector'           => 'nullable|string',
                'inspection_for'      => 'nullable|array',
                'inspection_for.*'    => 'string',
                'department_type'     => 'nullable|string',

            ]);

            DB::beginTransaction();

            $inspection = Inspection::create([
                'user_id'            => $user->id,
                'department_id'      => $request->department_id ?? $user->department_user->department_id,
                'proposed_date'      => json_encode($request->proposed_date),
                'inspection_date'    => $request->inspection_date,
                'unit_name'          => $request->unit_name,
                'reason_for_request' => $request->reason_for_request,
                'remarks'            => $request->remarks,
                'inspection_type'    => $request->inspection_type ?? 'On Request',
                'inspector'          => $request->inspector,
                'department_type'    => $request->department_type,
                'inspection_for'     => json_encode($request->inspection_for),
                'status'             => 'pending',
                'created_by'         => $user->email_id,
            ]);

            $department_code = str_pad($inspection->department_id, 2, '0', STR_PAD_LEFT);
            $formatted_id = str_pad($inspection->id, 6, '0', STR_PAD_LEFT);
            $request_id = "REQ-{$department_code}-{$formatted_id}";
            $inspection->update(['request_id' => $request_id]);

            $inspection->proposed_date = json_decode($inspection->proposed_date, true);
            $inspection->inspection_for = json_decode($inspection->inspection_for, true);

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
                        'id'                        => $inspection->id,
                        'request_id'                => $inspection->request_id,
                        'department_id'             => $inspection->department_id,
                        'department_name'           => $inspection->department->name,
                        'proposed_date'             => json_decode($inspection->proposed_date),
                        'inspection_date'           => $inspection->inspection_date,
                        'inspection_type'           => 'On Request',
                        'industry_name'             => $inspection->unit->unit_name ?? null,
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
                'id'                          => $inspection->id,
                'request_id'                  => $inspection->request_id,
                'proposed_date'               => json_decode($inspection->proposed_date),
                'inspection_date'             => $inspection->inspection_date ?? 'N/A',
                'department_name'             => $inspection->department->name ?? 'N/A',
                'inspector'                   => $inspection->inspectorUser ? $inspection->inspectorUser->authorized_person_name : 'N/A',
                'reason_for_request'          => $inspection->reason_for_request ?? '',
                'inspection_type'             => $inspection->inspection_type ?? '',
                'inspection_for'              => json_decode($inspection->inspection_for) ?? '',
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
                'id'                  => 'required|integer|exists:inspections,id',
                'department_id'       => 'nullable|integer|exists:departments,id',
                'proposed_date'       => 'nullable|array',
                'proposed_date.*'     => 'date',
                'reason_for_request'  => 'nullable|string|max:255',
                'remarks'             => 'nullable|string|max:255',

                'inspection_date'     => 'nullable|date',
                'unit_name'           => 'nullable|string',
                'inspection_type'     => 'nullable|string',
                'inspector'           => 'nullable|string',
                'inspection_for'      => 'nullable|array',
                'inspection_for.*'    => 'string',
                'department_type'     => 'nullable|string',
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
                'proposed_date'      => json_encode($request->proposed_date ?? $inspection->proposed_date),
                'reason_for_request' => $request->reason_for_request ?? $inspection->reason_for_request,
                'remarks'            => $request->remarks ?? $inspection->remarks,
                // 'status'             => $request->status ?? $inspection->status,
                'updated_by'         => $user->email_id,
                'inspection_type'    => $request->inspection_type ?? 'On Request',
                'inspector'          => $request->inspector ??  $inspection->inspector,
                'inspection_date'    => $request->inspection_date  ?? $inspection->inspection_date,
                'unit_name'          => $request->unit_name  ?? $inspection->unit_name,
                'inspection_for'     => json_encode($request->inspection_for ?? $inspection->inspection_for),
                'department_type'    => $request->department_type ??  $inspection->department_type,
            ]);

            $department_code = str_pad($inspection->department_id, 2, '0', STR_PAD_LEFT);
            $formatted_id = str_pad($inspection->id, 6, '0', STR_PAD_LEFT);
            $request_id = "REQ-{$department_code}-{$formatted_id}";
            $inspection->update(['request_id' => $request_id]);

            $inspection->proposed_date = json_decode($inspection->proposed_date, true);
            $inspection->inspection_for = json_decode($inspection->inspection_for, true);

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

            $industry_name = $request->industry_name;
            $from_date     = $request->from_date;
            $to_date       = $request->to_date;
            $perPage       = $request->per_page ?? 10;

            $query = Inspection::where('department_id', $request->department_id)
                ->whereIn('status', ['pending', 'approved','re_submitted']);

            if ($industry_name) {
                $query->whereHas('unit', function ($q) use ($industry_name) {
                    $q->where('unit_name', 'like', "%{$industry_name}%");
                });
            }

            if ($from_date && $to_date) {
                $query->whereBetween('inspection_date', [$from_date, $to_date]);
            }

            $inspections = $query->orderByDesc('updated_at')
                ->paginate($perPage);


            $data = $inspections->getCollection()->map(function ($item) {

                return [
                    'id'               => $item->id,
                    'request_id'       => $item->request_id,
                    'proposed_date'    => $item->proposed_date ?? null,
                    'inspection_type'  => $item->inspection_type ?? 'On Request',
                    'industry_name'    => $item->unit->unit_name ?? null,
                    'inspector'        => $item->inspectorUser ? $item->inspectorUser->authorized_person_name : 'Not Assigned',
                    'status'           => $item->status,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Inspections fetched successfully.',
                'total'   => $inspections->total(),
                'current_page' => $inspections->currentPage(),
                'last_page'    => $inspections->lastPage(),
                'per_page'     => $inspections->perPage(),
                'data'         => $data,
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

    public function approved_inspections_list(Request $request)
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

            $industry_name = $request->industry_name;
            $from_date     = $request->from_date;
            $to_date       = $request->to_date;
            $perPage       = $request->per_page ?? 10;

            $query = Inspection::with([
                'user:id,name_of_enterprise,authorized_person_name',
                'department:id,name'
            ])
                ->where('inspector', $user->id)
                ->where(function ($q) {
                    $q->where('department_type', 'joint')
                        ->orWhereIn('status', ['approved', 'completed','Date Confirmed']);
                });

            if ($industry_name) {
                $query->whereHas('unit', function ($q) use ($industry_name) {
                    $q->where('unit_name', 'like', "%{$industry_name}%");
                });
            }

            if ($from_date && $to_date) {
                $query->whereBetween('inspection_date', [$from_date, $to_date]);
            }

            $inspections = $query->orderByDesc('updated_at')
                ->paginate($perPage);


            $data = $inspections->getCollection()->map(function ($item) {
                return [
                    'id'               => $item->id,
                    'user_name'        => $item->user->authorized_person_name,
                    'inspection_id'    => $item->request_id,
                    'inspection_type'  => $item->inspection_type,
                    'inspector'        => $item->inspectorUser ? $item->inspectorUser->authorized_person_name : 'N/A',
                    'department_name'  => $item->department->name ?? null,
                    'industry_name'    => $item->unit->unit_name ?? null,
                    'proposed_date'  => json_decode($item->proposed_date),
                    'inspection_date'  => json_decode($item->inspection_date),
                    'actual_date_of_inspection' => $item->inspection_date ?? null,
                    'department_type'  => $item->department_type,
                    'reason'           => $item->reason_for_request,
                    'remarks'          => $item->remarks,
                    'status'           => $item->status,
                    'updated_at'       => $item->updated_at,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Approved inspection list fetched successfully.',
                'total'   => $inspections->total(),
                'current_page' => $inspections->currentPage(),
                'last_page'    => $inspections->lastPage(),
                'per_page'     => $inspections->perPage(),
                'data'         => $data,
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
                        'department_id'             => $inspection->department_id,
                        'department_name'           => $inspection->department->name,
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

    public function unit_list()
    {

        try {


            $units = UnitDetail::select('id', 'unit_name')
                ->orderBy('unit_name', 'asc')
                ->get();

            if ($units->isEmpty()) {
                return response()->json([
                    'status' => 1,
                    'message' => 'No units found.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Unit list fetched successfully.',
                'data' => $units
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function get_unit_details(Request $request)
    {

        try {


            $request->validate([
                'id' => 'required|integer|exists:unit_details,id',
            ]);

            $unit = UnitDetail::where('id', $request->id)
                ->select('id', 'user_id', 'unit_name', 'unit_address', 'unit_location_district', 'unit_location_subdivision', 'category_of_enterprise')
                ->first();

            if (!$unit) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unit not found.',
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Unit details fetched successfully.',
                'data' => [
                    'id'                                => $unit->id,
                    'user_id'                           => $unit->user_id,
                    'unit_name'                         => $unit->unit_name,
                    'unit_address'                      => $unit->unit_address,
                    'unit_location_district'            => $unit->district->district_name ?? null,
                    'unit_location_subdivision'         => $unit->subdivision->sub_division ?? null,
                    'category_of_enterprise'            => $unit->category_of_enterprise,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'error' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update_joint_inspection(Request $request)
    {

        try {


            $request->validate([
                'inspection_id'   => 'required|integer',
                'proposed_date'   => 'required|array|min:1',
                'proposed_date.*' => 'date|date_format:Y-m-d'
            ]);

            $inspection = Inspection::where('id', $request->inspection_id)->first();

            if (!$inspection) {
                return response()->json(['status' => 0, 'message' => 'Inspection not found.'], 404);
            }

            $inspection->update([
                'proposed_date' => json_encode($request->proposed_date)
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Proposed dates updated successfully.',
                'proposed_date' => json_decode($inspection->proposed_date, true)
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ]);
        }
    }
}
