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

            $total_applications_for_this_department = Department::find($department_id)->applications()->count();

            $total_count_pending_application_in_department = ApplicationWorkflowAssignment::where('status', 'pending')
                ->where('hierarchy_level', $hierarchy_level)
                ->where('department_id', $request->department_id)
                ->distinct('application_id')
                ->count('application_id');


            $total_count_approved_application_in_department = ApplicationWorkflowAssignment::where('status', 'approved');

            $percentage_pending_application = $total_applications_for_this_department > 0
                ? ($total_count_pending_application_in_department / $total_applications_for_this_department) * 100
                : 0;

            $total_count_approved_application_in_department = ApplicationWorkflowAssignment::query()

                ->where('hierarchy_level', $hierarchy_level)
                ->where('department_id', $request->department_id)
                ->distinct('application_id')
                ->count('application_id');


            $percentage_approved_application = $total_applications_for_this_department > 0
                ? ($total_count_approved_application_in_department / $total_applications_for_this_department) * 100
                : 0;

            $total_count_rejected_application_in_department = UserServiceApplication::where('status', 'rejected')->count();

            $percentage_rejected_application = $total_applications_for_this_department > 0
                ? ($total_count_rejected_application_in_department / $total_applications_for_this_department) * 100
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
                ->with(['application:id,applicationId,NOC_letter_number'])
                ->get(['id', 'application_id', 'status', 'status_file'])
                ->map(function ($item) {
                    return [
                        'application_id'     => $item->application_id,
                        'applicationId'      => $item->application->applicationId ?? null,
                        'NOC_letter_number'  => $item->application->NOC_letter_number ?? null,
                        'status_file'        =>  $item->status_file ? url($item->status_file) : null,
                    ];
                });

            return response()->json([
                'status'            => 1,
                'message'           => 'Total count applications under this department fetched successfully',
                'total_applications_for_this_department' => $total_applications_for_this_department,
                'total_count_pending_application_in_department' => $total_count_pending_application_in_department,
                'percentage_pending_application' => $percentage_pending_application,
                'percentage_approved_application' => $percentage_approved_application,
                'percentage_rejected_application' => $percentage_rejected_application,
                'total_count_approved_application_in_department' => $total_count_approved_application_in_department,
                'number_of_NOC_issued_by_department' => $number_of_NOC_issued_by_department,
                'application_count_per_service' => $application_count_per_service,
                'district_wise_application_in_department' => $district_wise_application_in_department,
                'district_wise_application_per_service' => $district_wise_application_per_service,
                'clarification_required' => $clarification_required,

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
            $total_applications_for_this_user = User::find($user_id)->applications()->count();
            $total_count_pending_application_in_user = UserServiceApplication::where('user_id', $request->user_id)
                ->whereIn('status', ['submitted', 're_submitted', 'pending', 'under_review'])
                ->count();

            $total_count_approved_application_in_user = UserServiceApplication::where('user_id', $request->user_id)
                ->where('status', 'approved')
                ->count();

            $percentage_pending_application = $total_applications_for_this_user > 0
                ? ($total_count_pending_application_in_user / $total_applications_for_this_user) * 100
                : 0;


            $percentage_approved_application = $total_applications_for_this_user > 0
                ? ($total_count_approved_application_in_user / $total_applications_for_this_user) * 100
                : 0;

            $total_count_rejected_application_in_department = UserServiceApplication::where('status', 'rejected')->count();

            $percentage_rejected_application = $total_applications_for_this_user > 0
                ? ($total_count_rejected_application_in_department / $total_applications_for_this_user) * 100
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
                'total_count_pending_application_in_user' => $total_count_pending_application_in_user,
                'percentage_pending_application' => $percentage_pending_application,
                'percentage_approved_application' => $percentage_approved_application,
                'percentage_rejected_application' => $percentage_rejected_application,
                'total_count_approved_application_in_user' => $total_count_approved_application_in_user,
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
}
