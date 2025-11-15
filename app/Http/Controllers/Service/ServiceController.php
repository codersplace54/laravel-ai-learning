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
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ServiceApplicationExport;

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
                    ->where('status', '!=', 'approved')
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
                    'application_id'      => $application->id,
                    'application_number'  => $application->applicationId,
                    'service_name'        => $application->service->service_title_or_description,
                    'applicant_name'      => $application->user->authorized_person_name,
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
                    'hierarchy'   => $application->user->department_user->hierarchy_level ?? null,
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

            $current_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                ->orderByDesc('id')
                ->first();

            $max_step = ServiceApprovalFlow::where('service_id', $application->service_id)
                ->max('step_number');

            $is_just_before_final_step = false;

            if ($current_step->step_number == $max_step) {
                $is_just_before_final_step = true;
            }

            $formatted_data = [];
            $application_data = json_decode($application->application_data, true);
            if (!empty($application_data)) {
                $questions = ServiceQuestionnaire::whereIn('id', array_keys($application_data))
                    ->pluck('question_label', 'id');
                foreach ($application_data as $question_id => $answer) {
                    $formatted_data[] = [
                        'id' => $question_id,
                        'question' => $questions[$question_id] ?? 'Question not found',
                        'answer'   => $answer,
                    ];
                }
            }


            $history_data = ApplicationWorkflowHistory::where('application_id', $application->id)
                ->orderByDesc('id')
                ->first();

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
                    'action_taken_by' => $history_data->action_taken_by,
                ];
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
                'application_data' => $formatted_data ?? [],
                'status'           => $application->status,
                'applied_fee'      => $application->applied_fee,
                'approved_fee'     => $application->approved_fee,
                'application_fee'     => $application->final_fee,
                'extra_payment'        => $application->extra_payment ?? 0,
                'total_fee'         => $application->total_fee ?? 0,
                'payment_status'   => $application->payment_status,
                'workflow' => $application->workflow->map(function ($flow) {
                    return [
                        'step_number'     => $flow->step_number,
                        'step_type'       => $flow->step_type,
                        'department'      => $flow->department->name,
                        'status'          => $flow->status,
                        'action_taken_by' => $flow->actionTaker?->authorized_person_name ? $flow->actionTaker->authorized_person_name . ' (' . $flow->actionTaker->email_id . ')' : null,
                        'action_taken_at' => $flow->action_taken_at,
                        'hierarchy_level'     => $flow->hierarchy_level,
                        'remarks'         => $flow->remarks,
                    ];
                }),
                'just_before_final_step'  => $is_just_before_final_step,
                'history_data'    => $history_data,
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

    public function download_application_pdf(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
        }

        try {
            $request->validate([
                'application_id'   => 'required|integer|exists:user_service_applications,id',
            ]);

            $application = UserServiceApplication::where('id', $request->application_id)->first();

            $path = $application->NOC_certificate;

            if (!$path || !Storage::disk('public')->exists($path)) {
                return response()->json(['status' => 0, 'message' => 'PDF file not found for this application.'], 404);
            }
            return response()->json([
                'status' => 1,
                'message' => 'PDF file is available.',
                'download_url' => Storage::url($path)
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching pdf.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
    public function download_user_application_pdf(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
        }

        try {
            $request->validate([
                'application_id'   => 'required|integer|exists:user_service_applications,id',
            ]);

            $application = UserServiceApplication::where('id', $request->application_id)->first();

            $path = $application->NOC_certificate;

            if (!$path || !Storage::disk('public')->exists($path)) {
                return response()->json(['status' => 0, 'message' => 'PDF file not found for this application.'], 404);
            }
            return response()->json([
                'status' => 1,
                'message' => 'PDF file is available.',
                'download_url' => Storage::url($path)
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching pdf.',
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

            $next_step = null;

            if ($request->status === 'approved') {

                $max_step = ServiceApprovalFlow::where('service_id', $application->service_id)
                    ->max('step_number');

                if ($current_step->step_number == $max_step) {
                    $application->update([
                        'status'       => 'approved',
                        'updated_at' => now(),
                    ]);

                    if ($application->service->form_template) {
                        $this->generate_dynamic_pdf($application, $user);
                    }

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
                        'hierarchy_level' => null,
                        'action_taken_by' => null,
                        'action_taken_at' => null,
                        'remarks'         => null,
                        'status'          => 'send_back',
                    ]);

                    $application->update([
                        'current_step_number' => $first_step_flow->step_number,
                        'status'              => 'send_back',
                    ]);
                }
            } elseif ($request->status === 'extra_payment') {

                $request->validate([
                    'extra_payment' => 'required|integer',
                ]);


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
                        'hierarchy_level' => null,
                        'action_taken_by' => null,
                        'action_taken_at' => null,
                        'remarks'         => null,
                        'status'          => 'extra_payment',
                    ]);

                    $total_fee = $application->final_fee +  $request->extra_payment;

                    $application->update([
                        'current_step_number' => $first_step_flow->step_number,
                        'payment_status'      => 'pending',
                        'total_fee'           =>  $total_fee,
                        'extra_payment'       => $request->extra_payment,
                        'remarks'             => $request->remarks,
                        'status'              => 'extra_payment',
                    ]);
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
                    'action_taken_by' => $entry->actionTaker ? $entry->actionTaker->authorized_person_name . ' (' . $entry->actionTaker->email_id . ')' : null,
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

    public function get_department_user_assigned_applications($user_id)
    {

        try {


            $user = User::where('id', $user_id)
                ->where('user_type', 'department')
                ->first();


            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or non-departmental user.'
                ], 404);
            }

            $hierarchy_level = $user->department_user->hierarchy_level;

            $applications = ApplicationWorkflowAssignment::with([
                'application.service:id,service_title_or_description',
                'application.user:id,authorized_person_name,email_id,mobile_no',
                'department:id,name'
            ])
                ->where('status', 'pending')
                ->get()
                ->filter(function ($assignment) use ($hierarchy_level) {
                    return $assignment->hierarchy_level == $hierarchy_level;
                })
                ->map(function ($assignment) {
                    return [
                        'application_id'   => $assignment->application->id,
                        'service_name'     => $assignment->application->service->service_title_or_description ?? null,
                        'applicant_name'   => $assignment->application->user->authorized_person_name,
                        'applicant_email'  => $assignment->application->user->email_id,
                        'applicant_mobile' => $assignment->application->user->mobile_no,
                        'department'       => $assignment->department->name,
                        'status'           => $assignment->application->status,
                        'current_step'     => $assignment->application->current_step_number,
                        'hierarchy_level'    => $assignment->hierarchy_level,
                    ];
                });

            return response()->json([
                'success' => true,
                'data'    => $applications
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while fetching assigned applications',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

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

            $percentage_pending_application = ($total_count_pending_application_in_department / $total_applications_for_this_department) * 100;

            $total_count_approved_application_in_department = ApplicationWorkflowAssignment::query()

                ->where('hierarchy_level', $hierarchy_level)
                ->where('department_id', $request->department_id)
                ->distinct('application_id')
                ->count('application_id');


            $percentage_approved_application = ($total_count_approved_application_in_department / $total_applications_for_this_department) * 100;

            $total_count_rejected_application_in_department = UserServiceApplication::where('status', 'rejected')->count();

            $percentage_rejected_application = ($total_count_rejected_application_in_department / $total_applications_for_this_department) * 100;


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

            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the application count',
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
                    'row_count'    => $list_of_NOC_issued_by_department->perPage(),
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


    public function preview_certificate($application_id)
    {

        try {


            $application = UserServiceApplication::with('service')->findOrFail($application_id);

            if (!$application->service || !$application->service->form_template) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Certificate template not found.'
                ]);
            }

            $user = Auth::user();

            $template = (string) data_get($application, 'service.form_template', '');
            $template = html_entity_decode($template, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $template = str_replace("\xC2\xA0", ' ', $template);

            $name = $application->user->authorized_person_name ?? $user->name ?? '—';
            $verifyUrl = 'https://swaagat.tripura.gov.in/verify';

            $qrPayload = "Name: {$name}\nApplication Id: {$application->id}\n{$verifyUrl}";
            $qrSvg = QrCode::format('svg')->size(220)->margin(0)->generate($qrPayload);
            $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

            $data = [
                'form_title'        => 'FORM VI',
                'rules_ref'         => '[ Under rule 19(1) of the Tripura Contract Labour (Regulation and Abolition) Rules, 1978; ]',
                'government'        => 'Government of Tripura',
                'issuing_office'    => 'Office of the Licensing Officer',
                'verify_portal_url' => 'https://swaagat.tripura.gov.in',

                'license_id'          => $application->id ?? '—',
                'issue_date'          => $application->application_date ? Carbon::parse($application->application_date)->format('d-m-Y') : '—',
                'principal_employer'  => $application->user->authorized_person_name ?? '—',
                'guardian_name'       => $application->user->management_details->owner_details_father_name ?? '—',
                'address'             => $application->user->management_details->owner_details_residential_details ?? '—',
                'work_location'       => $application->work_location ?? 'Tripura',
                'registration_no'     => $application->id ?? '—',
                'registration_date'   => $application->application_date ? Carbon::parse($application->application_date)->format('d-m-Y') : '—',
                'valid_upto'          => $application->NOC_expiry_date ? Carbon::parse($application->NOC_expiry_date)->format('d-m-Y') : '—',
                'max_contract_labour' => (string) ($application->max_contract_labour ?? 0),
                'fee_paid'            => (string) ($application->final_fee ?? 0),
                'security_deposit'    => (string) ($application->security_deposit ?? ''),
                'designation'         => $application->service->department->department_user->designation ?? '',
                'signature_note'      => 'Not Required',
                'user_name'           => $user->authorized_person_name ?? '',
                'user_id'             => (string) $user->id,
                'qr_code'             => $qrDataUri,
            ];

            $filled = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($m) use ($data) {
                $key = $m[1];
                return e($data[$key] ?? '');
            }, $template);

            if (stripos($filled, '<html') === false) {
                $filled = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $filled . '</body></html>';
            }

            $pdf = Pdf::loadHTML($filled)->setPaper('a4', 'portrait');

            $temp_file_name = 'preview_' . uniqid() . '.pdf';
            $temp_path = storage_path('app/public/temp/' . $temp_file_name);
            Storage::disk('public')->put('temp/' . $temp_file_name, $pdf->output());

            $previewUrl = asset('storage/temp/' . $temp_file_name);

            return response()->json([
                'status' => 1,
                'message' => 'Preview generated successfully.',
                'pdf_url' => $previewUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while generating preview.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function generate_dynamic_pdf(UserServiceApplication $application, User $user): void
    {

        $template = (string) data_get($application, 'service.form_template', '');
        if ($template === '') abort(422, 'No form template configured for this service.');

        $template = html_entity_decode($template, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $template = str_replace("\xC2\xA0", ' ', $template);

        $name     = $application->user->authorized_person_name ?? $user->name ?? '—';
        $verifyUrl = trim('https://swaagat.tripura.gov.in/verify');

        $qrPayload = "Name: {$name}\nApplication Id: {$application->id}\n{$verifyUrl}";
        $qrSvg     = QrCode::format('svg')->size(220)->margin(0)->generate($qrPayload);
        $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

        $data = [
            'form_title'        => 'FORM VI',
            'rules_ref'         => '[ Under rule 19(1) of the Tripura Contract Labour (Regulation and Abolition) Rules, 1978; ]',
            'government'        => 'Government of Tripura',
            'issuing_office'    => 'Office of the Licensing Officer',
            'verify_portal_url' => 'https://swaagat.tripura.gov.in',

            'license_id'          => $application->id ?? '—',
            'issue_date'          => $application->application_date ? Carbon::parse($application->application_date)->format('d-m-Y') : '—',
            'principal_employer'  => $application->user->authorized_person_name ?? '—',
            'guardian_name'       => $application->user->management_details->owner_details_father_name ?? '—',
            'address'             => $application->user->management_details->owner_details_residential_details ?? '—',
            'work_location'       => $application->work_location ?? 'Tripura',
            'registration_no'     => $application->id ?? '—',
            'registration_date'   => $application->application_date ? Carbon::parse($application->application_date)->format('d-m-Y') : '—',
            'valid_upto'          => $application->NOC_expiry_date ? Carbon::parse($application->NOC_expiry_date)->format('d-m-Y') : '—',
            'max_contract_labour' => (string) ($application->max_contract_labour ?? 0),
            'fee_paid'            => (string) ($application->final_fee ?? 0),
            'security_deposit'    => (string) ($application->security_deposit ?? ''),
            'designation'         => $application->service->department->department_user->designation ?? '',
            'signature_note'      => 'Not Required',
            'user_name'           => $user->authorized_person_name ?? '',
            'user_id'             => (string) $user->id,
            'qr_code'            => $qrDataUri,
        ];

        $filled = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($m) use ($data) {
            $key = $m[1];
            $val = $data[$key] ?? '';
            return e(is_scalar($val) ? (string) $val : '');
        }, $template);

        if (stripos($filled, '<html') === false) {
            $filled = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $filled . '</body></html>';
        }

        $pdf = Pdf::loadHTML($filled)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => true,
                'defaultFont'          => 'DejaVu Sans',
                'dpi'                  => 110,
            ]);


        $filename = uniqid('license_') . '.pdf';
        $path     = "uploads/{$user->id}/application/{$filename}";

        Storage::disk('public')->put($path, $pdf->output());
        $application->update(['NOC_certificate' => $path]);
    }

}
