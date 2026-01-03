<?php

namespace App\Imports;

use App\Models\ServiceMaster;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProfessionTaxCertificateImport implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];
    public array $assignment_skipped_rows = [];
    public array $history_skipped_rows = [];

    protected array $user_id_map = [];
    protected array $service_flows_map = [];
    protected int $service_id = 8;

    public function __construct()
    {
        DB::disableQueryLog();
        $this->user_id_map = User::pluck('id', 'old_id')->toArray();

        $this->service_flows_map = DB::table('service_approval_flows')
            ->where('service_id', $this->service_id)
            ->select('service_id', 'step_number', 'step_type', 'department_id', 'hierarchy_level')
            ->orderBy('service_id')
            ->orderBy('step_number')
            ->get()
            ->groupBy('service_id')
            ->toArray();
    }

    public function collection(Collection $rows)
    {
        $service_application_batch = [];
        $batch_size = 1000;

        foreach ($rows as $index => $row) {
            $row_number = $row['#'] ?? ($index + 1);

            $mapped_row = $this->map_user_service_application_with_data($row, (int) $row_number);

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

    protected function save_applications_and_prepare_assignment_history(array $service_applications_batch): void
    {
        if (empty($service_applications_batch)) {
            return;
        }

        $insert_batch = [];
        foreach ($service_applications_batch as $app) {
            $tmp = $app;
            unset($tmp['excel_row']);
            $insert_batch[] = $tmp;
        }

        DB::table('user_service_applications')->insert($insert_batch);

        $old_ids = [];
        foreach ($service_applications_batch as $application) {
            if (!empty($application['old_id'])) {
                $old_ids[] = (int) $application['old_id'];
            }
        }

        if (empty($old_ids)) {
            return;
        }

        $application_id_map = DB::table('user_service_applications')
            ->whereIn('old_id', $old_ids)
            ->pluck('id', 'old_id')
            ->toArray();

        $assignment_rows = [];
        $history_rows = [];

        foreach ($service_applications_batch as $application) {
            $old_id = $application['old_id'] ?? null;

            if (!$old_id || !isset($application_id_map[$old_id])) {
                continue;
            }

            $application['id'] = $application_id_map[$old_id];
            $app_status = $application['status'] ?? null;

            if (in_array($app_status, ['draft', 'noc_issued', 'approved', 'rejected'], true)) {
                $this->assignment_skipped_rows[] = [
                    'row' => $application['excel_row'] ?? null,
                    'old_id' => $application['old_id'] ?? null,
                    'service_id' => $application['service_id'] ?? null,
                    'status' => $app_status,
                    'reason' => 'ignored_due_to_status',
                ];
                
                foreach ($this->build_history_for_application($application) as $h) {
                    $history_rows[] = $h;
                }
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

    protected function map_user_service_application_with_data($row, int $excel_row_number)
    {
        $noc_details_id = $row['noc_details_id'];
        $old_user_id = $row['old_user_id'];
        $applicationid = $row['applicationid'] ?? null;
        $app_date_raw = $row['application_date'] ?? null;
        $application_status_raw = $row['application_status'] ?? 'draft';
        $payment_status_raw = $row['paymentstatus'] ?? 'unpaid';
        $title = $row['title'] ?? null;
        $final_fee = $row['final_fee'] ?? 0;
        $noc_certificate = $row['noc_certificate'] ?? null;
        $noc_certificate_number = $row['noc_certificate_number'] ?? null;
        $noc_application_date = $row['noc_application_date'] ?? null;
        $noc_expiry_date = $row['noc_expiry_date'] ?? null;
        $noc_generation_date = $row['noc_generation_date'] ?? null;
        $noc_type_raw = $row['noc_type'] ?? null;

        if (empty($noc_details_id)) {
            $this->skipped_rows[] = [
                'row' => $excel_row_number,
                'reason' => 'Missing NOC details ID',
            ];
            return null;
        }

        if (empty($old_user_id)) {
            $this->skipped_rows[] = [
                'row' => $excel_row_number,
                'old_id' => $noc_details_id,
                'old_user_id' => null,
                'reason_key' => 'missing_old_user_id',
                'reason' => 'Missing old user ID',
            ];
            return null;
        }

        $user_id = $this->user_id_map[$old_user_id] ?? null;
        if ($user_id === null) {
            $this->skipped_rows[] = [
                'row' => $excel_row_number,
                'old_id' => $noc_details_id,
                'old_user_id' => $old_user_id,
                'reason_key' => 'user_not_found',
                'reason' => 'User not found for old_user_id',
            ];
            return null;
        }

        $status_key = strtolower(str_replace([' ', '-'], '_', $application_status_raw));
        $status_map = [
            'draft' => 'draft',
            'submitted' => 'saved',
            'acknowledged' => 'under_review',
            'approved' => 'approved',
            'rejected' => 'rejected',
            're_submitted' => 're_submitted',
            'pending' => 'pending',
            'clarification_required' => 'send_back',
            'extra_payment_raised' => 'extra_payment',
            'extra_payment_paid' => 're_submitted',
            'forward_to_approval_authority' => 'under_review',
            'send_back' => 'send_back',
            'noc_issued' => 'noc_issued',
            'under_review' => 'under_review',
        ];

        $status = $status_map[$status_key] ?? 'draft';

        $payment_map = [
            'unpaid' => 'pending',
            'paid' => 'success',
        ];
        $payment_status = $payment_map[$payment_status_raw] ?? 'pending';

        $application_date = $this->parse_datetime($app_date_raw) ?: now();
        $noc_app_date = $this->parse_datetime($noc_application_date);
        $noc_exp_date = $this->parse_datetime($noc_expiry_date);
        $noc_gen_date = $this->parse_datetime($noc_generation_date);

        $corrected_noc_certificate = null;
        if (!empty($noc_certificate)) {
            $path = parse_url($noc_certificate, PHP_URL_PATH);
            $file_name = $path ? basename($path) : null;

            if ($file_name && $user_id) {
                $corrected_noc_certificate = "uploads/{$user_id}/application/{$file_name}";
            } elseif ($file_name) {
                $corrected_noc_certificate = $file_name;
            } else {
                $corrected_noc_certificate = $noc_certificate;
            }
        }

        $renewal = null;
        if ($noc_type_raw === 'New' || $noc_type_raw === 'Other') {
            $renewal = 'no';
        } elseif ($noc_type_raw === 'Renewal') {
            $renewal = 'yes';
        }

        return [
            'old_id' => $noc_details_id,
            'user_id' => $user_id,
            'service_id' => $this->service_id,
            'applicationid' => $applicationid,
            'status' => $status,
            'payment_status' => $payment_status,
            'final_fee' => (float) $final_fee,
            'application_date' => $application_date,
            'renewal' => $renewal,
            'NOC_certificate' => $corrected_noc_certificate,
            'license_id' => $noc_certificate_number,
            'NOC_application_date' => $noc_app_date,
            'NOC_expiry_date' => $noc_exp_date,
            'NOC_generationDate' => $noc_gen_date,
            'created_at' => now(),
            'updated_at' => now(),
            'excel_row' => $excel_row_number,
        ];
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
                'row' => $app_row['excel_row'] ?? null,
                'old_id' => $app_row['old_id'] ?? null,
                'service_id' => $service_id,
                'status' => $app_status,
                'reason' => 'service_flow_not_found',
            ];
            return [];
        }

        $flows = $this->service_flows_map[$service_id] ?? [];
        $first_step_flow = $flows[0] ?? null;

        if (!$first_step_flow) {
            $this->assignment_skipped_rows[] = [
                'row' => $app_row['excel_row'] ?? null,
                'old_id' => $app_row['old_id'] ?? null,
                'service_id' => $service_id,
                'status' => $app_status,
                'reason' => 'first_step_flow_not_found',
            ];
            return [];
        }

        $status_map = [
            'saved' => 'saved',
            'pending' => 'pending',
            're_submitted' => 're_submitted',
            'extra_payment' => 'extra_payment',
            'send_back' => 'send_back',
            'under_review' => 'in_progress',
        ];

        return [[
            'application_id' => $application_id,
            'service_id' => $service_id,
            'step_number' => $first_step_flow->step_number,
            'step_type' => $first_step_flow->step_type,
            'department_id' => $first_step_flow->department_id,
            'hierarchy_level' => $first_step_flow->hierarchy_level,
            'status' => $status_map[$app_status] ?? null,
            'action_taken_by' => null,
            'action_taken_at' => null,
            'remarks' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]];
    }

    protected function build_history_for_application(array $app_row): array
    {
        $application_id = $app_row['id'] ?? null;
        $service_id = $app_row['service_id'] ?? null;
        $app_status = $app_row['status'] ?? null;

        if (!$application_id || !$service_id) {
            $this->history_skipped_rows[] = [
                'row' => $app_row['excel_row'] ?? null,
                'old_id' => $app_row['old_id'] ?? null,
                'service_id' => $service_id,
                'status' => $app_status,
                'reason' => 'missing_application_or_service_id',
            ];
            return [];
        }

        if (!isset($this->service_flows_map[$service_id])) {
            $this->history_skipped_rows[] = [
                'row' => $app_row['excel_row'] ?? null,
                'old_id' => $app_row['old_id'] ?? null,
                'service_id' => $service_id,
                'status' => $app_status,
                'reason' => 'service_flow_not_found',
            ];
            return [];
        }

        $flows = $this->service_flows_map[$service_id] ?? [];
        $first_step_flow = $flows[0] ?? null;

        if (!$first_step_flow) {
            $this->history_skipped_rows[] = [
                'row' => $app_row['excel_row'] ?? null,
                'old_id' => $app_row['old_id'] ?? null,
                'service_id' => $service_id,
                'status' => $app_status,
                'reason' => 'first_step_flow_not_found',
            ];
            return [];
        }

        $status_map = [
            'saved' => 'saved',
            'pending' => 'pending',
            're_submitted' => 'approved',
            'extra_payment' => 'extra_payment',
            'send_back' => 'send_back',
            'under_review' => 'in_progress',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'noc_issued' => 'approved',
        ];

        return [[
            'application_id' => $application_id,
            'service_id' => $service_id,
            'step_number' => $first_step_flow->step_number,
            'step_type' => $first_step_flow->step_type,
            'department_id' => $first_step_flow->department_id,
            'hierarchy_level' => $first_step_flow->hierarchy_level,
            'action_taken_by' => null,
            'action_taken_at' => $app_row['application_date'] ?? null,
            'status' => $status_map[$app_status] ?? 'saved',
            'status_file' => null,
            'remarks' => null,
            'external_status' => null,
            'external_payment_amount' => null,
            'external_payment_status' => null,
            'external_noc_url' => null,
            'external_noc_file' => null,
            'source' => 'native',
            'created_at' => now(),
            'updated_at' => now(),
        ]];
    }

    protected function parse_datetime($date_string)
    {
        if (empty($date_string)) {
            return null;
        }
        
        try {
            return Carbon::parse($date_string);
        } catch (\Exception $e) {
            return null;
        }
    }
}