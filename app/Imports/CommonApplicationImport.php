<?php

namespace App\Imports;

use App\Models\ServiceMaster;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CommonApplicationImport implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];
    public array $assignment_skipped_rows = [];

    protected array $service_id_map = [];
    protected array $user_id_map = [];
    protected array $service_flows_map = [];
    protected array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->service_id_map = ServiceMaster::pluck('id', 'old_id')->toArray();
        $this->user_id_map = User::pluck('id', 'old_id')->toArray();

        $flows = DB::table('service_approval_flows')
            ->select('service_id', 'step_number', 'step_type', 'department_id', 'hierarchy_level')
            ->orderBy('service_id')
            ->orderBy('step_number')
            ->get();

        $grouped = [];
        foreach ($flows as $flow) {
            $grouped[$flow->service_id][] = $flow;
        }
        $this->service_flows_map = $grouped;
    }

    protected function getDefaultConfig(): array
    {
        return [
            'service_id_mapping' => [
                13648 => 20,
                21298 => 26,
                37458 => 28,
                37459 => 32,
                37460 => 34,
                13562 => 30,
                35490 => 39,
                19499 => 40,
                61686 => 42,
                29943 => 43,
                28436 => 52,
                30085 => 53,
                13943 => 54,
                13945 => 55,
                13944 => 56,
                13639 => 16,
                13946 => 19,
                13947 => 23,
                13953 => 24,
                13954 => 25,
                29310 => 29,
                29311 => 31,
                29312 => 33,
                29313 => 35,
                29314 => 36,
                36197 => 37,
                52636 => 38,
                20692 => 27,
            ],
            'payment_status_mapping' => [
                'unpaid' => 'pending',
                'paid' => 'paid',
            ],
            'application_status_mapping' => [
                'draft' => 'draft',
                'noc_issued' => 'noc_issued',
                'submitted' => 'submitted',
                'acknowledged' => 'under_review',
                'approved' => 'approved',
                'approved_beyond_timeline' => 'approved',
                'clarification_required' => 'send_back',
                'extra_payment_paid' => 're_submitted',
                'extra_payment_raised' => 'extra_payment',
                'forward_to_approval_authority' => 'under_review',
                'pending_beyond_timeline' => 'pending',
                're_submitted' => 're_submitted',
                'rejected' => 'rejected',
            ],
            'file_path_format' => 'sites/default/files/%s',
        ];
    }

    public function collection(Collection $rows)
    {
        DB::disableQueryLog();

        $service_application_batch = [];
        $batch_size = 500;

        foreach ($rows as $index => $row) {
            $row_number = $row['#'] ?? ($index + 1);

            $mapped_row = $this->map_application_row($row, $row_number);
            if ($mapped_row === null) {
                continue;
            }

            $service_application_batch[] = $mapped_row;

            if (count($service_application_batch) >= $batch_size) {
                $this->save_applications_and_prepare_assignment_history($service_application_batch);
                $service_application_batch = [];
            }
        }

        if (!empty($service_application_batch)) {
            $this->save_applications_and_prepare_assignment_history($service_application_batch);
        }
    }

    protected function map_application_row($row): ?array
    {
        $noc_details_id = $row['noc_details_id'] ?? null;
        $noc_master_id = $row['noc_master_id'] ?? null;
        $old_user_id = $row['old_user_id'] ?? null;
        $application_id = $row['applicationid'] ?? null;
        $final_fee = $row['final_fee'] ?? null;
        $payment_status_raw = strtolower($row['paymentstatus'] ?? '');
        $application_status_raw = $row['application_status'] ?? '';
        $noc_type_raw = $row['noc_type'] ?? null;
        $noc_cert_url_raw = $row['noc_certificate'] ?? null;
        $noc_cert_number = $row['noc_certificate_number'] ?? null;
        $noc_app_date_raw = $row['noc_application_date'] ?? null;
        $noc_exp_date_raw = $row['noc_expiry_date'] ?? null;
        $noc_gen_date_raw = $row['noc_generation_date'] ?? null;
        $app_date_raw = $row['application_date'] ?? null;
        $noc_mode = $row['noc_mode'] ?? null;

        if (empty($noc_master_id) || empty($noc_details_id)) {
            $this->skipped_rows[] = [
                'noc_id' => $noc_details_id,
                'old_user_id' => $old_user_id,
                'reason_key' => 'missing_required_fields',
                'reason' => 'Missing required fields (noc_master_id / noc_details_id)',
            ];
            return null;
        }

        $service_id = $this->service_id_map[$noc_master_id] ?? null;
        
        if ($service_id === null) {
            $service_id = $this->config['service_id_mapping'][$noc_master_id] ?? null;
        }

        if ($service_id === null) {
            $this->skipped_rows[] = [
                'noc_master_id' => $noc_master_id,
                'noc_id' => $noc_details_id,
                'old_user_id' => $old_user_id,
                'reason_key' => 'service_not_found',
                'reason' => 'Service not found for NOC_master_ID: ' . $noc_master_id,
            ];
            return null;
        }

        $user_id = null;
        if (!empty($old_user_id)) {
            $user_id = $this->user_id_map[$old_user_id] ?? null;
            if ($user_id === null) {
                $this->skipped_rows[] = [
                    'noc_id' => $noc_details_id,
                    'old_user_id' => $old_user_id,
                    'noc_master_id' => $noc_master_id,
                    'reason_key' => 'user_not_found',
                    'reason' => 'User not found',
                ];
                return null;
            }
        }

        $payment_status = $this->config['payment_status_mapping'][$payment_status_raw] ?? null;

        $status_key = strtolower(str_replace([' ', '-'], '_', $application_status_raw));
        $status = $this->config['application_status_mapping'][$status_key] ?? null;

        if ($status === null) {
            $this->skipped_rows[] = [
                'noc_id' => $noc_details_id,
                'old_user_id' => $old_user_id,
                'noc_master_id' => $noc_master_id,
                'reason_key' => 'status_not_mapped',
                'reason' => 'Status not mapped: ' . $application_status_raw,
                'raw_status' => $application_status_raw,
            ];
            return null;
        }

        $renewal = null;
        if ($noc_type_raw === 'New' || $noc_type_raw === 'Other') {
            $renewal = 'no';
        } elseif ($noc_type_raw === 'Renewal') {
            $renewal = 'yes';
        }

        $noc_certificate = null;
        if (!empty($noc_cert_url_raw)) {
            if (str_starts_with($noc_cert_url_raw, 'http')) {
                $path = parse_url($noc_cert_url_raw, PHP_URL_PATH);
                $noc_certificate = preg_replace('#^/(?:new/)?storage/#', '', $path);
            } else {
                $noc_certificate = $noc_cert_url_raw;
            }
        }

        $noc_app_date = $this->parse_date($noc_app_date_raw);
        $noc_expiry_date = $this->parse_date($noc_exp_date_raw);
        $noc_generation = $this->parse_datetime($noc_gen_date_raw);
        $application_date = $this->parse_datetime($app_date_raw);

        return [
            'old_id' => (int) $noc_details_id,
            'user_id' => $user_id,
            'service_id' => $service_id,
            'renewal' => $renewal,
            'applicationId' => $application_id,
            'application_date' => $application_date,
            'status' => $status,
            'final_fee' => $final_fee ?: null,
            'payment_status' => $payment_status,
            'NOC_application_date' => $noc_app_date,
            'NOC_expiry_date' => $noc_expiry_date,
            'NOC_certificate' => $noc_certificate,
            'license_id' => $noc_cert_number ?: null,
            'NOC_letter_date' => $noc_app_date,
            'NOC_generationDate' => $noc_generation,
            'NOC_mode' => $noc_mode,
        ];
    }

    protected function save_applications_and_prepare_assignment_history(array $service_applications_batch): void
    {
        if (empty($service_applications_batch)) {
            return;
        }

        // Filter out existing records by old_id
        $old_ids = array_column($service_applications_batch, 'old_id');
        $existing_old_ids = DB::table('user_service_applications')
            ->whereIn('old_id', $old_ids)
            ->pluck('old_id')
            ->toArray();

        $new_applications = array_filter($service_applications_batch, function ($app) use ($existing_old_ids) {
            return !in_array($app['old_id'], $existing_old_ids);
        });

        if (empty($new_applications)) {
            return;
        }

        DB::table('user_service_applications')->insert($new_applications);

        $new_old_ids = array_column($new_applications, 'old_id');
        $application_id_map = DB::table('user_service_applications')
            ->whereIn('old_id', $new_old_ids)
            ->pluck('id', 'old_id')
            ->toArray();

        $assignment_rows = [];
        $history_rows = [];

        foreach ($new_applications as $application) {
            $old_id = $application['old_id'] ?? null;

            if (!$old_id || !isset($application_id_map[$old_id])) {
                continue;
            }

            $application['id'] = $application_id_map[$old_id];

            $app_status = $application['status'] ?? null;
            if (in_array($app_status, ['draft', 'noc_issued', 'approved', 'rejected'], true)) {
                $this->assignment_skipped_rows[] = [
                    'old_id' => $application['old_id'] ?? null,
                    'service_id' => $application['service_id'] ?? null,
                    'status' => $app_status,
                    'reason' => 'ignored_due_to_status',
                ];
                continue;
            }

            foreach ($this->build_assignments_for_application($application) as $a) {
                $assignment_rows[] = $a;
            }

            foreach ($this->build_history_for_application($application) as $h) {
                $history_rows[] = $h;
            }
        }

        foreach (array_chunk($assignment_rows, 2000) as $chunk) {
            DB::table('application_workflow_assignments')->insert($chunk);
        }

        foreach (array_chunk($history_rows, 2000) as $chunk) {
            DB::table('application_workflow_history')->insert($chunk);
        }
    }

    protected function build_assignments_for_application(array $app_row): array
    {
        $application_id = $app_row['id'] ?? null;
        $service_id = $app_row['service_id'] ?? null;
        $app_status = $app_row['status'] ?? null;

        if (!$application_id || !$service_id) {
            return [];
        }

        if (!isset($this->service_flows_map[$service_id])) {
            $this->assignment_skipped_rows[] = [
                'old_id' => $app_row['old_id'] ?? null,
                'service_id' => $service_id,
                'status' => $app_status,
                'reason' => 'service_flow_not_found',
            ];
            return [];
        }

        $app_status_to_assignment = [
            'submitted' => 'saved',
            'pending' => 'pending',
            're_submitted' => 're_submitted',
            'extra_payment' => 'extra_payment',
            'send_back' => 'send_back',
            'under_review' => 'in_progress',
        ];

        $now = Carbon::now();
        $flows = $this->service_flows_map[$service_id] ?? [];
        $first_step_flow = $flows[0] ?? null;

        if (!$first_step_flow) {
            $this->assignment_skipped_rows[] = [
                'old_id' => $app_row['old_id'] ?? null,
                'service_id' => $service_id,
                'status' => $app_status,
                'reason' => 'no_steps_found_for_service',
            ];
            return [];
        }

        return [[
            'application_id' => $application_id,
            'service_id' => $service_id,
            'step_number' => $first_step_flow->step_number,
            'step_type' => $first_step_flow->step_type,
            'department_id' => $first_step_flow->department_id,
            'hierarchy_level' => !empty($first_step_flow->hierarchy_level) ? $first_step_flow->hierarchy_level : null,
            'status' => $app_status_to_assignment[$app_status] ?? 'pending',
            'action_taken_by' => null,
            'action_taken_at' => null,
            'remarks' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]];
    }

    protected function build_history_for_application(array $app_row): array
    {
        $application_id = $app_row['id'] ?? null;
        $service_id = $app_row['service_id'] ?? null;
        $app_status = $app_row['status'] ?? null;

        if (!$application_id || !$service_id || !isset($this->service_flows_map[$service_id])) {
            return [];
        }

        $app_status_to_history = [
            'submitted' => 'saved',
            'pending' => 'pending',
            're_submitted' => 'approved',
            'extra_payment' => 'extra_payment',
            'send_back' => 'send_back',
            'under_review' => 'in_progress',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'noc_issued' => 'approved',
        ];

        $flows = $this->service_flows_map[$service_id] ?? [];
        $first_step_flow = $flows[0] ?? null;

        if (!$first_step_flow) {
            return [];
        }

        $action_time = in_array($app_status, ['approved', 'rejected', 'noc_issued'], true)
            ? ($app_row['NOC_generationDate'] ?? null)
            : ($app_row['application_date'] ?? null);

        return [[
            'application_id' => $application_id,
            'service_id' => $service_id,
            'step_number' => $first_step_flow->step_number,
            'step_type' => $first_step_flow->step_type,
            'department_id' => $first_step_flow->department_id,
            'hierarchy_level' => !empty($first_step_flow->hierarchy_level) ? $first_step_flow->hierarchy_level : null,
            'action_taken_by' => null,
            'action_taken_at' => $action_time,
            'status' => $app_status_to_history[$app_status] ?? null,
            'status_file' => null,
            'remarks' => null,
            'external_status' => null,
            'external_payment_amount' => null,
            'external_payment_status' => null,
            'external_noc_url' => null,
            'external_noc_file' => null,
            'source' => 'native',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]];
    }

    protected function parse_date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function parse_datetime(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
