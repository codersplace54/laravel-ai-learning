<?php

namespace App\Http\Controllers\service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Appeal;
use App\Models\UserServiceApplication;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\User;

class AppealController extends Controller
{
    public function user_appeal_store(Request $request)
    {
        try {

            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'application_id' => 'required|exists:user_service_applications,id',
                'appeal_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
                'remarks_from_user' => 'required|string',
            ]);

            DB::beginTransaction();

            $application = UserServiceApplication::where('id', $request->application_id)->first();

            $user_id = $application->user_id;

            $last_assignment = ApplicationWorkflowAssignment::where('application_id', $application->id)
                ->orderByDesc('step_number')
                ->first();
            $department_id = $last_assignment?->department_id;

            $filePath = null;
            if ($request->hasFile('appeal_file')) {
                $filePath = $request->file('appeal_file')
                    ->store('appeals/' . $user_id, 'public');
            }

            $appeal = Appeal::where('application_id', $request->application_id)
                ->where('user_id', $user_id)
                ->first();

            if ($appeal) {
                $appeal->update([
                    'appeal_file' => $filePath ?? $appeal->appeal_file,
                    'remarks_from_user' => $request->remarks_from_user,
                    'status' => 'pending',
                ]);

                $appeal->appeal_file = $appeal->appeal_file
                    ? asset('storage/' . $appeal->appeal_file)
                    : null;

                DB::commit();

                return response()->json([
                    'status' => 1,
                    'message' => 'Appeal updated successfully!',
                    'data' => $appeal
                ], 200);
            } else {

                $appeal = Appeal::create([
                    'application_id' => $request->application_id,
                    'user_id' => $user_id,
                    'department_id' => $department_id,
                    'appeal_file' => $filePath,
                    'remarks_from_user' => $request->remarks_from_user,
                    'status' => 'pending',
                ]);

                $appeal->appeal_file = $appeal->appeal_file
                    ? asset('storage/' . $appeal->appeal_file)
                    : null;

                DB::commit();

                return response()->json([
                    'status' => 1,
                    'message' => 'Appeal submitted successfully!',
                    'data' => $appeal
                ], 201);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to submit appeal.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function get_department_appeals(Request $request, $user_id)
    {


        try {

            $per_page = $request->per_page ?? 10;

            $user = User::where('id', $user_id)
                ->where('user_type', 'department')
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or non-departmental user.'
                ], 404);
            }

            $dept_user = $user->department_user_location;
            $hierarchy_level = $user->department_user->hierarchy_level;
            $department_id = $user->department_user->department_id;

            $query = Appeal::with([
                'application.service:id,service_title_or_description',
                'application.user:id,authorized_person_name,email_id,mobile_no,district_id,subdivision_id,ulb_id'
            ])
                ->where('department_id', $department_id)
                ->where('status', 'pending');

            $query->whereHas('application.user', function ($q) use ($hierarchy_level, $dept_user) {
                $q->where(function ($loc) use ($hierarchy_level, $dept_user) {

                    foreach ($dept_user as $d) {
                        if ($hierarchy_level === 'block') {
                            $loc->orWhere('ulb_id', $d->block_id);
                        } elseif (str_starts_with($hierarchy_level, 'subdivision')) {
                            $loc->orWhere('subdivision_id', $d->subdivision_id);
                        } elseif (str_starts_with($hierarchy_level, 'district')) {
                            $loc->orWhere('district_id', $d->district_id);
                        }
                    }
                });
            });

            if ($request->search) {
                $search = $request->search;
                $query->whereHas('application', function ($q) use ($search) {
                    $q->where('applicationId', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($u) use ($search) {
                            $u->where('authorized_person_name', 'like', "%{$search}%")
                                ->orWhere('mobile_no', 'like', "%{$search}%");
                        });
                });
            }
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereHas('application', function ($q) use ($request) {
                    $q->whereDate('application_date', '>=', $request->date_from)
                        ->whereDate('application_date', '<=', $request->date_to);
                });
            }


            $appeals = $query->orderByDesc('id')->paginate($per_page);
            $appeals->getCollection()->transform(function ($appeal) use ($hierarchy_level) {
                $appeal->appeal_file = $appeal->appeal_file
                    ? asset('storage/' . $appeal->appeal_file)
                    : null;
                return [
                    'appeal_id' => $appeal->id,
                    'application_id' => $appeal->application->id ?? null,
                    'application_number' => $appeal->application->applicationId ?? null,
                    'service_name' => $appeal->application->service->service_title_or_description ?? null,
                    'applicant_name' => $appeal->application->user->authorized_person_name ?? null,
                    'applicant_mobile' => $appeal->application->user->mobile_no ?? null,
                    'appeal_file' => $appeal->appeal_file,
                    'remarks_from_user' => $appeal->remarks_from_user,
                    'status' => $appeal->status,
                    'hierarchy_level' => $hierarchy_level,
                    'created_at' => $appeal->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $appeals->items(),
                'pagination' => [
                    'current_page' => $appeals->currentPage(),
                    'last_page' => $appeals->lastPage(),
                    'per_page' => $appeals->perPage(),
                    'total' => $appeals->total(),
                ]
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while fetching appeals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update_appeal_status(Request $request)
    {
        try {

            DB::beginTransaction();

            $user = Auth::user();
            if (!$user || $user->user_type !== 'department') {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthorized user'
                ], 403);
            }

            $request->validate([
                'appeal_id' => 'required|exists:appeals,id',
                'status' => 'required|in:approved,rejected',
                'remarks_by_dept' => 'nullable|string',
                'dept_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
            ]);

            $appeal = Appeal::where('id', $request->appeal_id)
                ->first();

            $filePath = null;
            if ($request->hasFile('dept_file')) {
                $filePath = $request->file('dept_file')
                    ->store('appeals/dept_file/' . $appeal->user_id, 'public');
            }

            if (!$appeal) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Appeal not found or access denied'
                ], 404);
            }

            $appeal->update([
                'status' => $request->status,
                'remarks_by_dept' => $request->remarks_by_dept,
                'dept_file' => $filePath ?? $appeal->dept_file,
            ]);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Appeal ' . ucfirst($request->status) . ' successfully',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to update appeal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
