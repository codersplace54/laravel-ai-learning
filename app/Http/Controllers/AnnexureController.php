<?php

namespace App\Http\Controllers;

use App\Models\LineOfActivity;
use App\Models\UserIncentiveApplication;
use App\Models\TripuraMasterData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

            $base = UserIncentiveApplication::query()
                ->join('proformas', 'proformas.id', '=', 'user_incentive_applications.proforma_id')
                ->join('schemes', 'schemes.id', '=', 'user_incentive_applications.scheme_id')
                ->join('users', 'users.id', '=', 'user_incentive_applications.user_id')
                ->leftJoin('line_of_activities', 'line_of_activities.user_id', '=', 'users.id')
                ->leftJoin('tripura_master_data as tmd', 'tmd.district_code', '=', 'users.district_id')
                ->whereNotNull('user_incentive_applications.submitted_at')
                ->when($request->filled('scheme_id'),          fn($q) => $q->where('user_incentive_applications.scheme_id', $request->scheme_id))
                ->when($request->filled('sector'),             fn($q) => $q->where('line_of_activities.thrust_sector', $request->sector))
                ->when($request->filled('district_code'),      fn($q) => $q->where('users.district_id', $request->district_code))
                ->when($request->filled('application_status'), fn($q) => $q->where('user_incentive_applications.workflow_status', $request->application_status))
                ->when($request->filled('from_date'),          fn($q) => $q->whereDate('user_incentive_applications.submitted_at', '>=', $request->from_date))
                ->when($request->filled('to_date'),            fn($q) => $q->whereDate('user_incentive_applications.submitted_at', '<=', $request->to_date));

            $all_ids = (clone $base)->pluck('user_incentive_applications.id');

            $total_received  = $all_ids->count();
            $total_approved  = (clone $base)->whereIn('user_incentive_applications.workflow_status', ['noc_issued', 'claim_approved_by_gm', 'claim_approved_by_slc', 'approved_by_da'])->count();
            $total_disbursed = (clone $base)->whereIn('user_incentive_applications.workflow_status', ['claim_approved_by_slc', 'claim_approved_by_gm'])->count();

            $processing_times = UserIncentiveApplication::whereIn('id', $all_ids)
                ->whereNotNull('decided_at')
                ->selectRaw('DATEDIFF(decided_at, submitted_at) as days')
                ->pluck('days')
                ->filter(fn($d) => $d >= 0)
                ->sort()
                ->values();

            $count       = $processing_times->count();
            $avg_time    = $count > 0 ? round($processing_times->avg(), 2) : null;
            $min_time    = $count > 0 ? $processing_times->min() : null;
            $max_time    = $count > 0 ? $processing_times->max() : null;
            $median_time = $this->calculate_median($processing_times);

            $rows = (clone $base)
                ->select(
                    'proformas.id as proforma_id',
                    'proformas.title as metric',
                    'schemes.title as scheme_name',
                    'line_of_activities.thrust_sector as sector',
                    'tmd.district_name as district',
                    DB::raw('MIN(user_incentive_applications.submitted_at) as period_start'),
                    DB::raw('MAX(user_incentive_applications.submitted_at) as period_end'),
                    DB::raw('COUNT(DISTINCT user_incentive_applications.id) as applications_received'),
                    DB::raw('SUM(user_incentive_applications.workflow_status IN ("noc_issued","claim_approved_by_gm","claim_approved_by_slc","approved_by_da")) as applications_approved'),
                    DB::raw('SUM(user_incentive_applications.workflow_status IN ("claim_approved_by_slc","claim_approved_by_gm")) as applications_disbursed'),
                    DB::raw('AVG(CASE WHEN user_incentive_applications.decided_at IS NOT NULL THEN DATEDIFF(user_incentive_applications.decided_at, user_incentive_applications.submitted_at) END) as avg_processing_time'),
                    DB::raw('MIN(CASE WHEN user_incentive_applications.decided_at IS NOT NULL THEN DATEDIFF(user_incentive_applications.decided_at, user_incentive_applications.submitted_at) END) as min_time'),
                    DB::raw('MAX(CASE WHEN user_incentive_applications.decided_at IS NOT NULL THEN DATEDIFF(user_incentive_applications.decided_at, user_incentive_applications.submitted_at) END) as max_time')
                )
                ->groupBy('proformas.id', 'proformas.title', 'schemes.title', 'line_of_activities.thrust_sector', 'tmd.district_name')
                ->orderBy('proformas.id')
                ->get();

            $proforma_ids = $rows->pluck('proforma_id');

            $days_by_proforma = UserIncentiveApplication::whereIn('user_incentive_applications.id', $all_ids)
                ->whereNotNull('decided_at')
                ->whereIn('proforma_id', $proforma_ids)
                ->selectRaw('proforma_id, DATEDIFF(decided_at, submitted_at) as days')
                ->get()
                ->groupBy('proforma_id');

            $table_data = $rows->values()->map(fn($row, $index) => [
                'sl_no'                  => $index + 1,
                'metric'                 => $row->metric,
                'scheme'                 => $row->scheme_name,
                'sector'                 => $row->sector,
                'district'               => $row->district,
                'application_period'     => $row->period_start && $row->period_end
                    ? date('M Y', strtotime($row->period_start)) . ' - ' . date('M Y', strtotime($row->period_end))
                    : null,
                'applications_received'  => (int) $row->applications_received,
                'applications_approved'  => (int) $row->applications_approved,
                'applications_disbursed' => (int) $row->applications_disbursed,
                'avg_processing_time'    => $row->avg_processing_time ? round($row->avg_processing_time, 2) : null,
                'median_time'            => $this->calculate_median(
                    ($days_by_proforma[$row->proforma_id] ?? collect())->pluck('days')
                ),
                'min_time'               => $row->min_time,
                'max_time'               => $row->max_time,
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Incentive dashboard data fetched successfully.',
                'data'    => [
                    'summary'    => [
                        'applications_received'  => $total_received,
                        'applications_approved'  => $total_approved,
                        'applications_disbursed' => $total_disbursed,
                        'avg_processing_time'    => $avg_time,
                        'median_time'            => $median_time,
                        'min_time'               => $min_time,
                        'max_time'               => $max_time,
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
                ->whereNotNull('thrust_sector')
                ->where('thrust_sector', '!=', '')
                ->pluck('thrust_sector');

            $districts = TripuraMasterData::whereNotNull('district_name')
                ->whereNotNull('district_code')
                ->select('district_code', 'district_name')
                ->distinct()
                ->orderBy('district_name')
                ->get();

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
                'data'    => [
                    'sectors'   => $sectors,
                    'districts' => $districts,
                    'statuses'  => $statuses,
                ],
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
