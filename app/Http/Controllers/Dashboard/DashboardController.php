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

            $total_count_pending_application_in_department = UserServiceApplication::whereIn('id', $department_app_ids)
                ->whereIn('status', ['pending', 'under_review', 're_submitted', 'saved', 'submitted', 'extra_payment', 'send_back'])
                ->count();

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

            $district_wise_application_in_department = UserServiceApplication::with(['service', 'user.district'])
                ->whereHas('service', function ($q) use ($department_id) {
                    $q->where('department_id', $department_id);
                })
                ->select('user_id')
                ->with('user.district')
                ->get()
                ->groupBy(fn($app) => $app->user?->district?->district_name)
                ->map(fn($group, $district_name) => [
                    'district_name' => $district_name,
                    'count' => $group->count(),
                ])
                ->values();

            $district_wise_application_per_service = UserServiceApplication::with(['service', 'user.district'])
                ->whereHas('service', function ($q) use ($department_id) {
                    $q->where('department_id', $department_id);
                })
                ->get()
                ->groupBy(fn($app) => $app->service?->service_title_or_description)
                ->map(function ($appsPerService, $serviceName) {
                    return [
                        'service_name' => $serviceName,
                        'districts' => $appsPerService
                            ->groupBy(fn($app) => $app->user?->district?->district_name)
                            ->map(fn($apps_per_district, $district_name) => [
                                'district_name' => $district_name,
                                'count' => $apps_per_district->count(),
                            ])
                            ->values(),
                    ];
                })
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
                    $q->where('payment_status', 'paid')
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


            return response()->json([
                'status'            => 1,
                'message'           => 'Total count applications under this department fetched successfully',
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
                'license_issued_per_service'     => $license_issued_per_service,
                'avg_approval_time_per_service'  => $avg_approval_time_per_service,
                'total_pending_for_me'          => $total_pending_for_me,
                'total_approved_by_me'          => $total_approved_by_me,
                'total_individual_users'        => $total_individual_users
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

            $total_services_per_user = ServiceMaster::count();

            $total_application = UserServiceApplication::whereNotIn('status', ['expired', 'draft'])->count();

            $total_applications_for_this_user = User::find($user_id)->applications()->whereNotIn('status', ['expired', 'draft'])->count();

            $percentage_total_application = $total_applications_for_this_user > 0
                ? min(100, round(($total_applications_for_this_user / $total_application) * 100, 2))
                : 0;

            $total_count_pending_application_in_user = UserServiceApplication::where('user_id', $request->user_id)
                ->whereIn('status', ['submitted', 're_submitted', 'pending', 'under_review', 'saved', 'extra_payment', 'send_back'])
                ->count();

            $total_count_approved_application_in_user = UserServiceApplication::where('user_id', $request->user_id)
                ->whereIn('status', ['approved', 'noc_issued'])
                ->count();

            $percentage_pending_application = $total_applications_for_this_user > 0
                ? min(100, round(($total_count_pending_application_in_user / $total_applications_for_this_user) * 100, 2))
                : 0;


            $percentage_approved_application = $total_applications_for_this_user > 0
                ? min(100, round(($total_count_approved_application_in_user / $total_applications_for_this_user) * 100, 2))
                : 0;

            $total_count_rejected_application_in_user = UserServiceApplication::where('user_id', $request->user_id)
                ->where('status', 'rejected')
                ->count();

            $percentage_rejected_application = $total_applications_for_this_user > 0
                ? min(100, round(($total_count_rejected_application_in_user / $total_applications_for_this_user) * 100, 2))
                : 0;

            $services = ServiceMaster::withCount(['applications as user_application_count' => function ($q) use ($user_id) {
                $q->where('user_id', $user_id);
            }])
                ->orderByRaw('user_application_count = 0')
                ->orderBy('id', 'desc')
                ->get(['id', 'service_title_or_description']);

            $application_count_per_service = $services->map(function ($service) {
                return [
                    'service_id' => $service->id,
                    'service_name' => $service->service_title_or_description,
                    'application_count' => $service->user_application_count,
                ];
            });

            $services_with_noc_count = ServiceMaster::withCount([
                'applications as noc_issued_count' => function ($q) use ($user_id) {
                    $q->where('user_id', $user_id)
                        ->whereNotNull('NOC_certificate')
                        ->where('status', 'noc_issued');
                }
            ])->having('noc_issued_count', '>', 0)
                ->get(['id', 'service_title_or_description']);

            $noc_issued_per_service = $services_with_noc_count->map(function ($service) {
                return [
                    'service_id'      => $service->id,
                    'service_name'    => $service->service_title_or_description,
                    'noc_issued'      => $service->noc_issued_count,
                ];
            });


            $clarification_required = UserServiceApplication::where('user_id', $request->user_id)
                ->whereHas('latestWorkflow', function ($q) {
                    $q->where('status', 'send_back');
                })
                ->with([
                    'latestWorkflow' => function ($q) {
                        $q->where('status', 'send_back')
                            ->orderBy('id', 'desc');
                    },
                    'latestWorkflow.department',
                    'workflowHistory' => function ($q) {
                        $q->where('status', 'send_back')
                            ->orderBy('id', 'desc');
                    },
                    'workflowHistory.department',
                    'service:id,service_title_or_description'
                ])
                ->get()
                ->map(function ($app) {

                    $latest_send_back = $app->latestWorkflow && $app->latestWorkflow->status_file
                        ? $app->latestWorkflow
                        : $app->workflowHistory->first();
                    return [
                        'application_id'    => $app->id,
                        'applicationId'     => $app->applicationId, // applicationId is the application number saved in user service applications table
                        'service_id'        => $app->service_id,
                        'service_name'      => $app->service->service_title_or_description ?? null,
                        'department_name'   => $latest_send_back && $latest_send_back->department
                            ? $latest_send_back->department->name
                            : null,
                        'NOC_letter_number' => $app->NOC_letter_number,
                        'status_file'       => $latest_send_back?->status_file
                            ? url($latest_send_back->status_file)
                            : null,
                        'comments'        => $app->comments,
                        'remarks'        => $latest_send_back->remarks,
                    ];
                });



            return response()->json([
                'status'            => 1,
                'message'           => 'Total count applications under this department fetched successfully',
                'total_applications_for_this_user' => $total_applications_for_this_user,
                'percentage_total_application' => $percentage_total_application,
                'total_count_pending_application_in_user' => $total_count_pending_application_in_user,
                'percentage_pending_application' => $percentage_pending_application,
                'percentage_approved_application' => $percentage_approved_application,
                'percentage_rejected_application' => $percentage_rejected_application,
                'total_count_approved_application_in_user' => $total_count_approved_application_in_user,
                'total_count_rejected_application_in_user' => $total_count_rejected_application_in_user,
                'application_count_per_service' => $application_count_per_service,
                'clarification_required'       => $clarification_required,
                'noc_issued_per_service'        => $noc_issued_per_service

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
            });

            $total_individual_users = User::whereNull('old_id')
                ->where('user_type', 'individual')
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
