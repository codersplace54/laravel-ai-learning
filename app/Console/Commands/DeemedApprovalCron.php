<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\UserServiceApplication;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\ApplicationWorkflowHistory;
use App\Http\Controllers\Service\CertificateController;
use Carbon\Carbon;

class DeemedApprovalCron extends Command
{
    protected $signature = 'deemed:approve';
    protected $description = 'Auto-approve applications where deemed approval is enabled and max_processing_date has passed';

    public function handle()
    {
        Log::channel('deemed_approval')->info('DeemedApprovalCron started');

        $applications = UserServiceApplication::with('service')
            ->whereIn('status', ['submitted', 're_submitted'])
            ->whereNotNull('max_processing_date')
            ->where('max_processing_date', '<', Carbon::now())
            ->whereHas('service', function ($q) {
                $q->where('is_deemed_approval', 1);
            })
            ->get();

        $approved_count = 0;
        $failed_count   = 0;
        $approved_ids   = [];
        $failed_ids     = [];

        foreach ($applications as $application) {
            DB::beginTransaction();
            try {

                $current_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                    ->where('step_number', $application->current_step_number)
                    ->latest('id')
                    ->first();

                if ($current_step) {
                    $current_step->update([
                        'status'          => 'approved',
                        'action_taken_by' => null,
                        'action_taken_at' => now(),
                        'remarks'         => 'Auto approved by system',
                    ]);

                    ApplicationWorkflowHistory::create([
                        'application_id'  => $application->id,
                        'service_id'      => $application->service_id,
                        'step_number'     => $current_step->step_number,
                        'step_type'       => $current_step->step_type,
                        'department_id'   => $current_step->department_id,
                        'hierarchy_level' => $current_step->hierarchy_level,
                        'status'          => 'approved',
                        'action_taken_by' => null,
                        'action_taken_at' => now(),
                        'remarks'         => 'Auto approved by system',
                    ]);
                }

                $application->update(['status' => 'approved']);

                app(CertificateController::class)->auto_generate_certificate($application);

                DB::commit();
                $approved_count++;
                $approved_ids[] = $application->id;
                Log::channel('deemed_approval')->info("DeemedApproval: approved application #{$application->id}");
            } catch (\Exception $e) {
                DB::rollBack();
                $failed_count++;
                $failed_ids[] = $application->id;
                Log::channel('deemed_approval')->error("DeemedApproval: failed for application #{$application->id} — " . $e->getMessage());
            }
        }

        Log::channel('deemed_approval')->info('DeemedApprovalCron completed', [
            'approved'     => $approved_count,
            'approved_ids' => $approved_ids,
            'failed'       => $failed_count,
            'failed_ids'   => $failed_ids,
        ]);
        $this->info("Deemed approval cron done.");
        $this->info("Approved ({$approved_count}): " . (count($approved_ids) ? implode(', ', $approved_ids) : 'none'));
        $this->info("Failed ({$failed_count}): "   . (count($failed_ids)   ? implode(', ', $failed_ids)   : 'none'));

        return Command::SUCCESS;
    }
}
