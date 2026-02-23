<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\UserServiceApplication;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncSaralData extends Command
{
    protected $signature = 'saral:sync';
    protected $description = 'Sync application data with Saral system';

    private $saral_api_url = 'https://uat.tripura.gov.in/SaralTracking/api/values/SaralBulkUpload';

    private $service_code_mapping = [
        16 => '01',
        19 => '02',
        23 => '03',
        24 => '04',
        27 => '05',
    ];

    private $status_mapping = [
        'pending' => 'E',
        'approved' => 'A',
        'in_progress' => 'H',
        'rejected' => 'R',
        'saved' => 'U',
        'send_back' => 'X', 
        'noc_issued' => 'A', 
        
    ];

    public function handle()
    {
        $this->info('Starting Saral sync...');

        $applications = UserServiceApplication::with(['user', 'service.department'])
            ->whereHas('service', function ($q) {
                $q->where('department_id', 6);
            })
            ->whereIn('service_id', array_keys($this->service_code_mapping))
            ->limit(10)
            ->get();

        if ($applications->isEmpty()) {
            $this->warn('No applications found to sync.');
            return 0;
        }

        $saral_data = [];
        $errors = [];

        foreach ($applications as $app) {
            try {
                $data = $this->prepare_saral_data($app);
                if ($data) {
                    $saral_data[] = $data;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'application_id' => $app->id,
                    'error' => $e->getMessage()
                ];
                $this->error("Error processing application {$app->id}: {$e->getMessage()}");
            }
        }

        if (empty($saral_data)) {
            $this->error('No valid data to push to Saral.');
            return 1;
        }

        try {
            Log::info('SARAL_BULK_UPLOAD_REQUEST', [
                'url'   => $this->saral_api_url,
                'count' => is_array($saral_data) ? count($saral_data) : null,
                'payload_preview' => is_array($saral_data) ? array_slice($saral_data, 0, 2) : null,
            ]);
            $response = Http::post($this->saral_api_url, $saral_data);

            Log::info('SARAL_BULK_UPLOAD_RESPONSE', [
                'status'  => $response->status(),
                'ok'      => $response->successful(),
                'headers' => $response->headers(),
                'body'    => $response->body(),          
                'json'    => $response->json(),        
            ]);

            $this->info("Successfully pushed {$applications->count()} applications to Saral.");

            if (!empty($errors)) {
                $this->warn("Failed to process " . count($errors) . " applications.");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to push data to Saral: {$e->getMessage()}");
            return 1;
        }
    }

    private function prepare_saral_data($application)
    {
        $user = $application->user;
        $service = $application->service;

        if (!$user || !$service) {
            throw new \Exception("User or Service not found for application ID: {$application->id}");
        }

        $service_code = $this->service_code_mapping[$application->service_id] ?? null;

        $last_workflow_history = $application->workflowHistory()->orderBy('id', 'desc')->first();
        $last_workflow_assignment = $application->workflow()->orderBy('id', 'desc')->first();

        $last_status_description = $last_workflow_history->status ?? 'NA';
        $last_action_date = $last_workflow_history->action_taken_at ?? $application->application_date;
        $last_action_by = $last_workflow_history->actionTaker->authorized_person_name ?? 'System';
        $remarks_eng = $last_workflow_history->remarks ?? 'NA';
        $level = $last_workflow_assignment->step_type ?? null;
        $file_with_user = $last_workflow_assignment->hierarchy_level ?? 'NA';

        $last_action = $this->status_mapping[$last_workflow_history->status ?? 'pending'] ?? 'E';

        return [
            'DeptCode' => 'LAB',
            'ApplicationCode' => '04',
            'ServiceCode' => $service_code,
            'SubserviceCode' => '',
            'FileReferenceNo' => $application->applicationId,
            'ReceiptDate' => $this->format_datetime($application->application_date),
            'Name' => $this->sanitize_text($user->name_of_enterprise),
            'Father_HusbandName' => '',
            'gender' => '',
            'Address' => $this->sanitize_text($user->registered_enterprise_address),
            'MobileNo' => $user->mobile_no,
            'email_id' => $user->email_id ?? '',
            'RTSDueDate' => '',
            'DistrictCode' => $user->district_id,
            'LocationCode' => $user->district_id,
            'LocationType' => $user->district_id,
            'LocationName' => 'DIS',
            'SourceCode' => 8,
            'LastStatusDescription' => $this->sanitize_text($last_status_description),
            'LastAction' => $last_action,
            'LastActionBy' => $this->sanitize_text($last_action_by),
            'LastActionDate' => $this->format_datetime($last_action_date),
            'Remarks_Eng' => $this->sanitize_text($remarks_eng),
            'Level' => $level,
            'FileWithUser' => $this->sanitize_text($file_with_user),
        ];
    }

    private function format_datetime($date)
    {
        if (!$date) {
            return '';
        }

        try {
            return Carbon::parse($date)->format('d/m/Y H:i:s');
        } catch (\Exception $e) {
            return '';
        }
    }

    private function sanitize_text($text)
    {
        if (empty($text)) {
            return 'NA';
        }

        $text = preg_replace('/[^A-Za-z0-9\s_.,:\/&()\-]/', '', $text);
        return trim($text);
    }

}
