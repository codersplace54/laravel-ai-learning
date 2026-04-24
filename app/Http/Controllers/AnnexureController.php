<?php

namespace App\Http\Controllers;

use App\Models\LineOfActivity;
use App\Models\UserIncentiveApplication;
use App\Models\TripuraMasterData;
use App\Models\Scheme;
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

            $sector_user_ids = null;
            if ($request->filled('sector')) {
                $sector_user_ids = LineOfActivity::where('thrust_sector', $request->sector)
                    ->pluck('user_id')
                    ->unique()
                    ->values();
            }

            $apps = UserIncentiveApplication::query()
                // ->whereNotNull('submitted_at')
                ->when($request->filled('scheme_id'),          fn($q) => $q->where('scheme_id', $request->scheme_id))
                ->when($request->filled('application_status'), fn($q) => $q->where('workflow_status', $request->application_status))
                ->when($request->filled('from_date'),          fn($q) => $q->whereDate('submitted_at', '>=', $request->from_date))
                ->when($request->filled('to_date'),            fn($q) => $q->whereDate('submitted_at', '<=', $request->to_date))
                ->when($sector_user_ids !== null,              fn($q) => $q->whereIn('user_id', $sector_user_ids))
                ->when($request->filled('district_code'),      fn($q) => $q->whereHas('user', fn($u) => $u->where('district_id', $request->district_code)))
                ->with(['proforma.scheme', 'user'])
                ->get();

            $total_received  = $apps->count();
            $approved_statuses  = ['noc_issued', 'claim_approved_by_gm', 'claim_approved_by_slc', 'approved_by_da'];
            $disbursed_statuses = ['claim_approved_by_slc', 'claim_approved_by_gm'];
            $total_approved  = $apps->whereIn('workflow_status', $approved_statuses)->count();
            $total_disbursed = $apps->whereIn('workflow_status', $disbursed_statuses)->count();

            $processing_days = $apps->filter(fn($a) => $a->decided_at)
                ->map(fn($a) => $a->submitted_at->diffInDays($a->decided_at))
                ->filter(fn($d) => $d >= 0)
                ->sort()
                ->values();

            $count       = $processing_days->count();
            $avg_time    = $count > 0 ? round($processing_days->avg(), 2) : null;
            $min_time    = $count > 0 ? $processing_days->min() : null;
            $max_time    = $count > 0 ? $processing_days->max() : null;
            $median_time = $this->calculate_median($processing_days);

            // district name lookup (one per code)
            $district_names = TripuraMasterData::whereNotNull('district_code')
                ->select('district_code', 'district_name')
                ->get()
                ->unique('district_code')
                ->pluck('district_name', 'district_code');

            // sector lookup per user
            $sector_map = LineOfActivity::whereIn('user_id', $apps->pluck('user_id')->unique())
                ->select('user_id', 'thrust_sector')
                ->get()
                ->unique('user_id')
                ->pluck('thrust_sector', 'user_id');

            $grouped = $apps->groupBy(fn($a) => implode('|', [
                $a->proforma_id,
                $sector_map[$a->user_id] ?? '',
                $a->user->district_id ?? '',
            ]));

            $table_data = $grouped->values()->map(function ($group, $index) use ($approved_statuses, $disbursed_statuses, $district_names, $sector_map) {
                $first       = $group->first();
                $days        = $group->filter(fn($a) => $a->decided_at)
                    ->map(fn($a) => $a->submitted_at->diffInDays($a->decided_at))
                    ->filter(fn($d) => $d >= 0)
                    ->sort()
                    ->values();

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
                ->get()
                ->unique('district_code')
                ->values();

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
