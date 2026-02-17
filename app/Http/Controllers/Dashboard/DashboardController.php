<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceMaster;
use App\Models\UserServiceApplication;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\ApplicationWorkflowHistory;
use App\Models\ServiceApprovalFlow;
use App\Models\Department;
use App\Models\User;
use App\Models\DepartmentUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Models\UserFeedback;
use App\Models\Holiday;
use App\Models\Inspection;


class DashboardController extends Controller
{

    public function get_total_applications_by_department(Request $request)
    {

        try {

            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $user = User::where('id', $user->id)
                ->where('user_type', 'department')
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or non-departmental user.'
                ], 404);
            }

            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
            ]);

            $hierarchy_level = $user->department_user->hierarchy_level;
            $user_id         = $user->id;
            $department_id   = $request->department_id;

            $cacheKey = "dept_dashboard_{$department_id}_{$user_id}";

            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use (
                $department_id,
                $user_id,
                $hierarchy_level,
                $user
            ) {

                $total_application = UserServiceApplication::whereNotIn('status', ['expired', 'draft'])->count();

                $total_applications_for_this_department = ApplicationWorkflowAssignment::where('department_id', $department_id)
                    ->whereHas('application', function ($query) {
                        $query->whereNotIn('status', ['expired', 'draft']);
                    })
                    ->distinct('application_id')
                    ->count('application_id');


                $percentage_total_application = $total_applications_for_this_department > 0
                    ? min(100, round(($total_applications_for_this_department / $total_application) * 100, 2))
                    : 0;

                $department_app_ids = ApplicationWorkflowAssignment::where('department_id', $department_id)
                    ->pluck('application_id')
                    ->unique();

                $total_count_pending_application_in_department = UserServiceApplication::join('application_workflow_assignments as awa', 'awa.application_id', '=', 'user_service_applications.id')
                    ->where('awa.department_id', $department_id)
                    ->whereIn('user_service_applications.status', [
                        'pending',
                        'under_review',
                        're_submitted',
                        'saved',
                        'submitted',
                        'extra_payment',
                        'send_back'
                    ])
                    ->distinct()
                    ->count('user_service_applications.id');

                $total_count_approved_application_in_department = UserServiceApplication::whereIn('id', $department_app_ids)
                    ->whereIn('status', ['approved', 'noc_issued'])
                    ->count();

                $percentage_pending_application = $total_applications_for_this_department > 0
                    ? min(100, round(($total_count_pending_application_in_department / $total_applications_for_this_department) * 100, 2))
                    : 0;


                $percentage_approved_application = $total_applications_for_this_department > 0
                    ? min(100, round(($total_count_approved_application_in_department / $total_applications_for_this_department) * 100, 2))
                    : 0;

                $total_count_rejected_application_in_department = UserServiceApplication::whereIn('id', $department_app_ids)
                    ->where('status', 'rejected')
                    ->count();

                $percentage_rejected_application = $total_applications_for_this_department > 0
                    ? min(100, round(($total_count_rejected_application_in_department / $total_applications_for_this_department) * 100, 2))
                    : 0;


                $number_of_NOC_issued_by_department = UserServiceApplication::where('status', 'noc_issued')
                    ->whereHas('latestWorkflow', function ($q) use ($department_id) {
                        $q->where('department_id', $department_id);
                    })
                    ->count();

                $services = ServiceMaster::withCount('applications')
                    ->where('department_id', $department_id)
                    ->orderByRaw('applications_count = 0')
                    ->orderBy('id', 'desc')
                    ->get(['id', 'service_title_or_description']);

                $application_count_per_service = $services->map(function ($service) {
                    return [
                        'service_id' => $service->id,
                        'service_name' => $service->service_title_or_description,
                        'application_count' => $service->applications_count,
                    ];
                });

                $district_wise_application_in_department =  UserServiceApplication::join('service_masters', 'service_masters.id', '=', 'user_service_applications.service_id')
                    ->join('users', 'users.id', '=', 'user_service_applications.user_id')
                    ->join('tripura_master_data as tmd', 'tmd.id', '=', 'users.district_id')
                    ->where('service_masters.department_id', $department_id)
                    ->selectRaw('tmd.district_name, COUNT(*) as count')
                    ->groupBy('tmd.district_name')
                    ->get();

                $district_wise_application_per_service =
                    UserServiceApplication::join('service_masters', 'service_masters.id', '=', 'user_service_applications.service_id')
                    ->join('users', 'users.id', '=', 'user_service_applications.user_id')
                    ->join('tripura_master_data as tmd', 'tmd.id', '=', 'users.district_id')
                    ->where('service_masters.department_id', $department_id)
                    ->selectRaw('
            service_masters.service_title_or_description as service_name,
            tmd.district_name,
            COUNT(*) as count
        ')
                    ->groupBy(
                        'service_masters.service_title_or_description',
                        'tmd.district_name'
                    )
                    ->get()
                    ->groupBy('service_name')
                    ->map(fn($rows, $service) => [
                        'service_name' => $service,
                        'districts' => $rows->map(fn($r) => [
                            'district_name' => $r->district_name,
                            'count' => $r->count
                        ])->values()
                    ])
                    ->values();


                $clarification_required = UserServiceApplication::with([
                    'latestWorkflow',
                    'workflowHistory' => function ($q) use ($department_id) {
                        $q->where('status', 'send_back')
                            ->where('department_id', $department_id)
                            ->orderByDesc('id');
                    },
                    'service:id,service_title_or_description',
                    'user:id,authorized_person_name',
                ])
                    ->get()
                    ->filter(function ($app) {
                        $latest = $app->latestWorkflow ?? $app->workflowHistory->first();
                        return $latest && $latest->status === 'send_back';
                    })
                    ->map(function ($app) {
                        $latest_send_back = $app->latestWorkflow && $app->latestWorkflow->status_file
                            ? $app->latestWorkflow
                            : $app->workflowHistory->first();

                        return [
                            'application_id' => $app->id,  // application_id saved in history table
                            'applicationId' => $app->applicationId, // applicationId is the application number saved in user service applications table
                            'status_file' => $latest_send_back?->status_file ? url($latest_send_back->status_file) : null,
                            'service_name' => $app->service?->service_title_or_description,
                            'remark' => $latest_send_back?->remarks,
                            'application_date' => $app->application_date,
                            'applicant_name' => $app->user?->authorized_person_name,
                            'clarification_raised_date' => $latest_send_back?->action_taken_at,
                        ];
                    });


                $services_with_license_count = ServiceMaster::where('department_id', $department_id)
                    ->withCount([
                        'applications as licenses_issued_count' => function ($q) {
                            $q->whereNotNull('NOC_certificate')
                                ->where('status', 'approved');
                        }
                    ])->get(['id', 'service_title_or_description']);

                $license_issued_per_service = $services_with_license_count->map(function ($service) {
                    return [
                        'service_id'         => $service->id,
                        'service_name'       => $service->service_title_or_description,
                        'licenses_issued'    => $service->licenses_issued_count,
                    ];
                });

                $approved_applications = UserServiceApplication::with([
                    'service:id,service_title_or_description,department_id',
                    'latestWorkflow' => function ($q) use ($department_id) {
                        $q->where('department_id', $department_id);
                    }
                ])
                    ->whereIn('status', ['approved', 'noc_issued'])
                    ->whereHas('service', function ($q) use ($department_id) {
                        $q->where('department_id', $department_id);
                    })
                    ->get();

                $avg_approval_time_per_service = $approved_applications
                    ->groupBy(function ($application) {
                        return $application->service->id;
                    })
                    ->map(function ($applications, $service_id) {
                        $first_application = $applications->first();
                        $service          = $first_application->service;

                        $total_days = 0;
                        $count = $applications->count();

                        foreach ($applications as $application) {
                            $workflow = $application->latestWorkflow;

                            if (!$workflow || !$workflow->updated_at) {
                                continue;
                            }

                            $total_days += $application->application_date->diffInDays($workflow->updated_at);
                        }

                        $avg_days = $count > 0 ? $total_days / $count : 0;

                        return [
                            'service_id'        => $service_id,
                            'service_name'      => $service->service_title_or_description,
                            'avg_approval_days' => round($avg_days, 2),
                        ];
                    })
                    ->values();

                $department_locations = DepartmentUser::where('user_id', $user->id)->get();

                $latest_assignment_ids = ApplicationWorkflowAssignment::selectRaw('MAX(id) as id')
                    ->groupBy('application_id');

                $total_pending_for_me = ApplicationWorkflowAssignment::whereIn(
                    'id',
                    $latest_assignment_ids
                )
                    ->where('status', 'pending')
                    ->where('hierarchy_level', $hierarchy_level)
                    ->whereHas('application', function ($q) use ($department_id) {
                        $q->whereIn('payment_status', ['paid', 'success'])
                            ->where('department_id', $department_id);
                    })

                    ->whereHas('application.user', function ($q) use ($hierarchy_level, $department_locations) {
                        $q->where(function ($loc) use ($hierarchy_level, $department_locations) {
                            foreach ($department_locations as $location) {
                                if ($hierarchy_level === 'block') {
                                    $loc->orWhere('ulb_id', $location->block_id);
                                } elseif (str_starts_with($hierarchy_level, 'subdivision')) {
                                    $loc->orWhere('subdivision_id', $location->subdivision_id);
                                } elseif (str_starts_with($hierarchy_level, 'district')) {
                                    $loc->orWhere('district_id', $location->district_id);
                                }
                            }
                        });
                    })
                    ->count();

                $total_approved_by_me = ApplicationWorkflowAssignment::where('action_taken_by', $user_id)
                    ->where('status', 'approved')
                    ->distinct('application_id')
                    ->count('application_id');

                $total_individual_users = User::whereNull('old_id')
                    ->where('user_type', 'individual')
                    ->count();

                return [
                    'total_applications_for_this_department' => $total_applications_for_this_department,
                    'percentage_total_application' => $percentage_total_application,
                    'total_count_pending_application_in_department' => $total_count_pending_application_in_department,
                    'percentage_pending_application' => $percentage_pending_application,
                    'percentage_approved_application' => $percentage_approved_application,
                    'percentage_rejected_application' => $percentage_rejected_application,
                    'total_count_approved_application_in_department' => $total_count_approved_application_in_department,
                    'total_count_rejected_application_in_department' => $total_count_rejected_application_in_department,
                    'number_of_NOC_issued_by_department' => $number_of_NOC_issued_by_department,
                    'application_count_per_service' => $application_count_per_service,
                    'district_wise_application_in_department' => $district_wise_application_in_department,
                    'district_wise_application_per_service' => $district_wise_application_per_service,
                    'clarification_required' => $clarification_required,
                    'license_issued_per_service' => $license_issued_per_service,
                    'avg_approval_time_per_service' => $avg_approval_time_per_service,
                    'total_pending_for_me' => $total_pending_for_me,
                    'total_approved_by_me' => $total_approved_by_me,
                    'total_individual_users' => $total_individual_users
                ];
            });


            return response()->json([
                'status'            => 1,
                'message'           => 'Total count applications under this department fetched successfully',
                'total_applications_for_this_department' => $data['total_applications_for_this_department'],
                'percentage_total_application' => $data['percentage_total_application'],
                'total_count_pending_application_in_department' => $data['total_count_pending_application_in_department'],
                'percentage_pending_application' => $data['percentage_pending_application'],
                'percentage_approved_application' => $data['percentage_approved_application'],
                'percentage_rejected_application' => $data['percentage_rejected_application'],
                'total_count_approved_application_in_department' => $data['total_count_approved_application_in_department'],
                'total_count_rejected_application_in_department' => $data['total_count_rejected_application_in_department'],
                'number_of_NOC_issued_by_department' => $data['number_of_NOC_issued_by_department'],
                'application_count_per_service' => $data['application_count_per_service'],
                'district_wise_application_in_department' => $data['district_wise_application_in_department'],
                'district_wise_application_per_service' => $data['district_wise_application_per_service'],
                'clarification_required' => $data['clarification_required'],
                'license_issued_per_service' => $data['license_issued_per_service'],
                'avg_approval_time_per_service' => $data['avg_approval_time_per_service'],
                'total_pending_for_me' => $data['total_pending_for_me'],
                'total_approved_by_me' => $data['total_approved_by_me'],
                'total_individual_users' => $data['total_individual_users']
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the application count',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function get_total_applications_by_user(Request $request)
    {

        try {

            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $user = User::where('id', $user->id)
                ->where('user_type', 'individual')
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not a user.'
                ], 404);
            }

            $request->validate([
                'user_id' => 'required|integer|exists:users,id',
            ]);

            $user_id         = $user->id;

            $cacheKey = "user_dashboard_{$user_id}";

            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user_id) {


                $total_services_per_user = ServiceMaster::count();

                $total_application = UserServiceApplication::whereNotIn('status', ['expired', 'draft'])->count();

                $total_applications_for_this_user = User::find($user_id)->applications()->whereNotIn('status', ['expired', 'draft'])->count();

                $percentage_total_application = $total_applications_for_this_user > 0
                    ? min(100, round(($total_applications_for_this_user / $total_application) * 100, 2))
                    : 0;

                $total_count_pending_application_in_user = UserServiceApplication::where('user_id', $user_id)
                    ->whereIn('status', ['submitted', 're_submitted', 'pending', 'under_review', 'saved', 'extra_payment', 'send_back'])
                    ->count();

                $total_count_approved_application_in_user = UserServiceApplication::where('user_id', $user_id)
                    ->whereIn('status', ['approved', 'noc_issued'])
                    ->count();

                $percentage_pending_application = $total_applications_for_this_user > 0
                    ? min(100, round(($total_count_pending_application_in_user / $total_applications_for_this_user) * 100, 2))
                    : 0;


                $percentage_approved_application = $total_applications_for_this_user > 0
                    ? min(100, round(($total_count_approved_application_in_user / $total_applications_for_this_user) * 100, 2))
                    : 0;

                $total_count_rejected_application_in_user = UserServiceApplication::where('user_id', $user_id)
                    ->where('status', 'rejected')
                    ->count();

                $percentage_rejected_application = $total_applications_for_this_user > 0
                    ? min(100, round(($total_count_rejected_application_in_user / $total_applications_for_this_user) * 100, 2))
                    : 0;

                $application_count_per_service =
                    ServiceMaster::withCount([
                        'applications as application_count' => fn($q) =>
                        $q->where('user_id', $user_id)
                    ])
                    ->orderByRaw('application_count = 0')
                    ->orderByDesc('id')
                    ->get(['id', 'service_title_or_description'])
                    ->map(fn($s) => [
                        'service_id' => $s->id,
                        'service_name' => $s->service_title_or_description,
                        'application_count' => $s->application_count,
                    ]);

                $services_with_noc_count = ServiceMaster::withCount([
                    'applications as noc_issued_count' => function ($q) use ($user_id) {
                        $q->where('user_id', $user_id)
                            ->whereNotNull('NOC_certificate')
                            ->where('status', 'noc_issued');
                    }
                ])->having('noc_issued_count', '>', 0)
                    ->get(['id', 'service_title_or_description']);

                $noc_issued_per_service =
                    ServiceMaster::withCount([
                        'applications as noc_issued' => fn($q) =>
                        $q->where('user_id', $user_id)
                            ->where('status', 'noc_issued')
                            ->whereNotNull('NOC_certificate')
                    ])
                    ->having('noc_issued', '>', 0)
                    ->get(['id', 'service_title_or_description'])
                    ->map(fn($s) => [
                        'service_id' => $s->id,
                        'service_name' => $s->service_title_or_description,
                        'noc_issued' => $s->noc_issued,
                    ]);


                $clarification_required =
                    UserServiceApplication::where('user_id', $user_id)
                    ->whereHas(
                        'latestWorkflow',
                        fn($q) =>
                        $q->where('status', 'send_back')
                    )
                    ->with([
                        'latestWorkflow.department:id,name',
                        'service:id,service_title_or_description'
                    ])
                    ->select(
                        'id',
                        'applicationId',
                        'service_id',
                        'NOC_letter_number',
                        'comments'
                    )
                    ->get()
                    ->map(fn($app) => [
                        'application_id' => $app->id,  //  application id of  a user applied application
                        'applicationId' => $app->applicationId,  //application  number of the application
                        'service_id' => $app->service_id,
                        'service_name' => $app->service?->service_title_or_description,
                        'department_name' => $app->latestWorkflow?->department?->name,
                        'NOC_letter_number' => $app->NOC_letter_number,
                        'status_file' => $app->latestWorkflow?->status_file
                            ? url($app->latestWorkflow->status_file)
                            : null,
                        'comments' => $app->comments,
                        'remarks' => $app->latestWorkflow?->remarks,
                    ]);

                return compact(
                    'total_applications_for_this_user',
                    'percentage_total_application',
                    'total_count_pending_application_in_user',
                    'percentage_pending_application',
                    'percentage_approved_application',
                    'percentage_rejected_application',
                    'total_count_approved_application_in_user',
                    'total_count_rejected_application_in_user',
                    'application_count_per_service',
                    'clarification_required',
                    'noc_issued_per_service'
                );
            });


            return response()->json([
                'status'            => 1,
                'message'           => 'Total count applications under this department fetched successfully',
                'total_applications_for_this_user' => $data['total_applications_for_this_user'],
                'percentage_total_application' => $data['percentage_total_application'],
                'total_count_pending_application_in_user' => $data['total_count_pending_application_in_user'],
                'percentage_pending_application' => $data['percentage_pending_application'],
                'percentage_approved_application' => $data['percentage_approved_application'],
                'percentage_rejected_application' => $data['percentage_rejected_application'],
                'total_count_approved_application_in_user' => $data['total_count_approved_application_in_user'],
                'total_count_rejected_application_in_user' => $data['total_count_rejected_application_in_user'],
                'application_count_per_service' => $data['application_count_per_service'],
                'clarification_required' => $data['clarification_required'],
                'noc_issued_per_service' => $data['noc_issued_per_service'],

            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the application count',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_total_applications_by_admin(Request $request)
    {

        try {

            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $total_services = ServiceMaster::count();

            $total_applications = UserServiceApplication::whereNotIn('status', ['expired', 'draft'])->count();

            $percentage_total_application = 100;

            $total_count_pending_application = UserServiceApplication::whereIn('status', ['submitted', 're_submitted', 'pending', 'under_review', 'saved', 'extra_payment', 'send_back'])
                ->count();

            $total_count_approved_application = UserServiceApplication::whereIn('status', ['approved', 'noc_issued'])
                ->count();

            $percentage_pending_application = $total_applications > 0
                ? min(100, round(($total_count_pending_application / $total_applications) * 100, 2))
                : 0;


            $percentage_approved_application = $total_applications > 0
                ? min(100, round(($total_count_approved_application / $total_applications) * 100, 2))
                : 0;

            $total_count_rejected_application = UserServiceApplication::where('status', 'rejected')->count();

            $percentage_rejected_application = $total_applications > 0
                ? min(100, round(($total_count_rejected_application / $total_applications) * 100, 2))
                : 0;

            $services = ServiceMaster::withCount('applications')
                ->orderByRaw('applications_count = 0')
                ->orderBy('id', 'desc')
                ->get(['id', 'service_title_or_description']);

            $application_count_per_service = $services->map(function ($service) {
                return [
                    'service_id' => $service->id,
                    'service_name' => $service->service_title_or_description,
                    'application_count' => $service->applications_count,
                ];
            })->sortByDesc('application_count')->values();

            $total_individual_users = User::whereNull('old_id')
                ->where('user_type', 'individual')
                ->count();

            $total_new_applications = UserServiceApplication::whereHas('user', function ($q) {
                $q->whereNull('old_id');
            })
                ->count();


            return response()->json([
                'status'            => 1,
                'message'           => 'Total count applications under this department fetched successfully',
                'total_applications' => $total_applications,
                'percentage_total_application' => $percentage_total_application,
                'total_count_pending_application' => $total_count_pending_application,
                'percentage_pending_application' => $percentage_pending_application,
                'percentage_approved_application' => $percentage_approved_application,
                'percentage_rejected_application' => $percentage_rejected_application,
                'total_count_approved_application' => $total_count_approved_application,
                'total_count_rejected_application' => $total_count_rejected_application,
                'application_count_per_service' => $application_count_per_service,
                'total_individual_users'        => $total_individual_users,
                'total_new_applications'        => $total_new_applications,

            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the application count',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_department_wise_static_count(Request $request)
    {
        try {
            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
            ]);

            $departmentId = (int) $request->department_id;

            $cacheKey = "department_application_status_counts_v1:dept:{$departmentId}";

            $data = Cache::remember($cacheKey, now()->addHour(), function () use ($departmentId) {

                $base = UserServiceApplication::query()
                    ->whereHas('service', function ($q) use ($departmentId) {
                        $q->where('department_id', $departmentId);
                    });

                return [
                    'submitted' => (clone $base)->where('status', 'submitted')->count(),
                    'noc_issued' => (clone $base)->whereIn('status', ['noc_issued', 'approved'])->count(),
                    'rejected'  => (clone $base)->where('status', 'rejected')->count(),
                    'under_process' => (clone $base)->whereIn('status', [
                        'pending',
                        'under_review',
                        're_submitted',
                        'send_back',
                        'extra_payment',
                    ])->count(),
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Department wise application status count fetched successfully',
                'data'    => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong while fetching department statistics',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function get_overall_static_count(Request $request)
    {
        try {
            $cacheKey = 'overall_application_status_counts_v1';

            $data = Cache::remember($cacheKey, now()->addHour(), function () {
                $base = UserServiceApplication::query();

                return [
                    'submitted' => (clone $base)->where('status', 'submitted')->count(),
                    'noc_issued' => (clone $base)->whereIn('status', ['noc_issued', 'approved'])->count(),
                    'rejected'  => (clone $base)->where('status', 'rejected')->count(),
                    'under_process' => (clone $base)->whereIn('status', [
                        'pending',
                        'under_review',
                        're_submitted',
                        'send_back',
                        'extra_payment'
                    ])->count(),
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Overall application status count fetched successfully',
                'data'    => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong while fetching overall statistics',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_analytical_dashboard_count_for_admin(Request $request)
    {

        try {

            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $from_date = $request->from_date;
            $to_date   = $request->to_date;

            $date_filter = function ($query) use ($from_date, $to_date) {
                $query->when($from_date, fn($q) => $q->whereDate('created_at', '>=', $from_date))
                    ->when($to_date, fn($q) => $q->whereDate('created_at', '<=', $to_date));
            };

            $total_applications = UserServiceApplication::whereNotIn('status', ['expired', 'draft'])->tap($date_filter)->count();

            $new_users_last_30_days = User::where('created_at', '>=', Carbon::now()->subDays(30))
                ->count();


            $total_queries = UserFeedback::count();

            $noc_issued = UserServiceApplication::where('status', 'noc_issued')->count();


            $total_count_pending_application = UserServiceApplication::whereIn('status', ['submitted', 're_submitted', 'pending', 'under_review', 'saved', 'extra_payment', 'send_back'])
                ->tap($date_filter)
                ->count();

            $total_payments = UserServiceApplication::where('payment_status', 'paid')
                ->where('paid_amount', '>', 0)
                ->tap($date_filter)
                ->count();

            $upcoming_holidays = Holiday::whereDate('holiday_date', '>=', now()->toDateString())
                ->orderBy('holiday_date', 'asc')
                ->limit(2)
                ->get(['holiday_date', 'description']);

            $noc_expired_last_90_days = UserServiceApplication::whereDate('NOC_expiry_date', '>=', now()->subDays(90))
                ->whereDate('NOC_expiry_date', '<=', now())
                ->count();

            $rejected_application = UserServiceApplication::where('status', 'rejected')->tap($date_filter)->count();

            $received_applications = UserServiceApplication::whereIn('status', ['submitted', 'under_process', 'approved'])->tap($date_filter)->count();

            $total_individual_users = User::whereNull('old_id')
                ->where('user_type', 'individual')
                ->tap($date_filter)
                ->count();

            $total_inspections = Inspection::tap($date_filter)->count();

            $department_wise_application = Department::leftJoin('application_workflow_assignments as awa', 'departments.id', '=', 'awa.department_id')
                ->leftJoin('user_service_applications as usa', 'awa.application_id', '=', 'usa.id')
                ->when($from_date, fn($q) => $q->whereDate('usa.created_at', '>=', $from_date))
                ->when($to_date, fn($q) => $q->whereDate('usa.created_at', '<=', $to_date))
                ->select(
                    'departments.id as department_id',
                    'departments.name as department_name',
                    DB::raw('COUNT(DISTINCT usa.id) as application_count'),
                    DB::raw('COUNT(DISTINCT CASE WHEN usa.status = "noc_issued" THEN usa.id END) as noc_issued_count')
                )
                ->groupBy('departments.id', 'departments.name')
                ->orderByRaw(
                    'COUNT(DISTINCT usa.id) +
         COUNT(DISTINCT CASE WHEN usa.status = "noc_issued" THEN usa.id END) DESC'
                )
                ->get();

            $service_wise_application = ServiceMaster::leftJoin('user_service_applications as usa', 'service_masters.id', '=', 'usa.service_id')
                ->when($from_date, fn($q) => $q->whereDate('usa.created_at', '>=', $from_date))
                ->when($to_date, fn($q) => $q->whereDate('usa.created_at', '<=', $to_date))
                ->select(
                    'service_masters.id as service_id',
                    'service_masters.service_title_or_description as service_name',
                    DB::raw('COUNT(usa.id) as application_count'),
                    DB::raw('COUNT(CASE WHEN usa.status = "noc_issued" THEN 1 END) as noc_issued_count')
                )
                ->groupBy('service_masters.id', 'service_masters.service_title_or_description')
                ->orderByDesc('application_count')
                ->get();

            $district_wise_application_per_service =
                UserServiceApplication::join('service_masters', 'service_masters.id', '=', 'user_service_applications.service_id')
                ->join('users', 'users.id', '=', 'user_service_applications.user_id')
                ->join('tripura_master_data as tmd', 'tmd.district_code', '=', 'users.district_id')
                ->when($request->filled('district_id'), function ($query) use ($request) {

                    $districtIds = is_array($request->district_id)
                        ? $request->district_id
                        : explode(',', $request->district_id);

                    $query->whereIn('users.district_id', $districtIds);
                })
                ->when($request->filled('service_id'), function ($query) use ($request) {

                    $serviceIds = is_array($request->service_id)
                        ? $request->service_id
                        : explode(',', $request->service_id);

                    $query->whereIn('service_masters.id', $serviceIds);
                })
                ->selectRaw('
            service_masters.id as service_id,
            service_masters.service_title_or_description as service_name,
            tmd.district_name,
            COUNT(*) as count
        ')
                ->groupBy(
                    'service_masters.id',
                    'service_masters.service_title_or_description',
                    'tmd.district_name'
                )
                ->get()
                ->groupBy('service_name')
                ->map(fn($rows, $service) => [
                    'service_name' => $service,
                    'districts' => $rows->map(fn($r) => [
                        'district_name' => $r->district_name,
                        'count' => $r->count
                    ])->values()
                ])
                ->values();

            $year = $request->year ?? now()->year;

            $monthly_application_status = UserServiceApplication::selectRaw('
        MONTH(created_at) as month,
        COUNT(*) as total_applications,
        SUM(CASE WHEN status IN ("submitted","re_submitted","pending","under_review","saved","extra_payment","send_back") THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected_count
    ')
                ->whereYear('created_at', $year)
                ->tap($date_filter)
                ->groupBy(DB::raw('MONTH(created_at)'))
                ->orderBy(DB::raw('MONTH(created_at)'), 'asc')
                ->get();

            $monthly_application_status = $monthly_application_status->map(function ($item) {
                return [
                    'month' => $item->month,
                    'month_name' => date('M', mktime(0, 0, 0, $item->month, 1)),
                    'total_applications' => (int) $item->total_applications,
                    'pending_count' => (int) $item->pending_count,
                    'rejected_count' => (int) $item->rejected_count,
                ];
            });


            return response()->json([
                'status'            => 1,
                'message'           => 'Total count applications under this department fetched successfully',
                'total_applications' => $total_applications,
                'new_users_last_30_days' => $new_users_last_30_days,
                'total_queries' => $total_queries,
                'noc_issued' => $noc_issued,
                'total_count_pending_application' => $total_count_pending_application,
                'total_payments' => $total_payments,
                'upcoming_holidays' => $upcoming_holidays,
                'noc_expired_last_90_days' => $noc_expired_last_90_days,
                'rejected_application'        => $rejected_application,
                'received_applications'        => $received_applications,
                'total_individual_users'       => $total_individual_users,
                'total_inspections'            => $total_inspections,
                'department_wise_application'  => $department_wise_application,
                'service_wise_application'     => $service_wise_application,
                'district_wise_application_per_service'     => $district_wise_application_per_service,
                'monthly_application_status'     => $monthly_application_status,

            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the application count',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
