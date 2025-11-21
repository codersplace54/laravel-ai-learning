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

class ReportController extends Controller
{
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
                    'inspection_date'                 => $inspection->inspection_date
                        ? Carbon::parse($inspection->inspection_date)->format('Y-m-d')
                        : null,
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
                $inspection_query->whereYear('proposed_date', $year);
            }

            if (!empty($month)) {
                $inspection_query->whereMonth('proposed_date', $month);
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

                if ($inspection_date && $inspection->created_at) {
                    $uploaded_at = Carbon::parse($inspection->created_at);
                    $hours_diff  = $inspection_date->diffInHours($uploaded_at);

                    if ($hours_diff <= 48) {
                        $row['reports_uploaded_within_48_hrs']++;
                    } else {
                        $row['reports_uploaded_beyond_48_hrs']++;
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
                });

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

                $workflow_steps = $application->workflow ?? collect();
                $status         = (string) $application->status;

                if (in_array($status, ['in_progress', 'approved', 'rejected', 'send_back', 'extra_payment'])) {
                    $department_row['processed']++;
                }

                $approved_step = $workflow_steps->where('status', 'approved')
                    ->sortByDesc('action_taken_at')
                    ->first();

                $rejected_step = $workflow_steps->where('status', 'rejected')
                    ->sortByDesc('action_taken_at')
                    ->first();

                if ($approved_step) {
                    $department_row['approved']++;
                }

                if ($rejected_step) {
                    $department_row['rejected']++;
                }

                $latest_step = $workflow_steps->sortByDesc('action_taken_at')->first();

                if ($latest_step && $latest_step->status === 'send_back') {
                    $department_row['clarification_stage']++;
                }

                if (
                    $latest_step &&
                    !in_array($latest_step->status, ['pending', 'in_progress', 'approved', 'rejected', 'send_back', 'saved'])
                ) {
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

                $has_final_decision = $approved_step || $rejected_step;

                if ($due_date && !$has_final_decision) {
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
                ->whereDate('application_date', '<=', $to_date)
                ->whereNotNull('user_id');

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

            $users = User::whereIn('id', $user_ids)->get()->keyBy('id');

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
                $user  = $users->get($user_id);
                $unit  = $units->get($user_id);

                if (!$user || !$unit) {
                    continue;
                }

                $dist = trim((string) $unit->unit_location_district);
                $subd = trim((string) $unit->unit_location_subdivision);

                if ($dist === '') {
                    $dist = 'N/A';
                }

                if ($subd === '') {
                    $subd = 'N/A';
                }

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
                    'investment'                     => $unit->investment_details_total_project_cost ?? null,
                    'employment'                     => $unit->employment_details_total_employment ?? null,
                    'turnover'                       => $unit->annual_turnover ?? null,
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

    public function industry_report_summary(Request $request)
    {
        try {
            $request->validate([
                'from_date'    => 'nullable|date',
                'to_date'      => 'nullable|date|after_or_equal:from_date',
                'district'     => 'nullable|integer',
                'sub_division' => 'nullable|integer',
            ]);

            $from_date      = $request->from_date;
            $to_date        = $request->to_date;
            $district_id    = $request->district;
            $sub_division_id = $request->sub_division;

            $application_query = UserServiceApplication::query();

            if (!empty($from_date)) {
                $application_query->whereDate('application_date', '>=', $from_date);
            }

            if (!empty($to_date)) {
                $application_query->whereDate('application_date', '<=', $to_date);
            }

            $user_ids = $application_query
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->unique()
                ->values();

            if ($user_ids->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No record found.',
                    'data'    => [],
                ]);
            }

            $units = UnitDetail::whereIn('user_id', $user_ids)
                ->get()
                ->keyBy('user_id');

            $activities = Activity::whereIn('user_id', $user_ids)
                ->get()
                ->keyBy('user_id');

            $summary = [];

            foreach ($user_ids as $user_id) {
                $unit = $units->get($user_id);
                if (!$unit) {
                    continue;
                }

                $dist_id = $unit->unit_location_district;
                $subd_id = $unit->unit_location_subdivision;

                if (empty($dist_id) || empty($subd_id)) {
                    continue;
                }

                if (!empty($district_id) && (int) $district_id !== (int) $dist_id) {
                    continue;
                }

                if (!empty($sub_division_id) && (int) $sub_division_id !== (int) $subd_id) {
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
                    $summary[$dist_id][$subd_id] = [
                        'district_id'     => (int) $dist_id,
                        'sub_division_id' => (int) $subd_id,
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
