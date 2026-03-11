<?php

namespace App\Observers;

use App\Models\ApplicationWorkflowAssignment;
use App\Jobs\SendWhatsAppNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ApplicationWorkflowAssignmentObserver
{
    // public function created(ApplicationWorkflowAssignment $assignment)
    // {
    //     try {
    //         $application = $assignment->application;
    //         $department = $assignment->department;
    //         $forwarded_by = $assignment->actionTaker;

    //         if (!$application || !$department) {
    //             return;
    //         }

    //         $application_id = $application->applicationId ?? $application->id;
    //         $service_name = $application->service->service_title_or_description ?? '';
    //         $stage = $assignment->step_type ?? 'Step ' . $assignment->step_number;
    //         $forwarded_by_name = $forwarded_by?->authorized_person_name ?? 'N/A';
    //         $forwarded_on = Carbon::parse($assignment->created_at)->format('d M Y, g:i A');
    //         $button_url = env('APP_FRONTEND_URL') . "/dashboard/service-view/{$application->id}";

    //             SendWhatsAppNotification::dispatch(
    //                 $user->mobile_no,
    //                 'dept_app_forwarded_to_you_v1',
    //                 [
    //                     $application_id,
    //                     $service_name,
    //                     $stage,
    //                     $forwarded_by_name,
    //                     $forwarded_on,
    //                     'url' => $button_url
    //                 ],
    //                 "assignment_id={$assignment->id}"
    //             );
    //         }
    //     } catch (\Exception $e) {
    //         Log::channel('whatsapp')->error('workflow_assignment_notification_failed', [
    //             'assignment_id' => $assignment->id,
    //             'error' => $e->getMessage()
    //         ]);
    //     }
    // }
    
}
