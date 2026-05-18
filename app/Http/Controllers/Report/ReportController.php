<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserServiceApplication;
use App\Models\ServiceMaster;
use Carbon\Carbon;
use App\Models\DepartmentUser;
use App\Models\ServiceApprovalFlow;
use App\Models\UnitDetail;
use App\Models\Activity;
use App\Models\User;
use App\Models\ManagementDetails;
use App\Models\Inspection;
use App\Models\TripuraMasterData;
use App\Models\Department;
use App\Models\UserFeedback;
use App\Models\SingleWindowReport;
use App\Models\PaymentOrder;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PaymentReportExport;

class ReportController extends Controller
{
    public function application_status(Request $request)
    {
        try {
            $request->validate([
                'from_date'          => 'required|date',
                'to_date'            => 'required|date|after_or_equal:from_date',
                'department_id'      => 'nullable|integer',
                'application_status' => 'nullable|string',
                'per_page'           => 'nullable|integer',
                'page'               => 'nullable|integer',
            ]);

            $from_date          = $request->from_date;
            $to_date            = $request->to_date;
            $department_id      = $request->department_id;
            $application_status = $request->application_status;
            $per_page           = $request->per_page ?? 15;
            $page               = $request->page ?? 1;

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

            $total = $query->count();
            $applications = $query->orderBy('application_date', 'asc')
                ->skip(($page - 1) * $per_page)
                ->take($per_page)
                ->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                    'pagination' => [
                        'total'        => 0,
                        'per_page'     => $per_page,
                        'current_page' => $page,
                        'last_page'    => 0,
                    ],
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

                $due_date = $application->max_processing_date
                    ? Carbon::parse($application->max_processing_date)
                    : ($application_date && $service && $service->target_days !== null
                        ? $application_date->copy()->addDays((int) $service->target_days)
                        : null);

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

                    'due_date'                 => $application->max_processing_date
                        ? Carbon::parse($application->max_processing_date)->format('Y-m-d')
                        : ($due_date ? $due_date->format('Y-m-d') : null),

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
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $per_page,
                    'current_page' => $page,
                    'last_page'    => ceil($total / $per_page),
                ],
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

            $hierarchy_levels = collect();

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
                ->whereHas('user')
                ->whereIn('id', function ($q) {
                    $q->selectRaw('MAX(id)')
                        ->from('department_users')
                        ->where('is_active', 1)
                        ->groupBy('user_id');
                })
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

    public function industry_report_summary(Request $request)
    {
        try {
            $request->validate([
                'from_date'    => 'nullable|date',
                'to_date'      => 'nullable|date|after_or_equal:from_date',
                'district'     => 'nullable|integer',
                'sub_division' => 'nullable|integer',
            ]);

            $from_date       = $request->from_date;
            $to_date         = $request->to_date;
            $district_id     = $request->district;
            $sub_division_id = $request->sub_division;

            $application_query = UserServiceApplication::query();

            if (!empty($from_date)) {
                $application_query->whereDate('application_date', '>=', $from_date);
            }

            if (!empty($to_date)) {
                $application_query->whereDate('application_date', '<=', $to_date);
            }

            $user_query = User::whereIn('id', function ($q) {
                $q->select('user_id')
                    ->from('user_service_applications')
                    ->whereNotNull('user_id');
            });

            if (!empty($district_id)) {
                $user_query->where('district_code', $district_id);
            }

            if (!empty($sub_division_id)) {
                $user_query->where('sub_lgd_code', $sub_division_id);
            }

            $user_ids = $user_query->pluck('id')->unique()->values();
            if ($user_ids->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $units = UnitDetail::whereIn('user_id', $user_ids)->get()->keyBy('user_id');

            $activities = Activity::whereIn('user_id', $units->pluck('user_id'))
                ->get()
                ->keyBy('user_id');

            $district_ids     = $units->pluck('unit_location_district')->filter()->unique()->values();
            $sub_division_ids = $units->pluck('unit_location_subdivision')->filter()->unique()->values();

            $master_rows = TripuraMasterData::select(
                'district_code',
                'district_name',
                'sub_lgd_code',
                'sub_division'
            )
                ->whereIn('district_code', $district_ids->all())
                ->get();

            $district_names    = [];
            $subdivision_names = [];

            foreach ($master_rows as $row) {
                $d_code = (int) $row->district_code;
                $s_code = (int) $row->sub_lgd_code;

                if ($d_code && !empty($row->district_name)) {
                    if (!isset($district_names[$d_code])) {
                        $district_names[$d_code] = $row->district_name;
                    }
                }

                if ($d_code && $s_code && !empty($row->sub_division)) {
                    if (!isset($subdivision_names[$d_code])) {
                        $subdivision_names[$d_code] = [];
                    }
                    $subdivision_names[$d_code][$s_code] = $row->sub_division;
                }
            }

            $summary = [];

            foreach ($units->pluck('user_id') as $user_id) {
                $unit = $units->get($user_id);
                if (!$unit) {
                    continue;
                }

                $dist_id = $unit->unit_location_district;
                $subd_id = $unit->unit_location_subdivision;

                if (empty($dist_id) || empty($subd_id)) {
                    continue;
                }

                $activity = $activities->get($user_id);
                if (!$activity) {
                    continue;
                }

                $type = strtolower(trim((string) $activity->activity_of_enterprise));
                if ($type === '') {
                    continue;
                }

                if (!isset($summary[$dist_id])) {
                    $summary[$dist_id] = [];
                }

                if (!isset($summary[$dist_id][$subd_id])) {
                    $district_name     = $district_names[$dist_id] ?? (string) $dist_id;
                    $sub_division_name = $subdivision_names[$dist_id][$subd_id] ?? (string) $subd_id;

                    $summary[$dist_id][$subd_id] = [
                        'district_id'         => (int) $dist_id,
                        'district_name'       => $district_name,
                        'sub_division_id'     => (int) $subd_id,
                        'sub_division_name'   => $sub_division_name,
                        'manufacturing_count' => 0,
                        'services_count'      => 0,
                    ];
                }

                if (str_contains($type, 'manufact')) {
                    $summary[$dist_id][$subd_id]['manufacturing_count']++;
                } elseif (str_contains($type, 'service')) {
                    $summary[$dist_id][$subd_id]['services_count']++;
                }
            }

            if (empty($summary)) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $rows = [];
            foreach ($summary as $district_rows) {
                foreach ($district_rows as $row) {
                    $rows[] = $row;
                }
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Industry summary report.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function industry_report_details(Request $request)
    {
        try {
            $request->validate([
                'from_date'      => 'required|date',
                'to_date'        => 'required|date|after_or_equal:from_date',
                'department_id'  => 'nullable|integer',
                'district'       => 'nullable|string',
                'sub_division'   => 'nullable|string',
            ]);

            $from_date     = $request->from_date;
            $to_date       = $request->to_date;
            $department_id = $request->department_id;
            $district      = $request->district;
            $sub_division  = $request->sub_division;

            $application_query = UserServiceApplication::query()
                ->whereDate('application_date', '>=', $from_date)
                ->whereDate('application_date', '<=', $to_date);

            if (!empty($department_id)) {
                $application_query->whereHas('service', function ($q) use ($department_id) {
                    $q->where('department_id', $department_id);
                });
            }

            $applications_by_user = $application_query->get()->groupBy('user_id');

            if ($applications_by_user->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $user_ids = $applications_by_user->keys();

            $users = User::whereIn('id', $user_ids)
                ->with(['district:district_code,district_name', 'subdivision:sub_lgd_code,sub_division'])
                ->get()
                ->keyBy('id');

            $units = UnitDetail::whereIn('user_id', $user_ids)
                ->get()
                ->keyBy('user_id');

            $activities = Activity::whereIn('user_id', $user_ids)
                ->get()
                ->keyBy('user_id');

            $managements = ManagementDetails::whereIn('user_id', $user_ids)
                ->get()
                ->keyBy('user_id');

            $rows = [];

            foreach ($user_ids as $user_id) {
                $user = $users->get($user_id);
                $unit = $units->get($user_id);

                if (!$user || !$unit) {
                    continue;
                }

                $dist = $user->district ? $user->district->district_name : 'N/A';
                $subd = $user->subdivision ? $user->subdivision->sub_division : 'N/A';

                if (!empty($district) && $district !== $dist) {
                    continue;
                }

                if (!empty($sub_division) && $sub_division !== $subd) {
                    continue;
                }

                $user_apps = $applications_by_user->get($user_id) ?? collect();
                $services_availed_count = $user_apps
                    ->pluck('service_id')
                    ->filter()
                    ->unique()
                    ->count();

                $activity = $activities->get($user_id);
                $activity_text = $activity ? (string) $activity->activity_of_enterprise : null;

                $management = $managements->get($user_id);
                $women_entrepreneur = null;
                if ($management) {
                    $flag = strtolower((string) $management->owner_details_is_women_entrepreneur);
                    if ($flag === '1' || $flag === 'yes' || $flag === 'true') {
                        $women_entrepreneur = 'Yes';
                    } elseif ($flag !== '') {
                        $women_entrepreneur = 'No';
                    }
                }

                $rows[] = [
                    'district'                       => $dist,
                    'sub_division'                   => $subd,
                    'unique_id'                      => $user->bin ?? $user->id,
                    'enterprise_name'                => $user->name_of_enterprise,
                    'mobile_no'                      => $user->mobile_no,
                    'registration_date'              => $user->created_at
                        ? $user->created_at->format('Y-m-d')
                        : null,
                    'services_availed_count'         => $services_availed_count,
                    'activity'                       => $activity_text,
                    'product_manufacturing_process'  => $unit->product_manufacturing_process ?? null,
                    'category'                       => $unit->category_of_enterprise ?? null,
                    'women_entrepreneur'            => $women_entrepreneur,
                    'nic_2_digit'                    => $activity->nic_2_digit_code ?? null,
                    'nic_4_digit'                    => $activity->nic_4_digit_code ?? null,
                    'nic_5_digit'                    => $activity->nic_5_digit_code ?? null,
                    'investment'                     => $unit && $unit->investment_details_total_project_cost ? (int) $unit->investment_details_total_project_cost : null,
                    'employment'                     => $unit->employment_details_total_employment ?? null,
                    'turnover'                       => $unit && $unit->annual_turnover ? (int) $unit->annual_turnover : null,
                    'land_type'                      => $unit->unit_location_land_type ?? null,
                    'industrial_area_name'           => $unit->unit_location_estate_name ?? null,
                ];
            }

            if (empty($rows)) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Industry details report.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function departmental_approvals(Request $request)
    {
        try {
            $request->validate([
                'from_date' => 'nullable|date',
                'to_date'   => 'nullable|date|after_or_equal:from_date',
            ]);

            $from_date = $request->from_date;
            $to_date   = $request->to_date;

            $application_query = UserServiceApplication::with([
                'service:id,department_id,target_days',
                'service.department:id,name',
                'workflow' => function ($q) {
                    $q->orderBy('action_taken_at', 'asc');
                },
            ])
                ->whereHas('service', function ($q) {
                    $q->whereNotNull('department_id');
                })
                ->whereNotIn('status', ['expired', 'draft', 'saved']);

            if (!empty($from_date)) {
                $application_query->whereDate('application_date', '>=', $from_date);
            }

            if (!empty($to_date)) {
                $application_query->whereDate('application_date', '<=', $to_date);
            }

            $applications = $application_query->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $today   = Carbon::today();
            $summary = [];

            foreach ($applications as $application) {
                $service    = $application->service;
                $department = $service ? $service->department : null;

                if (!$service || !$department) {
                    continue;
                }

                $department_id   = $department->id;
                $department_name = $department->name;

                if (!isset($summary[$department_id])) {
                    $summary[$department_id] = [
                        'department_id'               => $department_id,
                        'department_name'             => $department_name,
                        'received'                    => 0,
                        'processed'                   => 0,
                        'approved'                    => 0,
                        'submitted_within_timelines'  => 0,
                        'submitted_timelines_lapsed'  => 0,
                        'clarification_stage'         => 0,
                        'rejected'                    => 0,
                        'other_status'                => 0,
                        'queried_within_7_days'       => 0,
                        'queried_after_7_days'        => 0,
                    ];
                }

                $department_row = &$summary[$department_id];

                $department_row['received']++;

                $app_status = strtolower(trim((string) $application->status));
                $workflow_steps = $application->workflow ?? collect();

                if ($workflow_steps->isNotEmpty()) {
                    $department_row['processed']++;
                }

                if ($app_status === 'noc_issued') {
                    $department_row['approved']++;
                } elseif ($app_status === 'rejected') {
                    $department_row['rejected']++;
                } elseif ($app_status === 'send_back') {
                    $department_row['clarification_stage']++;
                } elseif (!in_array($app_status, ['pending', 'in_progress', 'saved', 'under_review'])) {
                    $department_row['other_status']++;
                }

                $application_date = $application->application_date
                    ? Carbon::parse($application->application_date)
                    : null;

                $target_days = $service->target_days ? (int) $service->target_days : null;
                $due_date    = null;

                if ($application_date && $target_days !== null) {
                    $due_date = $application_date->copy()->addDays($target_days);
                }

                $has_final_decision = in_array($app_status, ['approved', 'rejected']);
                $is_in_clarification = $app_status === 'send_back';
                $is_other_status = !in_array($app_status, ['pending', 'in_progress', 'approved', 'rejected', 'send_back', 'saved', 'under_review']);

                if ($due_date && !$has_final_decision && !$is_in_clarification && !$is_other_status) {
                    if ($today->lessThanOrEqualTo($due_date)) {
                        $department_row['submitted_within_timelines']++;
                    } else {
                        $department_row['submitted_timelines_lapsed']++;
                    }
                }

                $first_send_back_step = $workflow_steps->where('status', 'send_back')
                    ->sortBy('action_taken_at')
                    ->first();

                if ($application_date && $first_send_back_step && $first_send_back_step->action_taken_at) {
                    $send_back_date = Carbon::parse($first_send_back_step->action_taken_at);
                    $days_gap       = $application_date->diffInDays($send_back_date);

                    if ($days_gap <= 7) {
                        $department_row['queried_within_7_days']++;
                    } else {
                        $department_row['queried_after_7_days']++;
                    }
                }

                unset($department_row);
            }

            $rows = array_values($summary);

            return response()->json([
                'status'  => 1,
                'message' => 'Departmental approvals report.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function cis_summary_report(Request $request)
    {
        try {
            $request->validate([
                'year'  => 'nullable|integer',
                'month' => 'nullable|integer|min:1|max:12',
            ]);

            $year  = $request->year;
            $month = $request->month;

            $inspection_query = Inspection::with(['department:id,name']);

            if (!empty($year)) {
                $inspection_query->whereYear('inspection_date', $year);
            }

            if (!empty($month)) {
                $inspection_query->whereMonth('inspection_date', $month);
            }

            $inspections = $inspection_query->get();

            if ($inspections->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $summary = [];

            foreach ($inspections as $inspection) {
                $department = $inspection->department;
                if (!$department) {
                    continue;
                }

                $department_id   = $department->id;
                $department_name = $department->name;

                if (!isset($summary[$department_id])) {
                    $summary[$department_id] = [
                        'department_id'                            => $department_id,
                        'department_name'                          => $department_name,
                        'scheduled_inspections'                    => 0,
                        'inspections_conducted'                    => 0,
                        'pending_inspections'                      => 0,
                        'self_certification_exempt_companies'      => 0,
                        'third_party_cert_exempt_companies'        => 0,
                        'reports_uploaded_within_48_hrs'           => 0,
                        'reports_uploaded_beyond_48_hrs'           => 0,
                    ];
                }

                $row = &$summary[$department_id];

                $row['scheduled_inspections']++;

                $inspection_date = $inspection->inspection_date
                    ? Carbon::parse($inspection->inspection_date)
                    : null;

                if ($inspection_date) {
                    $row['inspections_conducted']++;
                }

                $inspection_type = strtolower(trim((string) $inspection->inspection_type));

                if ($inspection_type !== '') {
                    if (str_contains($inspection_type, 'self')) {
                        $row['self_certification_exempt_companies']++;
                    } elseif (str_contains($inspection_type, 'third')) {
                        $row['third_party_cert_exempt_companies']++;
                    }
                }
                if ($inspection_date && !empty($inspection->proposed_date)) {
                    try {
                        $proposed_date = Carbon::parse($inspection->proposed_date);
                        $hours_diff    = $inspection_date->diffInHours($proposed_date);

                        if ($hours_diff <= 48) {
                            $row['reports_uploaded_within_48_hrs']++;
                        } else {
                            $row['reports_uploaded_beyond_48_hrs']++;
                        }
                    } catch (\Exception $e) {
                        // Skip if date parsing fails
                    }
                }

                unset($row);
            }

            foreach ($summary as &$row) {
                $row['pending_inspections'] = $row['scheduled_inspections'] - $row['inspections_conducted'];
                if ($row['pending_inspections'] < 0) {
                    $row['pending_inspections'] = 0;
                }
            }
            unset($row);

            $rows = array_values($summary);

            return response()->json([
                'status'  => 1,
                'message' => 'CIS summary report.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function cis_details_report(Request $request)
    {
        try {
            $request->validate([
                'from_date'       => 'nullable|date',
                'to_date'         => 'nullable|date|after_or_equal:from_date',
                'department_id'   => 'nullable|integer|exists:departments,id',
                'inspection_for'  => 'nullable|array',
                'inspection_for.*' => 'string',
            ]);

            $from_date      = $request->from_date;
            $to_date        = $request->to_date;
            $department_id  = $request->department_id;
            $inspection_for = $request->inspection_for;

            $query = Inspection::with([
                'user:id,name_of_enterprise,registered_enterprise_address,mobile_no,email_id',
                'department:id,name',
            ]);

            if (!empty($from_date)) {
                $query->whereDate('inspection_date', '>=', $from_date);
            }

            if (!empty($to_date)) {
                $query->whereDate('inspection_date', '<=', $to_date);
            }

            if (!empty($department_id)) {
                $query->where('department_id', $department_id);
            }

            if (!empty($inspection_for) && is_array($inspection_for)) {

                $query->whereIn('inspection_type', $inspection_for);
            }

            $inspections = $query
                ->orderBy('inspection_date', 'asc')
                ->get();

            if ($inspections->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $rows = $inspections->map(function ($inspection) {
                $user = $inspection->user;
                $inspection_date = $inspection->inspection_date
                    ? Carbon::parse($inspection->inspection_date)->format('Y-m-d')
                    : null;

                $type_text = strtolower(trim((string) $inspection->inspection_type));
                $self_certification  = '';
                $third_party_cert    = '';

                if ($type_text !== '') {
                    if (str_contains($type_text, 'self')) {
                        $self_certification = 'Yes';
                    }

                    if (str_contains($type_text, 'third')) {
                        $third_party_cert = 'Yes';
                    }
                }

                return [
                    'enterprise_name'                 => $user ? $user->name_of_enterprise : null,
                    'address'                         => $user ? $user->registered_enterprise_address : null,
                    'contact_no'                      => $user ? $user->mobile_no : null,
                    'email'                           => $user ? $user->email_id : null,
                    'act_name'                        => $inspection->inspection_type,
                    'inspection_date'                 => $inspection_date,
                    'self_certification_exempt'       => $self_certification,
                    'third_party_cert_exempt'         => $third_party_cert,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'CIS details report.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function user_list(Request $request)
    {
        try {
            $request->validate([
                'from_date'     => 'nullable|date',
                'to_date'       => 'nullable|date',
                'department_id' => 'nullable|integer|exists:departments,id',
                'service_id'    => 'nullable|integer|exists:service_masters,id',
                'per_page'      => 'nullable|integer',
                'page'          => 'nullable|integer',
            ]);

            $from_date     = $request->from_date;
            $to_date       = $request->to_date;
            $department_id = $request->department_id;
            $service_id    = $request->service_id;
            $per_page      = $request->per_page ?? 15;
            $page          = $request->page ?? 1;

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

            $total = $query->count();
            $applications = $query
                ->orderBy('application_date', 'asc')
                ->skip(($page - 1) * $per_page)
                ->take($per_page)
                ->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                    'pagination' => [
                        'total'        => 0,
                        'per_page'     => $per_page,
                        'current_page' => $page,
                        'last_page'    => 0,
                    ],
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
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $per_page,
                    'current_page' => $page,
                    'last_page'    => ceil($total / $per_page),
                ],
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

    public function registration_renewal_granted(Request $request)
    {
        try {
            $request->validate([
                'department_id' => 'nullable|integer|exists:departments,id',
                'service_id' => 'nullable|integer|exists:service_masters,id',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
            ]);

            $query = UserServiceApplication::query();

            if ($request->filled('from_date')) {
                $query->whereDate('application_date', '>=', $request->from_date);
            }

            if ($request->filled('to_date')) {
                $query->whereDate('application_date', '<=', $request->to_date);
            }

            if ($request->filled('service_id')) {
                $query->where('service_id', $request->service_id);
            }

            if ($request->filled('department_id')) {
                $query->whereHas('service', function ($q) use ($request) {
                    $q->where('department_id', $request->department_id);
                });
            }

            $applications = $query->with(['service', 'user.management_details'])->get();

            $report = [];

            foreach (['Registration', 'Renewal'] as $type) {
                $apps = $applications->filter(function ($app) use ($type) {
                    return $type === 'Renewal' ? $app->renewal === 'yes' : $app->renewal !== 'yes';
                });

                if ($apps->isEmpty()) {
                    continue;
                }

                $approved = $apps->where('status', 'approved');

                $female_received = $apps->filter(function ($app) {
                    return $app->user?->management_details?->owner_details_is_women_entrepreneur === 'YES';
                });

                $female_approved = $approved->filter(function ($app) {
                    return $app->user?->management_details?->owner_details_is_women_entrepreneur === 'YES';
                });

                $durations = $approved
                    ->filter(function ($app) {
                        return $app->NOC_generationDate && $app->application_date;
                    })
                    ->map(function ($app) {
                        $start = strtotime($app->application_date);
                        $end   = strtotime($app->NOC_generationDate);

                        return abs(($end - $start) / 86400);
                    });

                $fees = $approved
                    ->pluck('approved_fee')
                    ->filter(function ($fee) {
                        return $fee !== null && $fee !== '';
                    })
                    ->map(function ($fee) {
                        return (float) $fee;
                    });


                $median_time = 0;
                if ($durations->count() > 0) {
                    $sorted = $durations->sort()->values();
                    $mid = floor(($durations->count() - 1) / 2);
                    $median_time = $durations->count() % 2 ? $sorted[$mid] : ($sorted[$mid] + $sorted[$mid + 1]) / 2;
                }

                $report[] = [
                    'type' => $type,
                    'time_limit' => $apps->first()?->service?->target_days ?? 0,
                    'total_received' => $apps->count(),
                    'total_female_owned' => $female_received->count(),
                    'total_approved' => $approved->count(),
                    'total_approved_female_owned' => $female_approved->count(),
                    'avg_time' => round($durations->avg() ?? 0, 2),
                    'median_time' => round($median_time, 2),
                    'min_time' => round($durations->min() ?? 0, 2),
                    'max_time' => round($durations->max() ?? 0, 2),
                    'avg_fee' => round($fees->avg() ?? 0, 2),
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Registration/Renewal granted report generated successfully.',
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

    public function inspection_summary_report(Request $request)
    {
        try {

            $request->validate([
                'department_id' => 'nullable|integer|exists:departments,id',
                'from_date'     => 'nullable|date',
                'to_date'       => 'nullable|date',
            ]);

            $query = Inspection::query()
                ->select(
                    'department_id',
                    DB::raw('COUNT(*) as total_inspections'),
                )
                ->whereNotNull('inspection_date')
                ->whereNotNull('proposed_date')
                ->groupBy('department_id');

            if ($request->filled('from_date') && $request->filled('to_date')) {
                $query->whereBetween('inspection_date', [$request->from_date, $request->to_date]);
            }

            if ($request->filled('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            $data = $query->get();

            $report = $data->map(function ($row) {

                $department = Department::find($row->department_id);

                $inspections = Inspection::where('department_id', $row->department_id)
                    ->whereNotNull('inspection_date')
                    ->whereNotNull('proposed_date')
                    ->get(['inspection_date', 'proposed_date']);

                $durations = $inspections->map(function ($i) {
                    return (strtotime($i->inspection_date) - strtotime($i->proposed_date)) / 86400;
                });

                $avg_time = $durations->avg() ?? 0;

                $count = $durations->count();
                $median = 0;

                if ($count > 0) {
                    $sorted = $durations->sort()->values();
                    $middle = floor(($count - 1) / 2);

                    $median = ($count % 2)
                        ? $sorted[$middle]
                        : ($sorted[$middle] + $sorted[$middle + 1]) / 2;
                }

                $within_24 = $durations->filter(function ($d) {
                    return $d <= 1;
                })->count();

                $total = $durations->count();

                $within_24_percent = $total > 0 ? ($within_24 / $total) * 100 : 0;
                $above_24_percent  = 100 - $within_24_percent;

                return [
                    'department_name' => $department->name ?? null,
                    'total_inspections' => (int) $row->total_inspections,
                    'avg_time_days' => round($avg_time, 2),
                    'median_time_days' => round($median, 2),
                    'reports_within_24hrs_percent' => round($within_24_percent, 2),
                    'reports_above_24hrs_percent' => round($above_24_percent, 2),
                ];
            });

            return response()->json([
                'status' => 1,
                'message' => 'Inspection summary generated successfully',
                'data' => $report
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Failed to generate report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update_third_party_report(Request $request)
    {
        try {
            $request->validate([
                'data'                          => 'required|array',
                'data.*.service_id'             => 'required|integer|exists:service_masters,id',
                'data.*.total_received'         => 'required|integer',
                'data.*.total_processed'        => 'required|integer',
                'data.*.total_approved'         => 'required|integer',
                'data.*.max_time_to_approve'    => 'nullable|numeric',
                'data.*.min_time_to_approve'    => 'nullable|numeric',
                'data.*.avg_time_to_approve'    => 'nullable|numeric',
                'data.*.median_time_to_approve' => 'nullable|numeric',
                'data.*.avg_fee'                => 'nullable|numeric',
            ]);

            foreach ($request->data as $row) {
                SingleWindowReport::updateOrCreate(
                    ['type' => 'third_party', 'service_id' => $row['service_id']],
                    [
                        'total_received'         => $row['total_received'],
                        'total_processed'        => $row['total_processed'],
                        'total_approved'         => $row['total_approved'],
                        'max_time_to_approve'    => $row['max_time_to_approve'] ?? 0,
                        'min_time_to_approve'    => $row['min_time_to_approve'] ?? 0,
                        'avg_time_to_approve'    => $row['avg_time_to_approve'] ?? 0,
                        'median_time_to_approve' => $row['median_time_to_approve'] ?? 0,
                        'avg_fee'                => $row['avg_fee'] ?? 0,
                    ]
                );
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Third party report updated successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function online_single_windows_report()
    {
        try {
            $rows = SingleWindowReport::with(['service:id,department_id,service_title_or_description,target_days', 'service.department:id,name'])
                ->whereIn('type', ['native', 'third_party'])
                ->get()
                ->map(function ($row) {
                    return [
                        'department_name'        => $row->service?->department?->name,
                        'noc_description'        => $row->service?->service_title_or_description,
                        'time_limit'             => $row->service?->target_days,
                        'total_received'         => (int) $row->total_received,
                        'total_processed'        => (int) $row->total_processed,
                        'total_approved'         => (int) $row->total_approved,
                        'max_time_to_approve'    => round($row->max_time_to_approve, 2),
                        'min_time_to_approve'    => round($row->min_time_to_approve, 2),
                        'avg_time_to_approve'    => round($row->avg_time_to_approve, 2),
                        'median_time_to_approve' => round($row->median_time_to_approve, 2),
                        'avg_fee'                => round($row->avg_fee, 2),
                        'type'                   => $row->type,
                    ];
                })
                ->values();

            return response()->json([
                'status'  => 1,
                'message' => 'Single window reports.',
                'data'    => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function payment_report(Request $request)
    {
        try {
            $request->validate([
                'department_id'  => 'nullable|integer|exists:departments,id',
                'service_id'     => 'nullable|integer|exists:service_masters,id',
                'from_date'      => 'nullable|date',
                'to_date'        => 'nullable|date',
                'payment_status' => 'nullable|string|in:pending,paid,failed',
                'per_page'       => 'nullable|integer|min:1|max:500',
            ]);

            [$allRows, $summary] = $this->build_payment_report_data($request);

            $per_page     = (int) ($request->per_page ?? 15);
            $current_page = (int) ($request->page ?? 1);
            $total        = count($allRows);
            $paged_rows   = array_slice($allRows, ($current_page - 1) * $per_page, $per_page);

            return response()->json([
                'status'  => 1,
                'message' => 'Payment report generated successfully.',
                'summary' => $summary,
                'report'  => [
                    'data'       => $paged_rows,
                    'pagination' => [
                        'total'        => $total,
                        'per_page'     => $per_page,
                        'current_page' => $current_page,
                        'last_page'    => (int) ceil($total / $per_page),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 500);
        }
    }

    public function payment_report_export(Request $request)
    {
        try {
            $request->validate([
                'department_id'  => 'nullable|integer|exists:departments,id',
                'service_id'     => 'nullable|integer|exists:service_masters,id',
                'from_date'      => 'nullable|date',
                'to_date'        => 'nullable|date',
                'payment_status' => 'nullable|string|in:pending,paid,failed',
                'export_type'    => 'nullable|string|in:detailed,summary',
            ]);

            [$rows, $summary] = $this->build_payment_report_data($request);
            $export_type = $request->input('export_type', 'detailed');
            $export_data = $export_type === 'summary' ? $summary : $rows;
            $filename = 'payment_report_' . now()->format('Y_m_d_His') . '.xlsx';

            return Excel::download(new PaymentReportExport($export_data, $summary, $export_type), $filename);
        } catch (\Throwable $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 500);
        }
    }

    private function build_payment_report_data(Request $request): array
    {
        $query = PaymentOrder::query()->orderByDesc('payment_datetime');

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('payment_datetime', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('payment_datetime', '<=', $request->to_date);
        }

        $orders = $query->get();

        $all_application_ids = $orders
            ->flatMap(fn($order) => json_decode($order->application_id, true) ?? [])
            ->unique()->values()->all();

        $applications = UserServiceApplication::with(['service.department'])
            ->whereIn('id', $all_application_ids)
            ->when($request->filled('department_id'), fn($q) => $q->whereHas('service', fn($q) => $q->where('department_id', $request->department_id)))
            ->when($request->filled('service_id'), fn($q) => $q->where('service_id', $request->service_id))
            ->get()
            ->keyBy('id');

        $rows    = [];
        $summary = [];

        foreach ($orders as $order) {
            $app_ids    = json_decode($order->application_id, true) ?? [];
            $serviceFee = $order->payment_status === 'paid'
                ? (float) ($order->establishment_fee_paid ?? $order->operational_fee_paid ?? 0)
                : 0;

            foreach ($app_ids as $index => $app_id) {
                $app = $applications->get($app_id);

                if (!$app) continue;

                $amount         = (float) ($app->paid_amount ?? 0) + ($index === 0 ? $serviceFee : 0);
                $department     = $app->service?->department?->name ?? 'N/A';
                $department_id  = $app->service?->department?->id ?? 0;
                $payment_status = strtolower($order->payment_status);

                $rows[] = [
                    'id'             => $order->id,
                    'date'           => $order->payment_datetime
                        ? Carbon::parse($order->payment_datetime)->format('d-m-Y')
                        : null,
                    'department'     => $department,
                    'application_no' => $app->applicationId ?? $app->id,
                    'service'        => $app->service?->service_title_or_description,
                    'order_id'       => $order->order_id,
                    'grn_no'         => $order->GRN_number,
                    'payment_status' => ucfirst($order->payment_status),
                    'amount'         => $amount,
                ];

                if (!isset($summary[$department_id])) {
                    $summary[$department_id] = [
                        'department_id'      => $department_id,
                        'department'         => $department,
                        'total_transactions' => 0,
                        'paid'               => 0,
                        'pending'            => 0,
                        'failed'             => 0,
                        'total_amount'       => 0,
                    ];
                }

                $summary[$department_id]['total_transactions']++;
                $summary[$department_id][$payment_status]++;

                if ($payment_status === 'paid') {
                    $summary[$department_id]['total_amount'] += $amount;
                }
            }
        }

        return [$rows, array_values($summary)];
    }
}
