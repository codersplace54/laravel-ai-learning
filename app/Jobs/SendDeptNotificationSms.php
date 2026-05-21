<?php

namespace App\Jobs;

use App\Models\ApplicationWorkflowAssignment;
use App\Models\DepartmentUser;
use App\Models\User;
use App\Models\UserServiceApplication;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDeptNotificationSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $application_id;
    public $application_number;

    public function __construct(int $application_id, string $application_number)
    {
        $this->application_id = $application_id;
        $this->application_number = $application_number;
    }

    public function handle(): void
    {
        try {
            $application = UserServiceApplication::find($this->application_id);
            if (!$application) {
                Log::channel('sms')->warning("Application not found", ['application_id' => $this->application_id]);
                return;
            }

            $applicant_user = User::find($application->user_id);
            if (!$applicant_user) {
                Log::channel('sms')->warning("Applicant user not found", ['user_id' => $application->user_id]);
                return;
            }

            $latest_assignment = ApplicationWorkflowAssignment::where('application_id', $this->application_id)
                ->latest('id')
                ->first();

            if (!$latest_assignment) {
                Log::channel('sms')->warning("No workflow assignment found", ['application_id' => $this->application_id]);
                return;
            }

            $hierarchy_level = $latest_assignment->hierarchy_level;
            $department_id = $latest_assignment->department_id;

            $dept_users = DepartmentUser::where('department_id', $department_id)
                ->where('hierarchy_level', $hierarchy_level)
                ->where('is_active', 1)
                ->with(['user', 'district'])
                ->get();

            $user_details = [];
            foreach ($dept_users as $dept_user) {
                $should_send = false;

                if ($hierarchy_level === 'block') {
                    $should_send = $applicant_user->ulb_id == $dept_user->block_id;
                } elseif (str_starts_with($hierarchy_level, 'subdivision')) {
                    $should_send = $applicant_user->subdivision_id == $dept_user->subdivision_id;
                } elseif (str_starts_with($hierarchy_level, 'district')) {
                    if (strtolower($dept_user->district->district_name ?? '') === 'west tripura') {
                        $should_send = $applicant_user->district_id == $dept_user->district_id &&
                            $applicant_user->ch_name == $dept_user->ch_name;
                    } else {
                        $should_send = $applicant_user->district_id == $dept_user->district_id;
                    }
                }

                if ($should_send) {
                    $user_details[] = [
                        'user_id' => $dept_user->user_id,
                        'mobile' => $dept_user->user->mobile_no,
                    ];
                }
            }

            $user_details = collect($user_details)->unique('user_id')->values()->toArray();

            $sent_user_ids = [];
            $failed_user_ids = [];

            foreach ($user_details as $user) {
                try {
                    $template = config('sms_templates.dept_notification');
                    $message = str_replace(
                        '{APPLICATION_NUMBER}',
                        $this->application_number,
                        $template['message']
                    );

                    SmsService::send(
                        $user['mobile'],
                        $message,
                        $template['template_id']
                    );

                    $sent_user_ids[] = $user['user_id'];
                } catch (\Exception $e) {
                    $failed_user_ids[] = $user['user_id'];
                    Log::channel('sms')->error("Failed to send SMS to user", [
                        'user_id' => $user['user_id'],
                        'mobile' => $user['mobile'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::channel('sms')->info("SMS notification sent for application", [
                'application_id' => $this->application_id,
                'application_number' => $this->application_number,
                'hierarchy_level' => $hierarchy_level,
                'department_id' => $department_id,
                'total_users_targeted' => count($user_details),
                'sent_user_ids' => $sent_user_ids,
                'failed_user_ids' => $failed_user_ids,
                'total_sent' => count($sent_user_ids),
                'total_failed' => count($failed_user_ids),
            ]);
        } catch (\Exception $e) {
            Log::channel('sms')->error("Error sending SMS notifications", [
                'application_id' => $this->application_id,
                'application_number' => $this->application_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
