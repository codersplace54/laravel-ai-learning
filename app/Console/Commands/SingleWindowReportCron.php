<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\UserServiceApplication;
use App\Models\ServiceMaster;
use App\Models\SingleWindowReport;

class SingleWindowReportCron extends Command
{
    protected $signature   = 'report:single-window-native';
    protected $description = 'Compute native single window report and upsert into single_window_reports table per service';

    public function handle()
    {
        Log::channel('single_window_report')->info('SingleWindowReportCron started');

        $updated_ids = [];

        $applications = UserServiceApplication::query()
            ->select(
                'service_id',
                DB::raw('COUNT(*) as total_received'),
                DB::raw("SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as total_processed"),
                DB::raw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as total_approved"),
                DB::raw('AVG(CASE WHEN status = "approved" THEN approved_fee END) as avg_fee')
            )
            ->whereNotNull('application_date')
            ->where('is_third_party',0)
            ->groupBy('service_id')
            ->get();

        foreach ($applications as $row) {
            try {
                $service = ServiceMaster::find($row->service_id);

                $approvedApps = UserServiceApplication::where('service_id', $row->service_id)
                    ->where('status', 'approved')
                    ->whereNotNull('NOC_generationDate')
                    ->whereNotNull('application_date')
                    ->get(['application_date', 'NOC_generationDate']);

                $durations = $approvedApps->map(function ($a) {
                    return (strtotime($a->NOC_generationDate) - strtotime($a->application_date)) / 86400;
                })->filter();

                $median_time = 0;
                $cnt         = $durations->count();

                if ($cnt > 0) {
                    $sorted      = $durations->sort()->values();
                    $middle      = floor(($cnt - 1) / 2);
                    $median_time = $cnt % 2
                        ? $sorted[$middle]
                        : ($sorted[$middle] + $sorted[$middle + 1]) / 2;
                }

                SingleWindowReport::updateOrCreate(
                    ['type' => 'native', 'service_id' => $row->service_id],
                    [
                        'total_received'         => (int) $row->total_received,
                        'total_processed'        => (int) $row->total_processed,
                        'total_approved'         => (int) $row->total_approved,
                        'max_time_to_approve'    => round($durations->max() ?? 0, 2),
                        'min_time_to_approve'    => round($durations->min() ?? 0, 2),
                        'avg_time_to_approve'    => round($durations->avg() ?? 0, 2),
                        'median_time_to_approve' => round($median_time, 2),
                        'avg_fee'                => round($row->avg_fee ?? 0, 2),
                    ]
                );

            } catch (\Exception $e) {
                $failed_ids[] = $row->service_id;
                Log::channel('single_window_report')->error("SingleWindowReportCron: failed for service #{$row->service_id} — " . $e->getMessage());
            }
        }

        Log::channel('single_window_report')->info('SingleWindowReportCron completed', [
            'updated'     => count($updated_ids),
        ]);

        $this->info('Single window report cron done.');

        return Command::SUCCESS;
    }
}
