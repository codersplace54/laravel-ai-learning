<?php

namespace App\Http\Controllers\Service;

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


class ServiceController extends Controller
{
    public function get_total_services()
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $total_services = ServiceMaster::where('status', 1)->count();

            return response()->json([
                'status'        => 1,
                'message' => 'Total Services fetched successfully.',
                'total_services' => $total_services
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching total services',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_applications_count_by_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $total_applications = UserServiceApplication::where('service_id', $request->service_id)->count();

            return response()->json([
                'status'            => 1,
                'message'           => 'Total applications per service fetched successfully',
                'service_id'         => $service->id,
                'service_name'       => $service->service_title_or_description,
                'total_applications' => $total_applications
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the application count',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_total_fees_paid_all_services()
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }


            $total_fee_paid = UserServiceApplication::sum('final_fee');

            return response()->json([
                'status'      => 1,
                'message'     => 'Total fees for all services fetched successfully',
                'total_fee_paid'  => $total_fee_paid
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong while fetching total fees',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function get_total_fees_paid_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $total_fees = UserServiceApplication::where('service_id', $request->service_id)->sum('final_fee');

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Total fees per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'total_fee_paid_per_service'         => $total_fees
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the total fees per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_avg_fees_paid_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $total_fees = UserServiceApplication::where('service_id', $request->service_id)->avg('final_fee');

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Average fees per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'total_fee_paid_per_service'         => $total_fees
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the average fees per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_average_approval_timeline_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $avg_approval_days = UserServiceApplication::where('service_id', $request->service_id)
                ->where('status', 'approved')
                ->selectRaw('AVG(DATEDIFF(updated_at, application_date)) as avg_days')
                ->value('avg_days');

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Average fees per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'avg_approval_days'                  => (int) $avg_approval_days
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the average fees per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_approved_applications_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $approved_applications = UserServiceApplication::where('service_id', $request->service_id)
                ->where('status', 'approved')
                ->count();

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Approved application per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'approved_applications'              => $approved_applications
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the approved application per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_pending_applications_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $pending_applications = UserServiceApplication::where('service_id', $request->service_id)
                ->where('payment_status', 'pending')
                ->count();

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Payment pending application per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'pending_applications'               => $pending_applications
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the Payment pending application per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_services_by_department(Request  $request)
    {

        try {


            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
            ]);

            $services = ServiceMaster::where('department_id', $request->department_id)
                ->where('status', 1)
                ->get([
                    'id as service_id',
                    'service_title_or_description as service_name',
                    'target_days'
                ]);

            foreach ($services as $service) {
                $service->total_applications = UserServiceApplication::where('service_id', $service->service_id)->count();
                $service->pending_applications = UserServiceApplication::where('service_id', $service->service_id)
                    ->where('status', 'submitted')
                    ->count();
            }

            return response()->json([
                'status' => 1,
                'message' => 'Services list fetched successfully.',
                'data' => $services
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching services.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_department_applications(Request $request)
    {

        try {


            $request->validate([
                'department_id'   => 'required|integer|exists:departments,id',
                'status'          => 'nullable|in:submitted,under_review,approved,rejected',
                'service_id'      => 'nullable|integer|exists:service_masters,id',
                'applicant_name'  => 'nullable|string',
                'applicant_phone' => 'nullable|string',
                'date_from'       => 'nullable|date',
                'date_to'         => 'nullable|date|after_or_equal:date_from',
            ]);

            $data = UserServiceApplication::with(['service', 'user'])
                ->whereHas('service', function ($service) use ($request) {
                    $service->where('department_id', $request->department_id);
                });

            if ($request->status) {
                $data->where('status', $request->status);
            }
            if ($request->service_id) {
                $data->where('service_id', $request->service_id);
            }
            if ($request->applicant_name) {
                $data->whereHas('user', function ($service) use ($request) {
                    $service->where('authorized_person_name', 'like', '%' . $request->applicant_name . '%');
                });
            }
            if ($request->applicant_phone) {
                $data->whereHas('user', function ($service) use ($request) {
                    $service->where('mobile_no', 'like', '%' . $request->applicant_phone . '%');
                });
            }
            if ($request->date_from && $request->date_to) {
                $data->whereBetween('application_date', [$request->date_from, $request->date_to]);
            } elseif ($request->date_from) {
                $data->whereDate('application_date', '>=', $request->date_from);
            } elseif ($request->date_to) {
                $data->whereDate('application_date', '<=', $request->date_to);
            }

            $applications = $data->get()->map(function ($application) {
                return [
                    'application_id'      => $application->applicationId,
                    'service_name'        => $application->service->service_title_or_description,
                    'applicant_name'      => $application->user->authorized_person_name,
                    'applicant_phone'     => $application->user->mobile_no,
                    'status'              => $application->status,
                    'submission_date'     => $application->application_date,
                    'current_step_number' => $application->current_step_number,
                    'max_processing_date' => $application->max_processing_date,
                ];
            });

            return response()->json([
                'status' => 1,
                'message' => 'List of applications assigned to the department fetched successfully.',
                'data'    => $applications
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching applications.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_application_details($id)
    {

        try {


            $application = UserServiceApplication::with([
                'service:id,service_title_or_description',
                'user:id,authorized_person_name,mobile_no,email_id',
                'workflow.department:id,name'
            ])
                ->where('id', $id)
                ->first();

            if (!$application) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Application not found'
                ], 404);
            }

            $response = [
                'application_id'  => $application->id,
                'service_id'      => $application->service_id,
                'service_name'    => $application->service->service_title_or_description,
                'user' => [
                    'id'    => $application->user->id,
                    'name'  => $application->user->authorized_person_name,
                    'phone' => $application->user->mobile_no,
                    'email' => $application->user->email_id,
                ],
                'application_data' => $application->application_data ?? [],
                'status'           => $application->status,
                'applied_fee'      => $application->applied_fee,
                'approved_fee'     => $application->approved_fee,
                'payment_status'   => $application->payment_status,
                'workflow' => $application->workflow->map(function ($flow) {
                    return [
                        'step_number'     => $flow->step_number,
                        'step_type'       => $flow->step_type,
                        'department'      => $flow->department->name,
                        'status'          => $flow->status,
                        'action_taken_by' => $flow->action_taken_by,
                        'action_taken_at' => $flow->action_taken_at,
                        'remarks'         => $flow->remarks,
                    ];
                }),
            ];

            return response()->json([
                'status' => 1,
                'data'    => $response
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching application details.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function update_application_status(Request $request, $id)
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'status'         => 'required|in:pending,approved,rejected,under_review,send_back',
                'remarks'        => 'nullable|string',
                'action_taken_by' => 'required|integer|exists:users,id',
            ]);

            DB::beginTransaction();


            $application = UserServiceApplication::where('id', $id)->first();

            $current_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                ->where('step_number', $application->current_step_number)
                ->firstOrFail();

            $current_step->update([
                'status'          => $request->status,
                'remarks'         => $request->remarks,
                'action_taken_by' => $request->action_taken_by,
                'action_taken_at' => now(),
            ]);

            ApplicationWorkflowHistory::create([
                'application_id'  => $application->id,
                'service_id'      => $application->service_id,
                'step_number'     => $current_step->step_number,
                'step_type'       => $current_step->step_type,
                'department_id'   => $current_step->department_id,
                'hierarchy_level' => $current_step->hierarchy_level,
                'status'          => $request->status,
                'action_taken_by' => $request->action_taken_by,
                'action_taken_at' => now(),
                'remarks'         => $request->remarks,
            ]);

            $next_step = null;

            if ($request->status === 'approved') {

                $maxStep = ServiceApprovalFlow::where('service_id', $application->service_id)
                    ->max('step_number');

                if ($current_step->step_number == $maxStep) {
                    $application->update([
                        'status'       => 'approved',
                        'updated_at' => now(),
                    ]);

                    return response()->json([
                        'status' => 1,
                        'message' => 'Application approved successfully. Final step completed.',
                        'data' => [
                            'application_id' => $application->id,
                            'status'         => 'approved',
                        ]
                    ], 200);
                } else {
                    $next_step_flow = ServiceApprovalFlow::where('service_id', $application->service_id)
                        ->where('step_number', '>', $current_step->step_number)
                        ->orderBy('step_number')
                        ->first();

                    if ($next_step_flow) {
                        $next_step = ApplicationWorkflowAssignment::create([
                            'application_id'  => $application->id,
                            'service_id'      => $application->service_id,
                            'step_number'     => $next_step_flow->step_number,
                            'step_type'       => $next_step_flow->step_type,
                            'department_id'   => $next_step_flow->department_id,
                            'hierarchy_level' => $next_step_flow->hierarchy_level,
                            'action_taken_by' => $request->action_taken_by,
                            'action_taken_at' => now(),
                            'remarks'         => $request->remarks,
                            'status'          => 'pending',
                        ]);

                        $application->update([
                            'current_step_number' => $next_step_flow->step_number,
                            'status'              => 'under_review',
                        ]);
                    }
                }
            } elseif ($request->status === 'rejected') {

                $application->update(['status' => 'rejected']);
            } elseif ($request->status === 'send_back') {

                $first_step_flow = ServiceApprovalFlow::where('service_id', $application->service_id)
                    ->orderBy('step_number', 'asc')
                    ->first();

                if ($first_step_flow) {
                    $next_step = ApplicationWorkflowAssignment::create([
                        'application_id'  => $application->id,
                        'service_id'      => $application->service_id,
                        'step_number'     => $first_step_flow->step_number,
                        'step_type'       => $first_step_flow->step_type,
                        'department_id'   => $first_step_flow->department_id,
                        'hierarchy_level' => $first_step_flow->hierarchy_level,
                        'action_taken_by' => $request->action_taken_by,
                        'action_taken_at' => now(),
                        'remarks'         => $request->remarks,
                        'status'          => 'pending',
                    ]);

                    $application->update([
                        'current_step_number' => $first_step_flow->step_number,
                        'status'              => 'submitted',
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status'   => 1,
                'message'   => 'Application status updated',
                'next_step' => $next_step ? [
                    'step_number'     => $next_step->step_number,
                    'department_id'   => $next_step->department_id,
                    'hierarchy_level' => $next_step->hierarchy_level
                ] : null
            ], 200);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while updating status',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_department_dashboard(Request $request)
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
            ]);

            $department = Department::where('id', $request->department_id)
                ->first();

            if (!$department) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Department not found or inactive.'
                ], 404);
            }
            $department_id = $department->id;

            $application = DB::table('user_service_applications as application')
                ->join('service_masters as service', 'service.id', '=', 'application.service_id')
                ->where('service.department_id', $department_id)
                ->select(
                    DB::raw('COUNT(*) as total_applications'),
                    DB::raw("SUM(CASE WHEN application.status = 'submitted' THEN 1 ELSE 0 END) as submitted"),
                    DB::raw("SUM(CASE WHEN application.status = 'under_review' THEN 1 ELSE 0 END) as under_review"),
                    DB::raw("SUM(CASE WHEN application.status = 'approved' THEN 1 ELSE 0 END) as approved"),
                    DB::raw("SUM(CASE WHEN application.status = 'rejected' THEN 1 ELSE 0 END) as rejected")
                )
                ->first();

            $avg_processing_time_days = UserServiceApplication::whereHas('service', function ($val) use ($department_id) {
                $val->where('department_id', $department_id);
            })
                ->selectRaw('AVG(DATEDIFF(max_processing_date, application_date)) as avg_days')
                ->value('avg_days');

            $service_breakdown = DB::table('user_service_applications as application')
                ->join('service_masters as service', 'service.id', '=', 'application.service_id')
                ->where('service.department_id', $department_id)
                ->select(
                    'application.service_id',
                    'service.service_title_or_description as service_name',
                    DB::raw('COUNT(*) as total_applications'),
                    DB::raw("SUM(CASE WHEN application.status = 'submitted' THEN 1 ELSE 0 END) as submitted"),
                    DB::raw("SUM(CASE WHEN application.status = 'under_review' THEN 1 ELSE 0 END) as under_review"),
                    DB::raw("SUM(CASE WHEN application.status = 'approved' THEN 1 ELSE 0 END) as approved"),
                    DB::raw("SUM(CASE WHEN application.status = 'rejected' THEN 1 ELSE 0 END) as rejected")
                )
                ->groupBy('application.service_id', 'service.service_title_or_description')
                ->get();

            return response()->json([
                'status' => 1,
                'data' => [
                    'total_applications'    => $application->total_applications,
                    'submitted'              => $application->submitted,
                    'under_review'           => $application->under_review,
                    'approved'              => $application->approved,
                    'rejected'              => $application->rejected,
                    'avg_processing_time_days' => $avg_processing_time_days ? round($avg_processing_time_days, 2) : 0,
                    'service_breakdown'     => $service_breakdown,
                ]
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching dashboard data',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function get_work_flow_history($application_id)
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $history = ApplicationWorkflowHistory::where('application_id', $application_id)
                ->with(['department:id,name', 'actionTaker:id,authorized_person_name,email_id'])
                ->orderBy('action_taken_at', 'asc')
                ->get();

            $pending_steps = ApplicationWorkflowAssignment::where('application_id', $application_id)
                ->where('status', 'pending')
                ->with('department:id,name')
                ->get();

            $data = [];

            foreach ($history as $entry) {
                $data[] = [
                    'step_number'    => $entry->step_number,
                    'department'     => $entry->department->name,
                    'action_taken_by' => $entry->actionTaker->authorized_person_name. ' (' . $entry->actionTaker->email_id . ')',
                    'status'         => $entry->status,
                    'hierarchy_level' => $entry->hierarchy_level,
                    'remarks'        => $entry->remarks,
                    'timestamp'      => $entry->action_taken_at,
                ];
            }

            foreach ($pending_steps as $step) {
                $data[] = [
                    'step_number' => $step->step_number,
                    'department'  => $step->department->name,
                    'status'      => 'pending',
                ];
            }

            return response()->json([
                'status' => 1,
                'data'    => $data,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching workflow history',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
