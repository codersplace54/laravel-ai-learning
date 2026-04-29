<?php

namespace App\Http\Controllers;

use App\Models\LineOfActivity;
use App\Models\UserIncentiveApplication;
use App\Models\TripuraMasterData;
use App\Models\UserFeedback;
use App\Models\Department;
use App\Models\ServiceMaster;
use Illuminate\Http\Request;

class AnnexureController extends Controller
{
    public function incentive_dashboard(Request $request)
    {
        try {
            $request->validate([
                'scheme_id'          => 'nullable|integer|exists:schemes,id',
                'sector'             => 'nullable|string|max:255',
                'district_code'      => 'nullable|string',
                'application_status' => 'nullable|string',
                'from_date'          => 'nullable|date',
                'to_date'            => 'nullable|date|after_or_equal:from_date',
            ]);

            $sector_user_ids = null;
            if ($request->filled('sector')) {
                $sector_user_ids = LineOfActivity::where('thrust_sector', $request->sector)
                    ->pluck('user_id')->unique()->values();
            }

            $apps = UserIncentiveApplication::query()
                ->when($request->filled('scheme_id'),          fn($q) => $q->where('scheme_id', $request->scheme_id))
                ->when($request->filled('application_status'), fn($q) => $q->where('workflow_status', $request->application_status))
                ->when($request->filled('from_date'),          fn($q) => $q->whereDate('submitted_at', '>=', $request->from_date))
                ->when($request->filled('to_date'),            fn($q) => $q->whereDate('submitted_at', '<=', $request->to_date))
                ->when($sector_user_ids !== null,              fn($q) => $q->whereIn('user_id', $sector_user_ids))
                ->when($request->filled('district_code'),      fn($q) => $q->whereHas('user', fn($u) => $u->where('district_id', $request->district_code)))
                ->with(['proforma.scheme', 'user'])
                ->get();

            $approved_statuses  = ['noc_issued', 'claim_approved_by_gm', 'claim_approved_by_slc', 'approved_by_da'];
            $disbursed_statuses = ['claim_approved_by_slc', 'claim_approved_by_gm'];

            $processing_days = $apps->filter(fn($a) => $a->decided_at)
                ->map(fn($a) => $a->submitted_at->diffInDays($a->decided_at))
                ->filter(fn($d) => $d >= 0)->sort()->values();

            $district_names = TripuraMasterData::whereNotNull('district_code')
                ->select('district_code', 'district_name')->get()
                ->unique('district_code')->pluck('district_name', 'district_code');

            $sector_map = LineOfActivity::whereIn('user_id', $apps->pluck('user_id')->unique())
                ->select('user_id', 'thrust_sector')->get()
                ->unique('user_id')->pluck('thrust_sector', 'user_id');

            $grouped = $apps->groupBy(fn($a) => implode('|', [
                $a->proforma_id,
                $sector_map[$a->user_id] ?? '',
                $a->user->district_id ?? '',
            ]));

            $table_data = $grouped->values()->map(function ($group, $index) use ($approved_statuses, $disbursed_statuses, $district_names, $sector_map) {
                $first = $group->first();
                $days  = $group->filter(fn($a) => $a->decided_at)
                    ->map(fn($a) => $a->submitted_at->diffInDays($a->decided_at))
                    ->filter(fn($d) => $d >= 0)->sort()->values();

                $district_code = $first->user->district_id ?? null;

                return [
                    'sl_no'                  => $index + 1,
                    'metric'                 => $first->proforma->title ?? null,
                    'scheme'                 => $first->proforma->scheme->title ?? null,
                    'sector'                 => $sector_map[$first->user_id] ?? null,
                    'district'               => $district_code ? ($district_names[$district_code] ?? $district_code) : null,
                    'application_period'     => $group->min('submitted_at') && $group->max('submitted_at')
                        ? date('M Y', strtotime($group->min('submitted_at'))) . ' - ' . date('M Y', strtotime($group->max('submitted_at')))
                        : null,
                    'applications_received'  => $group->count(),
                    'applications_approved'  => $group->whereIn('workflow_status', $approved_statuses)->count(),
                    'applications_disbursed' => $group->whereIn('workflow_status', $disbursed_statuses)->count(),
                    'avg_processing_time'    => $days->count() > 0 ? round($days->avg(), 2) : null,
                    'median_time'            => $this->calculate_median($days),
                    'min_time'               => $days->count() > 0 ? $days->min() : null,
                    'max_time'               => $days->count() > 0 ? $days->max() : null,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Incentive dashboard data fetched successfully.',
                'data'    => [
                    'summary'    => [
                        'applications_received'  => $apps->count(),
                        'applications_approved'  => $apps->whereIn('workflow_status', $approved_statuses)->count(),
                        'applications_disbursed' => $apps->whereIn('workflow_status', $disbursed_statuses)->count(),
                        'avg_processing_time'    => $processing_days->count() > 0 ? round($processing_days->avg(), 2) : null,
                        'median_time'            => $this->calculate_median($processing_days),
                        'min_time'               => $processing_days->count() > 0 ? round($processing_days->min(), 2) : null,
                        'max_time'               => $processing_days->count() > 0 ? round($processing_days->max(), 2) : null,
                    ],
                    'table_data' => $table_data,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    public function incentive_dashboard_filters()
    {
        try {
            $sectors = LineOfActivity::distinct()
                ->whereNotNull('thrust_sector')->where('thrust_sector', '!=', '')
                ->pluck('thrust_sector');

            $districts = TripuraMasterData::whereNotNull('district_name')->whereNotNull('district_code')
                ->select('district_code', 'district_name')->get()
                ->unique('district_code')->values();

            $statuses = [
                ['value' => 'submitted',             'label' => 'Received'],
                ['value' => 'approved_by_da',        'label' => 'Approved (DA)'],
                ['value' => 'noc_issued',            'label' => 'NOC Issued'],
                ['value' => 'claim_approved_by_gm',  'label' => 'Approved (GM)'],
                ['value' => 'claim_approved_by_slc', 'label' => 'Disbursed (SLC)'],
                ['value' => 'rejected_by_da',        'label' => 'Rejected (DA)'],
                ['value' => 'rejected_by_gm',        'label' => 'Rejected (GM)'],
                ['value' => 'rejected_by_slc',       'label' => 'Rejected (SLC)'],
            ];

            return response()->json([
                'status'  => 1,
                'message' => 'Filter options fetched successfully.',
                'data'    => compact('sectors', 'districts', 'statuses'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    const SLA_DAYS = 7;

    public function service_level_queries(Request $request)
    {
        try {
            $request->validate([
                'department_id'     => 'nullable|integer|exists:departments,id',
                'service_id'        => 'nullable|integer|exists:service_masters,id',
                'from_date'         => 'nullable|date',
                'to_date'           => 'nullable|date|after_or_equal:from_date',
                'escalation_status' => 'nullable|in:escalated,not_escalated',
                'sla_status'        => 'nullable|in:met,unmet',
            ]);

            $feedbacks = UserFeedback::query()
                ->with(['department', 'service'])
                ->when($request->filled('department_id'),     fn($q) => $q->where('department_id', $request->department_id))
                ->when($request->filled('service_id'),        fn($q) => $q->where('service_id', $request->service_id))
                ->when($request->filled('from_date'),         fn($q) => $q->whereDate('created_at', '>=', $request->from_date))
                ->when($request->filled('to_date'),           fn($q) => $q->whereDate('created_at', '<=', $request->to_date))
                ->when($request->filled('escalation_status'), fn($q) => $q->where('escalated', $request->escalation_status === 'escalated'))
                ->get();

            $feedbacks->each(function ($fb) {
                if ($fb->resolved_at) {
                    $fb->resolution_time = $fb->created_at->diffInDays($fb->resolved_at);
                    $fb->sla_met         = $fb->resolution_time <= self::SLA_DAYS;
                } else {
                    $fb->resolution_time = null;
                    $fb->sla_met         = false;
                }
            });

            if ($request->filled('sla_status')) {
                if ($request->sla_status == 'met') {
                    $feedbacks = $feedbacks->where('sla_met', true)->values();
                } else {
                    $feedbacks = $feedbacks->where('sla_met', false)->values();
                }
            }

            $grouped = $feedbacks->groupBy(function ($fb) {
                $dept = $fb->department_id ?? 'none';
                $service = $fb->service_id ?? 'none';
                return $dept . '|' . $service;
            });
            $table_data = $grouped->values()->map(function ($group, $index) {
                $first            = $group->first();
                $resolved         = $group->filter(fn($fb) => $fb->resolution_time !== null);
                $resolution_times = $resolved->pluck('resolution_time')->sort()->values();
                $total            = $group->count();
                $within_sla       = $resolved->filter(fn($fb) => $fb->sla_met)->count();

                return [
                    'sl_no'               => $index + 1,
                    'department'          => $first->department->name ?? null,
                    'service'             => $first->service->service_title_or_description ?? null,
                    'total_queries'       => $total,
                    'resolved_within_sla' => $within_sla,
                    'sla_compliance_pct'  => $total > 0 ? round(($within_sla / $total) * 100, 1) : 0,
                    'avg_resolution_time' => $resolution_times->count() > 0 ? round($resolution_times->avg(), 2) : null,
                    'median_time'         => $this->calculate_median($resolution_times),
                    'min_time'            => $resolution_times->count() > 0 ? $resolution_times->min() : null,
                    'max_time'            => $resolution_times->count() > 0 ? $resolution_times->max() : null,
                ];
            });

            $all_resolved         = $feedbacks->filter(fn($fb) => $fb->resolution_time !== null);
            $all_resolution_times = $all_resolved->pluck('resolution_time')->sort()->values();
            $all_within_sla       = $all_resolved->filter(fn($fb) => $fb->sla_met)->count();
            $total_count          = $feedbacks->count();

            return response()->json([
                'status'  => 1,
                'message' => 'Queries SLA dashboard fetched successfully.',
                'data'    => [
                    'summary' => [
                        'total_queries'       => $total_count,
                        'resolved_within_sla' => $all_within_sla,
                        'sla_compliance_pct'  => $total_count > 0 ? round(($all_within_sla / $total_count) * 100, 1) : 0,
                        'avg_resolution_time' => $all_resolution_times->count() > 0 ? round($all_resolution_times->avg(), 2) : null,
                        'median_time'         => $this->calculate_median($all_resolution_times),
                        'min_time'            => $all_resolution_times->count() > 0 ? $all_resolution_times->min() : null,
                        'max_time'            => $all_resolution_times->count() > 0 ? $all_resolution_times->max() : null,
                    ],
                    'table_data' => $table_data,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    public function individual_queries_tracker(Request $request)
    {
        try {
            $request->validate([
                'department_id'     => 'nullable|integer|exists:departments,id',
                'service_id'        => 'nullable|integer|exists:service_masters,id',
                'from_date'         => 'nullable|date',
                'to_date'           => 'nullable|date|after_or_equal:from_date',
                'escalation_status' => 'nullable|in:escalated,not_escalated',
                'sla_status'        => 'nullable|in:met,unmet',
                'per_page'          => 'nullable|integer|min:1|max:100',
            ]);

            $per_page = $request->per_page ?? 15;

            $query = UserFeedback::query()
                ->with(['department', 'service'])
                ->when($request->filled('department_id'),     fn($q) => $q->where('department_id', $request->department_id))
                ->when($request->filled('service_id'),        fn($q) => $q->where('service_id', $request->service_id))
                ->when($request->filled('from_date'),         fn($q) => $q->whereDate('created_at', '>=', $request->from_date))
                ->when($request->filled('to_date'),           fn($q) => $q->whereDate('created_at', '<=', $request->to_date))
                ->when($request->filled('escalation_status'), fn($q) => $q->where('escalated', $request->escalation_status === 'escalated'))
                ->orderBy('id', 'desc');

            $paginated = $query->paginate($per_page);

            $rows = collect($paginated->items())->map(function ($fb) {
                $resolution_time = null;
                $sla_met         = null;

                if ($fb->resolved_at) {
                    $resolution_time = $fb->created_at->diffInDays($fb->resolved_at);
                    $sla_met         = $resolution_time <= self::SLA_DAYS;
                }

                $is_delayed = !$fb->resolved_at && $fb->created_at->diffInDays(now()) > (self::SLA_DAYS + 2);

                return [
                    'id'                  => $fb->id,
                    'ticket_id'           => 'QT-' . $fb->created_at->format('Y') . '-' . str_pad($fb->id, 3, '0', STR_PAD_LEFT),
                    'service'             => $fb->service->service_title_or_description ?? null,
                    'department'          => $fb->department->name ?? null,
                    'submitted_on'        => $fb->created_at->format('d-M-Y'),
                    'final_resolution_date' => $fb->resolved_at ? $fb->resolved_at->format('d-M-Y') : null,
                    'actual_resolution_time' => $resolution_time,
                    'status'              => $fb->resolved_at ? 'Resolved' : 'Pending',
                    'escalated'           => $fb->escalated ? 'Yes' : 'No',
                    'is_delayed'          => $is_delayed,
                    'sla_met'             => $sla_met === null ? null : ($sla_met ? 'Met' : 'Unmet'),
                    'resolution_summary'  => $fb->suggestions,
                ];
            });

            if ($request->filled('sla_status')) {
                $rows = $rows->filter(function ($row) use ($request) {
                    if ($row['sla_met'] === null) return false;
                    return $request->sla_status === 'met'
                        ? $row['sla_met'] === 'Met'
                        : $row['sla_met'] === 'Unmet';
                })->values();
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Query resolution tracker fetched successfully.',
                'data'    => $rows,
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    public function queries_dashboard_filters()
    {
        try {
            $departments = Department::select('id', 'name')
                ->whereIn('id', UserFeedback::distinct()->whereNotNull('department_id')->pluck('department_id'))
                ->get();

            $services = ServiceMaster::select('id', 'service_title_or_description')
                ->whereIn('id', UserFeedback::distinct()->whereNotNull('service_id')->pluck('service_id'))
                ->get();

            $sla_statuses = [
                ['value' => 'met',   'label' => 'SLA Met'],
                ['value' => 'unmet', 'label' => 'SLA Unmet'],
            ];

            $escalation_statuses = [
                ['value' => 'escalated',     'label' => 'Escalated'],
                ['value' => 'not_escalated', 'label' => 'Not Escalated'],
            ];

            return response()->json([
                'status'  => 1,
                'message' => 'Filter options fetched successfully.',
                'data'    => compact('departments', 'services', 'sla_statuses', 'escalation_statuses'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    private function calculate_median($days): ?float
    {
        $sorted = collect($days)->filter(fn($d) => $d >= 0)->sort()->values();
        $count  = $sorted->count();
        if ($count === 0) return null;
        $mid = intdiv($count, 2);
        return $count % 2 === 0
            ? round(($sorted[$mid - 1] + $sorted[$mid]) / 2, 2)
            : (float) $sorted[$mid];
    }
}
