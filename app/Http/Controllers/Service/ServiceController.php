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
use App\Models\User;
use App\Models\ServiceQuestionnaire;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ServiceApplicationExport;
use App\Services\ApplicationDataFormatter;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use setasign\Fpdi\Fpdi;
use App\Services\SmsService;
use App\Jobs\SendWhatsAppNotification;
use App\Models\DepartmentUser;
use App\Models\PaymentOrder;
use Carbon\Carbon;
use App\Traits\PaymentMapTrait;
use Illuminate\Support\Facades\Log;

class ServiceController extends Controller
{
    use PaymentMapTrait;
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

            $search = $request->search ?? null;

            $department_user = User::where('id', Auth::id())->first();

            $services_query = ServiceMaster::with('department')->where('department_id', $request->department_id)
                ->where('status', 1);

            if ($search) {
                $services_query->where(function ($q) use ($search) {
                    $q->where('service_title_or_description', 'like', "%{$search}%")
                        ->orWhereHas('department', function ($q2) use ($search) {
                            $q2->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $services = $services_query->get([
                'id as service_id',
                'service_title_or_description as service_name',
                'target_days',
                'department_id'

            ]);

            $hierarchy_column_map = [
                'block'         => 'block_id',
                'subdivision1'  => 'subdivision_id',
                'subdivision2'  => 'subdivision_id',
                'subdivision3'  => 'subdivision_id',
                'district1'     => 'district_id',
                'district2'     => 'district_id',
                'district3'     => 'district_id'
            ];

            foreach ($services as $service) {
                $service->department_name = $service->department->name ?? null;
                unset($service->department);
                $service->total_applications = UserServiceApplication::where('service_id', $service->service_id)->count();
                $latest_assignments = ApplicationWorkflowAssignment::where('service_id', $service->service_id)
                    ->where('status', 'pending')
                    ->orderByDesc('id')
                    ->get()
                    ->groupBy('application_id')
                    ->map(fn($group) => $group->first());

                $pending_count = 0;

                foreach ($latest_assignments as $assignment) {
                    $application = UserServiceApplication::find($assignment->application_id);
                    if (!$application) continue;

                    $applicant_user = User::find($application->user_id);
                    if (!$applicant_user) continue;

                    $location_column = $hierarchy_column_map[$assignment->hierarchy_level] ?? null;
                    if (!$location_column) continue;

                    if ($applicant_user->$location_column == $department_user->$location_column) {
                        $pending_count++;
                    }
                }

                $service->pending_applications = $pending_count;
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


            $per_page = $request->per_page ?? 10;

            $request->validate([
                'department_id'     => 'nullable|array',
                'department_id.*'   => 'nullable|integer|exists:departments,id',
                'status'            => 'nullable|in:submitted,under_review,approved,rejected,saved,extra_payment,re_submitted,send_back,noc_issued',
                'service_id'        => 'nullable|array',
                'service_id.*'      => 'nullable|integer|exists:service_masters,id',
                'applicant_name'  => 'nullable|string',
                'applicant_phone' => 'nullable|string',
                'date_from'       => 'nullable|date',
                'date_to'         => 'nullable|date|after_or_equal:date_from',
                'hierarchy_level' => 'nullable|string',
            ]);

            $data = UserServiceApplication::with(['service', 'user', 'latestWorkflow'])
                ->where('status', '!=', 'draft')
                ->whereHas('service', function ($service) use ($request) {

                    if ($request->filled('department_id')) {
                        $service->whereIn('department_id', $request->department_id);
                    }

                    if ($request->filled('service_id')) {
                        $service->whereIn('service_id', $request->service_id);
                    }
                });

            if ($request->filled('search')) {

                $search = $request->search;
                $data->where(function ($q) use ($search) {

                    $q->where('applicationId', 'like', "%{$search}%")

                        ->orWhereHas('user', function ($u) use ($search) {
                            $u->where('authorized_person_name', 'like', "%{$search}%")
                                ->orWhere('mobile_no', 'like', "%{$search}%")
                                ->orWhere('name_of_enterprise', 'like', "%{$search}%");
                        });
                });
            }


            if ($request->status) {
                $data->where('status', $request->status);
            }

            if ($request->filled('hierarchy_level')) {
                $data->whereHas('latestWorkflow', function ($q) use ($request) {
                    $q->where('hierarchy_level', $request->hierarchy_level);
                });
            }

            if ($request->filled('district_id')) {
                $data->whereHas('user', function ($q) use ($request) {
                    $q->where('district_id', $request->district_id);
                });
            }

            if ($request->filled('subdivision_id')) {
                $data->whereHas('user', function ($q) use ($request) {
                    $q->where('subdivision_id', $request->subdivision_id);
                });
            }

            if ($request->date_from && $request->date_to) {
                $data->whereBetween('application_date', [$request->date_from, $request->date_to]);
            } elseif ($request->date_from) {
                $data->whereDate('application_date', '>=', $request->date_from);
            } elseif ($request->date_to) {
                $data->whereDate('application_date', '<=', $request->date_to);
            }

            $applications_data = $data
                ->orderByDesc('id')
                ->paginate($per_page);
            $application_ids = collect($applications_data->items())->pluck('id')->toArray();
            $payment_map = $this->payment_map_for_applications($application_ids);

            $applications = collect($applications_data->items())
                ->map(function ($application) use ($payment_map) {
                    return [
                        'application_id'      => $application->id,
                        'application_number'  => $application->applicationId,
                        'service_name'        => $application->service->service_title_or_description,
                        'applicant_name'      => optional($application->user)->authorized_person_name,
                        'name_of_enterprise'  => optional($application->user)->name_of_enterprise,
                        'applicant_phone'     => $application->user->mobile_no,
                        'status'              => $application->status,
                        'submission_date'     => $application->application_date,
                        'final_fee'           => $application->final_fee,
                        'extra_payment'       => $application->extra_payment ?? 0,
                        'total_fee'           => $application->total_fee  ?? 0,
                        'payment_status'      => $application->payment_status,
                        'current_step_number' => $application->current_step_number,
                        'max_processing_date' => $application->max_processing_date,
                        'district_code'   => $application->user->district->district_code ?? null,
                        'district_name' => $application->user->district->district_name ?? null,
                        'subdivision_code'   => $application->user->subdivision->sub_lgd_code ?? null,
                        'subdivision_name' =>  $application->user->subdivision->sub_division ?? null,
                        'ulb_code'   => $application->user->ulb->ulb_lgd_code ?? null,
                        'ulb_name' => $application->user->ulb->ulb_name ?? null,
                        'ward_code'   => $application->user->ward->gp_vc_ward_lgd_code ?? null,
                        'ward_name' => $application->user->ward->name_of_gp_vc_or_ward ?? null,
                        'hierarchy_level'     => $application->latestWorkflow->hierarchy_level ?? null,
                        'payment_details'     => $payment_map[$application->id] ?? [],
                        'renewal' => $application->renewal === 'yes' ? 'YES' : 'NO',
                        'previous_application_id' => $application->previous_application_id,
                    ];
                });

            return response()->json([
                'status' => 1,
                'message' => 'List of applications assigned to the department fetched successfully.',
                'data'    => $applications,
                'pagination' => [
                    'current_page' => $applications_data->currentPage(),
                    'last_page'    => $applications_data->lastPage(),
                    'per_page'     => $applications_data->count(),
                    'total'        => $applications_data->total(),
                    'next_page_url' => $applications_data->nextPageUrl(),
                    'prev_page_url' => $applications_data->previousPageUrl(),
                ]
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
                'user:id,authorized_person_name,mobile_no,email_id,district_id,subdivision_id,ulb_id,ward_id',
                'workflow.department:id,name',
                'workflow.actionTaker:id,authorized_person_name,email_id'
            ])
                ->where('id', $id)
                ->first();

            if (!$application) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Application not found'
                ], 404);
            }

            $auth_user = Auth::user();

            $current_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                ->orderByDesc('id')
                ->first();

            $max_step_number = ServiceApprovalFlow::where('service_id', $application->service_id)
                ->max('step_number');

            $final_step = ServiceApprovalFlow::where('service_id', $application->service_id)
                ->where('step_number', $max_step_number)
                ->first();

            $is_eligible_for_certificate_action = false;
            if ($auth_user->user_type == 'department' && $final_step && $current_step) {
                $dept_user_match = DepartmentUser::where('user_id', $auth_user->id)
                    ->where('department_id', $final_step->department_id)
                    ->where('hierarchy_level', $current_step->hierarchy_level)
                    ->exists();
                if ($dept_user_match) {
                    $is_eligible_for_certificate_action = true;
                }
            }

            $is_just_before_final_step = false;
            $is_finally_approved = false;

            if ($current_step && $current_step->step_number == $max_step_number) {
                $is_just_before_final_step = true;
                if ($current_step->status == 'approved') {
                    $is_finally_approved = true;
                }
            }

            // $formatted_data = [];
            // $application_data = json_decode($application->application_data, true);
            // if (!empty($application_data)) {
            //     $questions = ServiceQuestionnaire::whereIn('id', array_keys($application_data))
            //         ->pluck('question_label', 'id');
            //     foreach ($application_data as $question_id => $answer) {
            //         $formatted_data[] = [
            //             'id' => $question_id,
            //             'question' => $questions[$question_id] ?? 'Question not found',
            //             'answer'   => $answer,
            //         ];
            //     }
            // }

            $formatter = new ApplicationDataFormatter();
            $formatted_application_data = $formatter->build_application_view_data($application);

            $history_data = ApplicationWorkflowHistory::where('application_id', $application->id)
                ->orderByDesc('id')
                ->first();

            $step_files = ApplicationWorkflowHistory::where('application_id', $application->id)
                ->orderByDesc('id')
                ->get();

            if ($history_data) {
                $history_data = [
                    'id'             => $history_data->id,
                    'step_number'    => $history_data->step_number,
                    'status'         => $history_data->status,
                    'remarks'        => $history_data->remarks,
                    'status_file'    => !empty($history_data->status_file)
                        ? asset('storage/' . $history_data->status_file)
                        : null,
                    'action_taken_at' => $history_data->action_taken_at,
                    'action_taken_by' => optional($history_data->actionTaker)->authorized_person_name
                        ? optional($history_data->actionTaker)->authorized_person_name . ' (' . optional($history_data->actionTaker)->email_id . ')'
                        : null,
                ];
            }

            $license_details = [
                'NOC_generationDate' => $application->NOC_generationDate,
                'NOC_expiry_date' => $application->NOC_expiry_date,
                'license_id' => $application->license_id,
                'NOC_mode' => $application->NOC_mode,
                'NOC_certificate' => $application->noc_certificate_url,
            ];

            $is_land_allotment = $application->service_id == 64;
            $land_allotment_details = [
                'is_land_allotment' => $is_land_allotment,
                'land_allotment_estimate_amount' => $is_land_allotment ? $application->applied_fee : null,
                'land_allotment_approved_amount' => $is_land_allotment ? $application->total_fee : null
            ];

            $payment_details = PaymentOrder::whereJsonContains('application_id', $application->id)
                ->whereNot('payment_status', 'pending')
                ->get()
                ->map(function ($p) {
                    return [
                        'id'               => $p->id,
                        'payment_amount'   => $p->payment_amount,
                        'payment_status'   => $p->payment_status,
                        'gateway'          => $p->gateway,
                        'gateway_order_id' => $p->gateway_order_id,
                        'transaction_id'   => $p->transaction_id,
                        'GRN_number'       => $p->GRN_number,
                        'payment_datetime' => $p->payment_datetime,
                        'created_at'       => $p->created_at,
                    ];
                });

            $response = [
                'application_id'  => $application->id,
                'application_number'  => $application->applicationId,
                'service_id'      => $application->service_id,
                'service_name'    => $application->service->service_title_or_description,
                'user' => [
                    'id'    => $application->user->id,
                    'name'  => $application->user->authorized_person_name,
                    'phone' => $application->user->mobile_no,
                    'email' => $application->user->email_id,
                    'district_code'   => $application->user->district->district_code ?? null,
                    'district_name' => $application->user->district->district_name ?? null,
                    'subdivision_code'   => $application->user->subdivision->sub_lgd_code ?? null,
                    'subdivision_name' =>  $application->user->subdivision->sub_division ?? null,
                    'ulb_code'   => $application->user->ulb->ulb_lgd_code ?? null,
                    'ulb_name' => $application->user->ulb->ulb_name ?? null,
                    'ward_code'   => $application->user->ward->gp_vc_ward_lgd_code ?? null,
                    'ward_name' => $application->user->ward->name_of_gp_vc_or_ward ?? null,
                ],
                'application_data' => $formatted_application_data,
                'status'           => $application->status,
                'applied_fee'      => $application->applied_fee,
                'approved_fee'     => $application->approved_fee,
                'application_fee'     => $application->final_fee,
                'extra_payment'        => $application->extra_payment ?? 0,
                'total_fee'         => $application->total_fee ?? 0,
                'payment_status'   => $application->payment_status,
                'workflow' => $application->workflow->map(function ($flow) use ($step_files, $application) {
                    $history = $step_files->first(function ($h) use ($flow) {
                        return $h->step_number == $flow->step_number
                            && $h->status === $flow->status;
                    });
                    $actionTaker = $flow->actionTaker;

                    $max_step_number = $application->workflow->max('step_number');
                    $display_status = $flow->status;

                    if (
                        $flow->status === 'approved' &&
                        $flow->step_number != $max_step_number
                    ) {
                        $display_status = 'forwarded';
                    }

                    return [
                        'step_number'     => $flow->step_number,
                        'step_type'       => $flow->step_type,
                        'department'      => $flow->department?->name,
                        'status'          => $display_status,
                        'action_taken_by' => $actionTaker
                            ? "{$actionTaker->authorized_person_name} ({$actionTaker->email_id})"
                            : null,
                        'action_taken_at' => $flow->action_taken_at,
                        'hierarchy_level'     => $flow->hierarchy_level,
                        'remarks'         => $flow->remarks,
                        'status_file'     => $history && $history->status_file
                            ? asset('storage/' . $history->status_file)
                            : null,
                    ];
                }),
                'just_before_final_step'  => $is_just_before_final_step,
                'is_finally_approved'  => $is_finally_approved,
                'is_eligible_for_certificate_action' => $is_eligible_for_certificate_action,
                'history_data'    => $history_data,
                'is_certificate_generated'    => $application->NOC_certificate ? true : false,
                'created_at'    => $application->created_at,
                'updated_at'    => $application->updated_at,
                'license_details' => $license_details,
                'land_allotment_details' => $land_allotment_details,
                'payment_details' => $payment_details,
                'renewal' => $application->renewal === 'yes' ? 'YES' : 'NO',
                'previous_application_id' => $application->previous_application_id,
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
                'status'         => 'required|in:pending,approved,rejected,under_review,send_back,extra_payment',
                'status_file'    => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:3072',
                'remarks'        => 'nullable|string'
            ]);

            DB::beginTransaction();


            $application = UserServiceApplication::where('id', $id)->first();

            $current_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                ->where('step_number', $application->current_step_number)
                ->latest('id')
                ->firstOrFail();

            if ($current_step->hierarchy_level !== $user->department_user->hierarchy_level) {
                return response()->json([
                    'status'  => 0,
                    'message' => "You can't update this application. It's assigned to level {$current_step->hierarchy_level} department users."
                ], 403);
            }

            $current_step->update([
                'status'          => $request->status,
                'remarks'         => $request->remarks,
                'action_taken_by' => $user->id,
                'action_taken_at' => now(),
            ]);

            $status_file = null;
            if ($request->hasFile('status_file')) {
                $file = $request->file('status_file');
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $status_file = $file->storeAs("uploads/$user->id/application_status", $filename, 'public');
            }

            ApplicationWorkflowHistory::create([
                'application_id'  => $application->id,
                'service_id'      => $application->service_id,
                'step_number'     => $current_step->step_number,
                'step_type'       => $current_step->step_type,
                'department_id'   => $current_step->department_id,
                'hierarchy_level' => $current_step->hierarchy_level,
                'status'          => $request->status,
                'status_file'     => $status_file,
                'action_taken_by' => $user->id,
                'action_taken_at' => now(),
                'remarks'         => $request->remarks,
            ]);

            $department_name = null;

            if ($current_step->department_id) {
                $department_name = Department::where('id', $current_step->department_id)
                    ->value('name');
            }

            $next_step = null;

            if ($request->status === 'approved') {

                $max_step = ServiceApprovalFlow::where('service_id', $application->service_id)
                    ->max('step_number');

                if ($application->service_id == 64) {
                    $application->update([
                        'total_fee' => $request->land_allotment_approved_amount,
                        'payment_status' => 'pending'
                    ]);
                }

                if ($current_step->step_number == $max_step) {

                    $application->update([
                        'status'       => 'approved',
                        'updated_at' => now(),
                    ]);

                    $this->add_qr_to_by_law_file($application);

                    if ($application->status === 'approved') {
                        $sms = SmsService::buildSmsMessage('application_approved', [
                            'APP_NO' => $application->applicationId,
                            'DEPT_NAME' => $department_name ?? 'the department',
                        ]);

                        SmsService::send(
                            $application->user->mobile_no,
                            $sms['message'],
                            $sms['template_id']
                        );

                        SendWhatsAppNotification::dispatch(
                            $application->user->mobile_no,
                            'application_approved_v2',
                            [
                                $application->applicationId,
                                $application->service->service_title_or_description,
                                'Approved',
                                Carbon::parse($application->updated_at)->format('d M Y, g:i A')
                            ]
                        );
                    }

                    DB::commit();

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
                            'action_taken_by' =>  null,
                            'action_taken_at' => null,
                            'status'          => 'pending',
                        ]);

                        $application->update([
                            'current_step_number' => $next_step_flow->step_number,
                            'status'              => 'under_review',
                        ]);

                        $next_department_name = Department::where('id', $next_step_flow->department_id)
                            ->value('name');

                        SendWhatsAppNotification::dispatch(
                            $application->user->mobile_no,
                            'application_forwarded_v1',
                            [
                                $application->applicationId,
                                $application->service->service_title_or_description,
                                'Under Review',
                                $next_department_name ?? 'Next Department',
                                Carbon::parse($application->updated_at)->format('d M Y, g:i A')
                            ]
                        );
                    }
                }
            } elseif ($request->status === 'rejected') {

                $application->update(['status' => 'rejected']);

                SendWhatsAppNotification::dispatch(
                    $application->user->mobile_no,
                    'application_rejected_v1',
                    [
                        $application->applicationId,
                        $application->service->service_title_or_description,
                        Carbon::parse($application->updated_at)->format('d M Y, g:i A'),
                        $request->remarks ?? 'No reason provided'
                    ]
                );
            } elseif ($request->status === 'send_back') {

                $first_step_flow = ServiceApprovalFlow::where('service_id', $application->service_id)
                    ->orderBy('step_number', 'asc')
                    ->first();

                if ($first_step_flow) {
                    $application->update([
                        'status'              => 'send_back',
                        'updated_at' => now(),
                    ]);

                    SendWhatsAppNotification::dispatch(
                        $application->user->mobile_no,
                        'application_sent_back_v1',
                        [
                            $application->service->service_title_or_description,
                            'Returned',
                            Carbon::parse($application->updated_at)->format('d M Y, g:i A'),
                            $request->remarks ?? 'No reason provided',
                            $application->applicationId,
                        ]
                    );
                }
            } elseif ($request->status === 'extra_payment') {

                $request->validate([
                    'extra_payment' => 'required|integer',
                ]);


                $current_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                    ->where('step_number', $application->current_step_number)
                    ->latest('id')
                    ->first();

                if ($current_step) {

                    $current_step->update([
                        'status'          => 'extra_payment',
                        'remarks'         => $request->remarks,
                        'action_taken_by' => $user->id,
                        'action_taken_at' => now(),
                    ]);

                    $effective_fee = ($application->final_fee + $request->extra_payment) - ($application->paid_amount ?? 0);

                    if ($effective_fee < 0) {
                        $effective_fee = 0;
                    }

                    $application->update([
                        'payment_status'      => 'pending',
                        'effective_fee'       =>  $effective_fee,
                        'extra_payment'       => $request->extra_payment,
                        'remarks'             => $request->remarks,
                        'status'              => 'extra_payment',
                    ]);

                    SendWhatsAppNotification::dispatch(
                        $application->user->mobile_no,
                        'application_extra_payment_raised_v1',
                        [
                            $application->applicationId,
                            $application->service->service_title_or_description,
                            $request->extra_payment,
                            $request->remarks ?? 'Additional payment required',
                            Carbon::parse($application->updated_at)->format('d M Y, g:i A')
                        ]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'status'   => 1,
                'message'   => 'Application status updated',
                'next_step' => $next_step ? [
                    'step_number'     => $next_step->step_number,
                    'step_type'     => $next_step->step_type,
                    'department_id'   => $next_step->department_id,
                    'hierarchy_level' => $next_step->hierarchy_level
                ] : null
            ], 200);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while updating status',
                'error'   => $e->getMessage(),
                'line' => $e->getLine()
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
                $actionTaker = $entry->actionTaker;
                $data[] = [
                    'step_number'    => $entry->step_number,
                    'department'     => $entry->department->name,
                    'action_taken_by' => $actionTaker
                        ? "{$actionTaker->authorized_person_name} ({$actionTaker->email_id})"
                        : null,
                    'status'         => $entry->status,
                    'hierarchy_level' => $entry->hierarchy_level,
                    'remarks'        => $entry->remarks,
                    'status_file'    => asset(Storage::url($entry->status_file)),
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

    public function get_department_user_assigned_applications(Request $request, $user_id)
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

            $latest_assignments = ApplicationWorkflowAssignment::selectRaw("MAX(id) as id")
                ->groupBy('application_id')
                ->pluck('id');

            $query = ApplicationWorkflowAssignment::with([
                'application.service:id,service_title_or_description,department_id',
                'application.user:id,authorized_person_name,name_of_enterprise,email_id,mobile_no,district_id,subdivision_id,ulb_id'
            ])
                ->whereIn('id', $latest_assignments)
                ->where('status', 'pending')
                ->where('hierarchy_level', $hierarchy_level)
                ->whereHas('application', function ($q) use ($department_id) {
                    $q->where('payment_status', 'paid')
                        ->where('department_id', $department_id);
                });

            $query->whereHas('application.user', function ($q) use ($hierarchy_level, $dept_user) {
                $q->where(function ($loc) use ($hierarchy_level, $dept_user) {

                    foreach ($dept_user as $d) {
                        if ($hierarchy_level === 'block') {
                            $loc->orWhere('ulb_id', $d->block_id);
                        } elseif (str_starts_with($hierarchy_level, 'subdivision')) {
                            $loc->orWhere('subdivision_id', $d->subdivision_id);
                        } elseif (str_starts_with($hierarchy_level, 'district')) {
                            if (strtolower($d->district->district_name ?? '') === 'west tripura') {
                                $loc->orWhere(function ($q) use ($d) {
                                    $q->where('district_id', $d->district_id)
                                        ->where('ch_name', $d->ch_name);
                                });
                            } else {
                                $loc->orWhere('district_id', $d->district_id);
                            }
                        }
                        // elseif (str_starts_with($hierarchy_level, 'state')) {
                        // }
                    }
                });
            });

            if ($request->search) {
                $search = $request->search;
                $query->whereHas('application', function ($q) use ($search) {
                    $q->where('applicationId', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($u) use ($search) {
                            $u->where('authorized_person_name', 'like', "%{$search}%")
                                ->orWhere('mobile_no', 'like', "%{$search}%")
                                ->orWhere('name_of_enterprise', 'like', "%{$search}%");
                        });
                });
            }

            if ($request->service_id) {
                $query->whereHas('application.service', function ($q) use ($request) {
                    $q->where('id', $request->service_id);
                });
            }

            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereHas('application', function ($q) use ($request) {
                    $q->whereDate('application_date', '>=', $request->date_from)
                        ->whereDate('application_date', '<=', $request->date_to);
                });
            } elseif ($request->filled('date_from')) {
                $query->whereHas('application', function ($q) use ($request) {
                    $q->whereDate('application_date', '>=', $request->date_from);
                });
            } elseif ($request->filled('date_to')) {
                $query->whereHas('application', function ($q) use ($request) {
                    $q->whereDate('application_date', '<=', $request->date_to);
                });
            }

            $query->orderByDesc('id');
            $applications = $query->paginate($per_page);

            $applications->getCollection()->transform(function ($assignment) use ($hierarchy_level) {
                return [
                    'application_id'   => $assignment->application->id ?? null,
                    'application_number' => $assignment->application->applicationId ?? null,
                    'max_processing_date' => $assignment->application->max_processing_date ?? null,
                    'application_date' => $assignment->application->application_date ?? null,
                    'service_name'     => $assignment->application->service->service_title_or_description ?? null,
                    'applicant_name'   => $assignment->application->user->authorized_person_name ?? null,
                    'applicant_email'  => $assignment->application->user->email_id ?? null,
                    'applicant_mobile' => $assignment->application->user->mobile_no ?? null,
                    'name_of_enterprise' => $assignment->application->user->name_of_enterprise ?? null,
                    'department'       => $assignment->department?->name ?? null,
                    'status'           => $assignment->application->status ?? null,
                    'current_step'     => $assignment->application->current_step_number ?? null,
                    'step_type'        => $assignment->step_type ?? null,
                    'hierarchy_level'  => $hierarchy_level,
                    'renewal' => $assignment->application->renewal === 'yes' ? 'YES' : 'NO',
                    'previous_application_id' => $assignment->application->previous_application_id,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $applications->items(),
                'pagination' => [
                    'current_page' => $applications->currentPage(),
                    'last_page'    => $applications->lastPage(),
                    'per_page'     => $applications->count(),
                    'total'        => $applications->total(),
                    'next_page_url' => $applications->nextPageUrl(),
                    'prev_page_url' => $applications->previousPageUrl(),
                ]
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while fetching assigned applications',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_list_of_NOC_issued_by_department(Request $request)
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

            $department_id   = $request->department_id;
            $per_page = $request->get('per_page', 10);

            $list_of_NOC_issued_by_department = UserServiceApplication::with(['user', 'unit', 'latestWorkflow'])
                ->where('status', 'approved')
                ->whereHas('latestWorkflow', function ($q) use ($department_id) {
                    $q->where('department_id', $department_id);
                })
                ->paginate($per_page)
                ->through(function ($application) {
                    return [
                        'application_id'   => $application->applicationId,
                        'applicant_name'   => $application->user?->authorized_person_name,
                        'application_date' => $application->application_date,
                        'name_of_unit'     => $application->unit?->unit_name,
                    ];
                });


            return response()->json([
                'status'            => 1,
                'message'           => 'Total count applications under this department fetched successfully',
                'list_of_NOC_issued_by_department' => $list_of_NOC_issued_by_department->items(),
                'pagination' => [
                    'current_page' => $list_of_NOC_issued_by_department->currentPage(),
                    'row_count'    => $list_of_NOC_issued_by_department->count(),
                    'total'        => $list_of_NOC_issued_by_department->total(),
                    'start_row'    => $list_of_NOC_issued_by_department->firstItem(),
                    'end_row'      => $list_of_NOC_issued_by_department->lastItem(),
                    'last_page'    => $list_of_NOC_issued_by_department->lastPage(),
                    'next_page_url' => $list_of_NOC_issued_by_department->nextPageUrl(),
                    'prev_page_url' => $list_of_NOC_issued_by_department->previousPageUrl(),
                ],

            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the application count',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function export_service_applications()
    {
        try {

            return Excel::download(new ServiceApplicationExport, 'service_applications.xlsx');
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate Excel file',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function add_qr_to_by_law_file($application): ?string
    {
        $application_data = json_decode($application->application_data, true);

        $certificate_url = rtrim(config('app.url'), '/') . "/storage/uploads/{$application->user->id}/application/{$application->applicationId}.pdf";
        $qr_payload = "Certificate Link: {$certificate_url}";

        // for cooperative society only
        if ((int) $application->service_id !== 2 || empty($application_data['278'])) {
            return null;
        }

        $by_law_file = $application_data['278'];

        $storage_url = Storage::url('');
        $base_url = rtrim(config('app.url'), '/') . '/storage/';
        if (str_starts_with($by_law_file, $base_url)) {
            $by_law_file = substr($by_law_file, strlen($base_url));
        } elseif (str_starts_with($by_law_file, 'http')) {
            $by_law_file = preg_replace('#^https?://[^/]+/(?:new/)?storage/#', '', $by_law_file);
        }

        if (!Storage::disk('public')->exists($by_law_file)) {
            return null;
        }

        $source_full_path = Storage::disk('public')->path($by_law_file);

        $tmp_qr_path = null;
        $normalized_path = null;

        try {
            $qr_png = QrCode::format('png')
                ->size(300)
                ->margin(4)
                ->errorCorrection('M')
                ->generate($qr_payload);

            $tmp_qr_path = storage_path('app/temp_qr_' . uniqid('', true) . '.png');
            $normalized_path = storage_path('app/temp_normalized_' . uniqid('', true) . '.pdf');

            $temp_dir = dirname($tmp_qr_path);
            if (!is_dir($temp_dir)) {
                mkdir($temp_dir, 0755, true);
            }

            file_put_contents($tmp_qr_path, $qr_png);

            $working_pdf_path = $source_full_path;
            $pdf = new Fpdi('P', 'mm');

            try {
                // Try original PDF directly first
                $page_count = $pdf->setSourceFile($working_pdf_path);
            } catch (\Throwable $fpdiException) {
                // If FPDI fails, normalize the PDF through Ghostscript
                $gs_path = $this->findGhostscriptBinary();

                if (!$gs_path) {
                    throw new \RuntimeException(
                        'Ghostscript not found. Please install Ghostscript or set GHOSTSCRIPT_BIN in .env'
                    );
                }

                $gs_bin = escapeshellarg($gs_path);

                $gs_cmd = $gs_bin
                    . ' -q'
                    . ' -dNOPAUSE'
                    . ' -dBATCH'
                    . ' -dSAFER'
                    . ' -sDEVICE=pdfwrite'
                    . ' -dCompatibilityLevel=1.4'
                    . ' -dAutoRotatePages=/None'
                    . ' -sOutputFile=' . escapeshellarg($normalized_path)
                    . ' ' . escapeshellarg($source_full_path);

                $output = [];
                $return_var = 0;
                exec($gs_cmd . ' 2>&1', $output, $return_var);

                if ($return_var !== 0 || !file_exists($normalized_path) || filesize($normalized_path) === 0) {
                    throw new \RuntimeException(
                        "Ghostscript failed (exit code {$return_var}): " . implode("\n", $output)
                    );
                }

                $working_pdf_path = $normalized_path;
                $pdf = new Fpdi('P', 'mm');
                $page_count = $pdf->setSourceFile($working_pdf_path);
            }

            $qr_size_mm = 25.0;
            $outer_margin_mm = 10.0;
            $gap_mm = 3.0;
            $inner_margin_mm = $outer_margin_mm + $gap_mm;

            for ($page_no = 1; $page_no <= $page_count; $page_no++) {
                $template_id = $pdf->importPage($page_no);
                $size = $pdf->getTemplateSize($template_id);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template_id);

                $x = $inner_margin_mm;
                $y = $size['height'] - $inner_margin_mm - $qr_size_mm;

                $pdf->Image($tmp_qr_path, $x, $y, $qr_size_mm, 0, 'PNG');
            }

            $final_content = $pdf->Output('S');
            file_put_contents($source_full_path, $final_content);

            if ($tmp_qr_path && file_exists($tmp_qr_path)) {
                @unlink($tmp_qr_path);
            }

            if ($normalized_path && file_exists($normalized_path)) {
                @unlink($normalized_path);
            }

            return $by_law_file;
        } catch (\Throwable $e) {
            if ($tmp_qr_path && file_exists($tmp_qr_path)) {
                @unlink($tmp_qr_path);
            }

            if ($normalized_path && file_exists($normalized_path)) {
                @unlink($normalized_path);
            }

            Log::error('add_qr_to_by_law_file: ' . $e->getMessage() . ' on line ' . $e->getLine());
            return null;
        }
    }

    private function findGhostscriptBinary(): ?string
    {
        foreach (['/usr/bin/gs', '/usr/local/bin/gs'] as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        $output = [];
        $code = 1;
        @exec('which gs 2>/dev/null', $output, $code);

        if ($code === 0 && !empty($output[0])) {
            $path = trim($output[0]);
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function add_qr_to_by_law(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || $user->user_type !== 'admin') {
                return response()->json(['status' => 0, 'message' => 'Unauthorized.'], 403);
            }

            $request->validate([
                'application_id' => 'required|integer|exists:user_service_applications,id',
            ]);

            $application = UserServiceApplication::with('user')->findOrFail($request->application_id);

            $result = $this->add_qr_to_by_law_file($application);

            if ($result === null) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'QR not added. Either not a cooperative society application, by-law file missing, or file key (278) not found in application data.',
                ], 422);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'QR code added to by-law file successfully.',
                'file'    => $result,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function get_user_approved_applications(Request $request)
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

            $user_id =  $user->id;

            $request->validate([
                'department_id' => 'nullable|integer|exists:departments,id',
                'service_id'    => 'nullable|integer|exists:service_masters,id',
                'step_type'      => 'nullable|string',
                'date_from'     => 'nullable|date',
                'date_to'       => 'nullable|date|after_or_equal:date_from',
                'search'        => 'nullable|string'
            ]);

            $data = UserServiceApplication::with(['service', 'user'])

                ->whereHas('workflow', function ($q) use ($user_id, $request) {

                    $q->where('status', 'approved')
                        ->where('action_taken_by', $user_id);

                    if ($request->filled('step_type')) {
                        $q->where('step_type', $request->step_type);
                    }
                })

                ->orderByDesc('id');

            if ($request->filled('department_id')) {

                $data->whereHas('service', function ($q) use ($request) {
                    $q->where('department_id', $request->department_id);
                });
            }

            if ($request->filled('service_id')) {
                $data->where('service_id', $request->service_id);
            }

            if ($request->filled('status')) {
                $data->where('status', $request->status);
            }

            if ($request->filled('district_id')) {
                $data->whereHas('user', function ($q) use ($request) {
                    $q->where('district_id', $request->district_id);
                });
            }

            if ($request->filled('subdivision_id')) {
                $data->whereHas('user', function ($q) use ($request) {
                    $q->where('subdivision_id', $request->subdivision_id);
                });
            }

            if ($request->filled('search')) {

                $search = $request->search;

                $data->where(function ($q) use ($search) {

                    $q->where('applicationId', 'like', "%{$search}%")

                        ->orWhereHas('user', function ($u) use ($search) {
                            $u->where('authorized_person_name', 'like', "%{$search}%")
                                ->orWhere('mobile_no', 'like', "%{$search}%")
                                ->orWhere('name_of_enterprise', 'like', "%{$search}%");
                        });
                });
            }

            if ($request->date_from && $request->date_to) {

                $data->whereBetween('application_date', [
                    $request->date_from,
                    $request->date_to
                ]);
            } elseif ($request->date_from) {

                $data->whereDate('application_date', '>=', $request->date_from);
            } elseif ($request->date_to) {

                $data->whereDate('application_date', '<=', $request->date_to);
            }

            $applications_data = $data->orderByDesc('id')->get();

            $applications = $applications_data->map(function ($application) {

                $workflow = $application->workflow->first();

                return [

                    'application_id'      => $application->id,
                    'application_number'  => $application->applicationId,
                    'service_name'        => $application->service->service_title_or_description ?? null,
                    'applicant_name'      => optional($application->user)->authorized_person_name,
                    'enterprise_name'     => optional($application->user)->name_of_enterprise,
                    'mobile_no'           => optional($application->user)->mobile_no,
                    'application_status'  => $application->status,
                    'submission_date'     => $application->application_date,
                    'approved_step'       => $workflow->step_number ?? null,
                    'step_type'           => $workflow->step_type ?? null,
                    'status'              => $application->status,
                    'approved_date'       => $workflow->updated_at ?? null,
                    'remarks'             => $workflow->remarks ?? null,
                    'renewal' => $application->renewal === 'yes' ? 'YES' : 'NO',
                    'previous_application_id' => $application->previous_application_id,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Applications approved by the logged-in user fetched successfully.',
                'data'    => $applications
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong while fetching applications.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_user_previous_applications(Request $request)
    {

        try {

            $request->validate([
                'user_id' => 'required|integer|exists:users,id',
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $user = User::where('id', $user->id)
                ->whereIn('user_type', ['department', 'admin'])
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or non-departmental user.'
                ], 404);
            }

            $dept_ids = DepartmentUser::where('user_id', $user->id)->pluck('department_id');

            $applications_data = UserServiceApplication::with(['service', 'user'])
                ->where('user_id', $request->user_id)
                ->whereHas('service', function ($q) use ($dept_ids) {
                    $q->whereIn('department_id', $dept_ids);
                })
                ->orderByDesc('id')
                ->get();

            $applicant = User::find($request->user_id);

            $applications = $applications_data->map(function ($application) {

                $workflow = $application->workflow
                    ->sortByDesc('id')
                    ->first();

                return [
                    'application_id'     => $application->id,
                    'application_number' => $application->applicationId,
                    'service_name'       => $application->service->service_title_or_description ?? null,
                    'applicant_name'     => optional($application->user)->authorized_person_name,
                    'enterprise_name'    => optional($application->user)->name_of_enterprise,
                    'mobile_no'          => optional($application->user)->mobile_no,
                    'application_status' => $application->status,
                    'submission_date'    => $application->application_date,
                    'approved_step'      => $workflow->step_number ?? null,
                    'step_type'          => $workflow->step_type ?? null,
                    'status'             => $application->status,
                    'approved_date'      => $workflow->updated_at ?? null,
                    'remarks'            => $workflow->remarks ?? null,
                    'renewal' => $application->renewal === 'yes' ? 'YES' : 'NO',
                    'previous_application_id' => $application->previous_application_id,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => "User's previous applications fetched successfully.",
                'user'    => [
                    'user_id'         => $applicant?->id,
                    'name'            => $applicant?->authorized_person_name,
                    'enterprise_name' => $applicant?->name_of_enterprise,
                    'mobile_no'       => $applicant?->mobile_no,
                    'email'           => $applicant?->email_id,
                    'pan'             => $applicant?->pan,
                    'address'         => $applicant?->registered_enterprise_address,
                    'city'            => $applicant?->registered_enterprise_city,
                ],
                'data'    => $applications
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong while fetching applications.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
