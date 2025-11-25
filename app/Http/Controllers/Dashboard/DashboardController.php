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

            $total_services_per_department = ServiceMaster::where('department_id', $department_id)->count();

            $total_applications_for_this_department = Department::find($department_id)->applications()->count();

            $percentage_total_application = $total_applications_for_this_department > 0
                ? min(100, round(($total_applications_for_this_department / $total_services_per_department) * 100, 2))
                : 0;

            $total_count_pending_application_in_department = ApplicationWorkflowAssignment::where('status', 'pending')
                ->where('hierarchy_level', $hierarchy_level)
                ->where('department_id', $request->department_id)
                ->distinct('application_id')
                ->count('application_id');


            $total_count_approved_application_in_department = ApplicationWorkflowAssignment::where('status', 'approved');

            $percentage_pending_application = $total_applications_for_this_department > 0
                ? min(100, round(($total_count_pending_application_in_department / $total_applications_for_this_department) * 100, 2))
                : 0;

            $total_count_approved_application_in_department = ApplicationWorkflowAssignment::query()

                ->where('hierarchy_level', $hierarchy_level)
                ->where('department_id', $request->department_id)
                ->distinct('application_id')
                ->count('application_id');


            $percentage_approved_application = $total_applications_for_this_department > 0
                ? min(100, round(($total_count_approved_application_in_department / $total_applications_for_this_department) * 100, 2))
                : 0;

            $total_count_rejected_application_in_department = UserServiceApplication::where('status', 'rejected')->count();

            $percentage_rejected_application = $total_applications_for_this_department > 0
                ? min(100, round(($total_count_rejected_application_in_department / $total_applications_for_this_department) * 100, 2))
                : 0;


            $number_of_NOC_issued_by_department = UserServiceApplication::where('status', 'approved')
                ->whereHas('latestWorkflow', function ($q) use ($department_id) {
                    $q->where('department_id', $department_id);
                })
                ->count();

            $services = ServiceMaster::withCount('applications')
                ->where('department_id', $department_id)
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

            $clarification_required = ApplicationWorkflowHistory::where('department_id', $department_id)
                ->where('status', 'send_back')
                ->with([
                    'application' => function ($q) {
                        $q->select('id', 'applicationId', 'service_id', 'application_date', 'remarks')
                            ->with('service:id,service_title_or_description');
                    },
                ])
                ->get(['id', 'application_id', 'status', 'status_file'])
                ->map(function ($item) {

                    $workflow   = $application->workflow ?? collect();

                    $latest_send_back_step = $workflow->where('status', 'send_back')
                    ->sortByDesc('action_taken_at')
                    ->first();

                    $send_back_date = $latest_send_back_step
                        ? Carbon::parse($latest_send_back_step->action_taken_at)
                        : null;

                    $application = $item->application;
                    $service     = $application ? $application->service : null;
                    return [
                        'application_id'     => $item->application_id,
                        'applicationId'      => $item->application->applicationId ?? null,
                        'status_file'        =>  $item->status_file ? url($item->status_file) : null,
                        'service_name'              => $service->service_title_or_description ?? null,
                        'remark'                    => $item->remarks ?? null,
                        'application_date'          => $application->application_date ?? null,
                        'clarification_raised_date' => $send_back_date ?? null,
                    ];
                });

            $services_with_license_count = ServiceMaster::where('department_id', $department_id)
                ->withCount([
                    'applications as licenses_issued_count' => function ($q) {
                        $q->where('status', 'approved');
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
                ->where('status', 'approved')
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
                        $total_days += $application->application_date->diffInDays(
                            $application->latestWorkflow->updated_at
                        );
                    }

                    $avg_days = $count > 0 ? $total_days / $count : 0;

                    return [
                        'service_id'        => $service_id,
                        'service_name'      => $service->service_title_or_description,
                        'avg_approval_days' => round($avg_days, 2),
                    ];
                })
                ->values();



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
                'avg_approval_time_per_service'  => $avg_approval_time_per_service
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

            $total_applications_for_this_user = User::find($user_id)->applications()->count();

            $percentage_total_application = $total_applications_for_this_user > 0
                ? min(100, round(($total_applications_for_this_user / $total_services_per_user) * 100, 2))
                : 0;

            $total_count_pending_application_in_user = UserServiceApplication::where('user_id', $request->user_id)
                ->whereIn('status', ['submitted', 're_submitted', 'pending', 'under_review'])
                ->count();

            $total_count_approved_application_in_user = UserServiceApplication::where('user_id', $request->user_id)
                ->where('status', 'approved')
                ->count();

            $percentage_pending_application = $total_applications_for_this_user > 0
                ? min(100, round(($total_count_pending_application_in_user / $total_applications_for_this_user) * 100, 2))
                : 0;


            $percentage_approved_application = $total_applications_for_this_user > 0
                ? min(100, round(($total_count_approved_application_in_user / $total_applications_for_this_user) * 100, 2))
                : 0;

            $total_count_rejected_application_in_department = UserServiceApplication::where('status', 'rejected')->count();

            $percentage_rejected_application = $total_applications_for_this_user > 0
                ? min(100, round(($total_count_rejected_application_in_department / $total_applications_for_this_user) * 100, 2))
                : 0;

            $services = ServiceMaster::withCount('applications')
                ->get(['id', 'service_title_or_description']);

            $application_count_per_service = $services->map(function ($service) {
                return [
                    'service_id' => $service->id,
                    'service_name' => $service->service_title_or_description,
                    'application_count' => $service->applications_count,
                ];
            });

            $clarification_required = UserServiceApplication::where('user_id', $request->user_id)
                ->whereHas('workflowHistory', function ($q) {
                    $q->where('status', 'send_back');
                })
                ->with([
                    'workflowHistory' => function ($q) {
                        $q->where('status', 'send_back')
                            ->orderBy('id', 'desc');
                    },
                    'workflowHistory.department'
                ])
                ->get()
                ->map(function ($app) {

                    $latest_send_back = $app->workflowHistory->first();

                    return [
                        'application_id'    => $app->id,
                        'applicationId'     => $app->applicationId,
                        'service_id'        => $app->service_id,
                        'department_name'   => $latest_send_back && $latest_send_back->department
                            ? $latest_send_back->department->name
                            : null,
                        'NOC_letter_number' => $app->NOC_letter_number,
                        'status_file'       => $latest_send_back && $latest_send_back->status_file
                            ? url($latest_send_back->status_file)
                            : null,
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
                '$total_count_rejected_application_in_department' => $total_count_rejected_application_in_department,
                'application_count_per_service' => $application_count_per_service,
                'clarification_required'       => $clarification_required,

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

            $total_applications = UserServiceApplication::count();

            $percentage_total_application = $total_services > 0
                ? min(100, round(($total_applications / $total_services) * 100, 2))
                : 0;

            $total_count_pending_application = UserServiceApplication::whereIn('status', ['submitted', 're_submitted', 'pending', 'under_review'])
                ->count();

            $total_count_approved_application = UserServiceApplication::where('status', 'approved')
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
                ->get(['id', 'service_title_or_description']);

            $application_count_per_service = $services->map(function ($service) {
                return [
                    'service_id' => $service->id,
                    'service_name' => $service->service_title_or_description,
                    'application_count' => $service->applications_count,
                ];
            });



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
                '$total_count_rejected_application' => $total_count_rejected_application,
                'application_count_per_service' => $application_count_per_service,

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
