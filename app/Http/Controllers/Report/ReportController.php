<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserServiceApplication;
use App\Models\ServiceMaster;
use App\Models\Department;

class ReportController extends Controller
{

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
