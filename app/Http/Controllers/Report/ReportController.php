<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserServiceApplication;
use App\Models\ServiceMaster;
use App\Models\Department;
use App\Models\ApplicationWorkflowHistory;
use App\Models\AclRule;
use Carbon\Carbon;
use App\Models\DepartmentUser;
use App\Models\ServiceApprovalFlow;

class ReportController extends Controller
{
    public function user_list(Request $request)
    {
        try {
            $request->validate([
                'from_date'     => 'nullable|date',
                'to_date'       => 'nullable|date',
                'department_id' => 'nullable|integer|exists:departments,id',
                'service_id'    => 'nullable|integer|exists:service_masters,id',
            ]);

            $from_date     = $request->from_date;
            $to_date       = $request->to_date;
            $department_id = $request->department_id;
            $service_id    = $request->service_id;

            $query = UserServiceApplication::with([
                'user:id,name_of_enterprise,authorized_person_name,mobile_no,email_id,registered_enterprise_address',
                'service:id,department_id,service_title_or_description,target_days',
                'workflow' => function ($q) {
                    $q->orderBy('action_taken_at', 'asc');
                },
            ]);

            if (!empty($service_id)) {
                $query->where('service_id', $service_id);
            }

            if (!empty($department_id)) {
                $query->whereHas('service', function ($q) use ($department_id) {
                    $q->where('department_id', $department_id);
                });
            }

            if (!empty($from_date)) {
                $query->whereDate('application_date', '>=', $from_date);
            }

            if (!empty($to_date)) {
                $query->whereDate('application_date', '<=', $to_date);
            }

            $applications = $query
                ->orderBy('application_date', 'asc')
                ->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $rows = $applications->map(function ($application) {
                $user     = $application->user;
                $service  = $application->service;
                $workflow = $application->workflow ?? collect();

                $application_date = $application->application_date
                    ? Carbon::parse($application->application_date)
                    : null;

                $first_send_back_step = $workflow->where('status', 'send_back')
                    ->sortBy('action_taken_at')
                    ->first();

                $query_within_7_days = '';
                $query_after_7_days  = '';

                if ($application_date && $first_send_back_step && $first_send_back_step->action_taken_at) {
                    $send_back_date = Carbon::parse($first_send_back_step->action_taken_at);
                    $days_gap       = $application_date->diffInDays($send_back_date);

                    if ($days_gap <= 7) {
                        $query_within_7_days = 'Yes';
                    } else {
                        $query_after_7_days = 'Yes';
                    }
                }

                $noc_date = null;

                if (!empty($application->NOC_generationDate)) {
                    $noc_date = Carbon::parse($application->NOC_generationDate);
                } else {
                    $approved_step = $workflow->where('status', 'approved')
                        ->sortByDesc('action_taken_at')
                        ->first();

                    if ($approved_step && $approved_step->action_taken_at) {
                        $noc_date = Carbon::parse($approved_step->action_taken_at);
                    }
                }

                return [
                    'enterprise_name'        => $user ? $user->name_of_enterprise : null,
                    'person_name'            => $user ? $user->authorized_person_name : null,
                    'contact_no'             => $user ? $user->mobile_no : null,
                    'email'                  => $user ? $user->email_id : null,
                    'address'                => $user ? $user->registered_enterprise_address : null,
                    'date_of_application'    => $application_date ? $application_date->format('Y-m-d') : null,
                    'query_within_7_days'    => $query_within_7_days,
                    'query_after_7_days'     => $query_after_7_days,
                    'date_of_receipt_of_noc' => $noc_date ? $noc_date->format('Y-m-d') : null,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'User list report.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function department_user_list(Request $request)
    {
        try {
            $request->validate([
                'department_id' => 'nullable|integer|exists:departments,id',
                'service_id'    => 'nullable|integer|exists:service_masters,id',
                'approval_step' => 'nullable|string',
            ]);

            $department_id = $request->department_id;
            $service_id    = $request->service_id;
            $approval_step = $request->approval_step;

            $department_user_query = DepartmentUser::with([
                'department:id,name',
                'user:id,user_name,authorized_person_name,name_of_enterprise,email_id,mobile_no',
            ])
                ->where('is_active', 1);

            if (!empty($department_id)) {
                $department_user_query->where('department_id', $department_id);
            }

            $flows = collect();

            if (!empty($department_id) || !empty($service_id) || !empty($approval_step)) {
                $flow_query = ServiceApprovalFlow::query();

                if (!empty($department_id)) {
                    $flow_query->where('department_id', $department_id);
                }

                if (!empty($service_id)) {
                    $flow_query->where('service_id', $service_id);
                }

                if (!empty($approval_step)) {
                    $flow_query->where('step_type', $approval_step);
                }

                $flows = $flow_query->get();

                if ($flows->isEmpty()) {
                    return response()->json([
                        'status'  => 1,
                        'message' => 'No record found.',
                        'data'    => [],
                    ]);
                }

                $hierarchy_levels    = $flows->pluck('hierarchy_level')->filter()->unique()->values();
                $flow_department_ids = $flows->pluck('department_id')->filter()->unique()->values();

                if (empty($department_id) && $flow_department_ids->isNotEmpty()) {
                    $department_user_query->whereIn('department_id', $flow_department_ids);
                }

                if ($hierarchy_levels->isNotEmpty()) {
                    $department_user_query->whereIn('hierarchy_level', $hierarchy_levels->all());
                }
            }

            $department_users = $department_user_query
                ->orderBy('id', 'asc')
                ->get();

            if ($department_users->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $service_label = null;

            if (!empty($service_id)) {
                $service = ServiceMaster::select('id', 'service_title_or_description')
                    ->find($service_id);

                if ($service) {
                    $service_label = $service->service_title_or_description;
                }
            }

            $rows = $department_users->map(function ($department_user) use ($service_label) {
                $department = $department_user->department;
                $user       = $department_user->user;

                $name = null;
                if ($user) {
                    $name = $user->authorized_person_name ?: $user->name_of_enterprise;
                }

                return [
                    'department_name'    => $department ? $department->name : null,
                    'role'               => $service_label,
                    'user_name'          => $user ? $user->user_name : null,
                    'name'               => $name,
                    'designation'        => $department_user->designation,
                    'email_id'           => $user ? $user->email_id : null,
                    'mobile_number'      => $user ? $user->mobile_no : null,
                    'date_of_assignment' => $department_user->created_at
                        ? $department_user->created_at->format('Y-m-d')
                        : null,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Department user list.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function approval_step_list(Request $request)
    {
        try {
            $request->validate([
                'department_id' => 'nullable|integer|exists:departments,id',
                'service_id'    => 'nullable|integer|exists:service_masters,id',
            ]);

            $department_id = $request->department_id;
            $service_id    = $request->service_id;

            $query = ServiceApprovalFlow::query();

            if (!empty($department_id)) {
                $query->where('department_id', $department_id);
            }

            if (!empty($service_id)) {
                $query->where('service_id', $service_id);
            }

            $step_types = $query
                ->orderBy('step_number')
                ->pluck('step_type')
                ->filter()
                ->unique()
                ->values();

            if ($step_types->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Approval step list.',
                'data'    => $step_types,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function application_status(Request $request)
    {
        try {
            $request->validate([
                'from_date'          => 'required|date',
                'to_date'            => 'required|date|after_or_equal:from_date',
                'department_id'      => 'nullable|integer',
                'application_status' => 'nullable|string', 
            ]);

            $from_date          = $request->from_date;
            $to_date            = $request->to_date;
            $department_id      = $request->department_id;
            $application_status = $request->application_status;

            $query = UserServiceApplication::with([
                'user:id,name_of_enterprise,authorized_person_name,mobile_no,email_id,registered_enterprise_address',
                'service:id,department_id,service_title_or_description,target_days',
                'service.department:id,name',
                'workflow' => function ($q) {
                    $q->orderBy('action_taken_at', 'asc');
                },
            ]);

            $query->whereDate('application_date', '>=', $from_date)
                ->whereDate('application_date', '<=', $to_date);

            if (!empty($department_id)) {
                $query->whereHas('service', function ($q) use ($department_id) {
                    $q->where('department_id', $department_id);
                });
            }

            if (!empty($application_status)) {
                $query->where('status', $application_status);
            }

            $applications = $query
                ->orderBy('application_date', 'asc')
                ->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $rows = $applications->map(function ($application) {
                $user       = $application->user;
                $service    = $application->service;
                $department = $service ? $service->department : null;
                $workflow   = $application->workflow ?? collect();

                $approved_step = $workflow->where('status', 'approved')
                    ->sortByDesc('action_taken_at')
                    ->first();


                $rejected_step = $workflow->where('status', 'rejected')
                    ->sortByDesc('action_taken_at')
                    ->first();

                $first_send_back_step = $workflow->where('status', 'send_back')
                    ->sortBy('action_taken_at')
                    ->first();

                $application_date = $application->application_date
                    ? Carbon::parse($application->application_date)
                    : null;

                $due_date = null;
                if ($application_date && $service && $service->target_days !== null) {
                    $due_date = $application_date->copy()->addDays((int) $service->target_days);
                }

                $decision_status = $application->status;
                $decision_date   = null;

                if ($approved_step) {
                    $decision_status = 'approved';
                    $decision_date   = $approved_step->action_taken_at
                        ? Carbon::parse($approved_step->action_taken_at)
                        : null;
                } elseif ($rejected_step) {
                    $decision_status = 'rejected';
                    $decision_date   = $rejected_step->action_taken_at
                        ? Carbon::parse($rejected_step->action_taken_at)
                        : null;
                }

                
                $total_days_delayed = null;

                if ($due_date && $decision_date) {
                    if ($decision_date->lessThanOrEqualTo($due_date)) {
                        $total_days_delayed = 0;
                    } else {
                        $total_days_delayed = (int) $due_date->diffInDays($decision_date);
                    }
                }


                $clarification_delay_days = null;

                if ($first_send_back_step) {
                    $send_back_date = $first_send_back_step->action_taken_at
                        ? Carbon::parse($first_send_back_step->action_taken_at)
                        : null;

                    if ($send_back_date) {
                        
                        $resubmission_step = $workflow
                            ->filter(function ($step) use ($first_send_back_step) {
                                if (!$step->action_taken_at) {
                                    return false;
                                }

                                
                                if ($step->id === $first_send_back_step->id) {
                                    return false;
                                }

                                return $step->action_taken_at > $first_send_back_step->action_taken_at
                                    && $step->status !== 'send_back';
                            })
                            ->sortBy('action_taken_at')
                            ->first();

                        if ($resubmission_step && $resubmission_step->action_taken_at) {
                            $resubmission_date = Carbon::parse($resubmission_step->action_taken_at);

                            $clarification_delay_days = $resubmission_date->diffInDays($send_back_date);
                        }
                    }
                }


                return [
                    'department_name'          => $department ? $department->name : null,
                    'application_no'           => $application->applicationId ?? $application->id,
                    'application_date'         => $application_date ? $application_date->format('Y-m-d') : null,
                    'application_for'          => $service ? $service->service_title_or_description : null,
                    'organization'             => $user
                        ? ($user->name_of_enterprise ?: $user->authorized_person_name)
                        : null,
                    'registered_address'       => $user ? $user->registered_enterprise_address : null,
                    'mobile_no'                => $user ? $user->mobile_no : null,
                    'email_id'                 => $user ? $user->email_id : null,

                    'due_date'                 => $due_date ? $due_date->format('Y-m-d') : null,

                    'approved_on'              => $approved_step && $approved_step->action_taken_at
                        ? Carbon::parse($approved_step->action_taken_at)->format('Y-m-d')
                        : null,
                    'rejected_on'              => $rejected_step && $rejected_step->action_taken_at
                        ? Carbon::parse($rejected_step->action_taken_at)->format('Y-m-d')
                        : null,
                    'send_back_on'             => $first_send_back_step && $first_send_back_step->action_taken_at
                        ? Carbon::parse($first_send_back_step->action_taken_at)->format('Y-m-d')
                        : null,

                    'current_status'           => $decision_status, 
                    'total_days_delayed'       => $total_days_delayed,
                    'clarification_delay_days' => $clarification_delay_days,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Application status report.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function online_single_windows(Request $request)
    {


        try {

            $request->validate([
                'department_id' => 'nullable|integer|exists:departments,id',
                'service_id' => 'nullable|integer|exists:service_masters,id',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
            ]);

            $query = UserServiceApplication::query()
                ->select(
                    'service_id',
                    DB::raw('COUNT(*) as total_received'),
                    DB::raw("SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as total_processed"),
                    DB::raw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as total_approved"),
                    DB::raw('AVG(CASE WHEN status = "approved" THEN approved_fee END) as avg_fee')
                )
                ->whereNotNull('application_date')
                ->groupBy('service_id');

            if ($request->filled('from_date') && $request->filled('to_date')) {
                $query->whereBetween('application_date', [$request->from_date, $request->to_date]);
            }

            if ($request->filled('service_id')) {
                $query->where('service_id', $request->service_id);
            }

            if ($request->filled('department_id')) {
                $query->whereIn('service_id', function ($sub) use ($request) {
                    $sub->select('id')
                        ->from('service_masters')
                        ->where('department_id', $request->department_id);
                });
            }

            $applications = $query->get();

            $report = $applications->map(function ($row) {
                $service = ServiceMaster::find($row->service_id);

                $approvedApps = UserServiceApplication::where('service_id', $row->service_id)
                    ->where('status', 'approved')
                    ->whereNotNull('NOC_generationDate')
                    ->whereNotNull('application_date')
                    ->get(['application_date', 'NOC_generationDate']);

                $durations = $approvedApps->map(function ($a) {
                    return $a->NOC_generationDate
                        ? (strtotime($a->NOC_generationDate) - strtotime($a->application_date)) / 86400
                        : null;
                })->filter();

                $max_time = $durations->max() ?? 0;
                $min_time = $durations->min() ?? 0;
                $avg_time = $durations->avg() ?? 0;

                $count = $durations->count();
                $median_time = 0;
                if ($count > 0) {
                    $sorted = $durations->sort()->values();
                    $middle = floor(($count - 1) / 2);
                    $median_time = $count % 2
                        ? $sorted[$middle]
                        : ($sorted[$middle] + $sorted[$middle + 1]) / 2;
                }

                return [
                    'department_name' => $service->department->name ?? null,
                    'noc_description' => $service->service_title_or_description,
                    'time_limit' => $service->target_days,
                    'total_received' => (int) $row->total_received,
                    'total_processed' => (int) $row->total_processed,
                    'total_approved' => (int) $row->total_approved,
                    'max_time_to_approve' => round($max_time, 2),
                    'min_time_to_approve' => round($min_time, 2),
                    'avg_time_to_approve' => round($avg_time, 2),
                    'median_time_to_approve' => round($median_time, 2),
                    'avg_fee' => round($row->avg_fee, 2),
                ];
            });

            return response()->json([
                'status' => 1,
                'message' => 'Report generated successfully.',
                'data' => $report
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to generate report.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
