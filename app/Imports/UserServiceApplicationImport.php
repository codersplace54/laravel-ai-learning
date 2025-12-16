<?php

namespace App\Imports;

use App\Models\ApplicationWorkflowAssignment;
use App\Models\ApplicationWorkflowHistory;
use App\Models\ServiceMaster;
use App\Models\User;
use App\Models\UserServiceApplication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class UserServiceApplicationImport implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];

    protected array $service_id_map = [];
    protected array $user_id_map = [];
    protected array $service_flows_map = [];

    public function __construct()
    {
        $this->service_id_map = ServiceMaster::pluck('id', 'old_id')->toArray();
        $this->user_id_map    = User::pluck('id', 'old_id')->toArray();

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

    public function collection(Collection $rows)
    {
        UserServiceApplication::truncate();
        ApplicationWorkflowAssignment::truncate();
        ApplicationWorkflowHistory::truncate();

        DB::disableQueryLog();

        $app_batch              = [];
        $assignment_batch       = [];
        $history_batch          = [];

        $app_batch_size         = 500;
        $assignment_batch_size  = 2000;
        $history_batch_size     = 2000;

        foreach ($rows as $index => $row) {
            $row_number = $row['#'] ?? ($index + 1);

            $mapped_row = $this->map_row_to_db($row, $row_number);

            if ($mapped_row === null) {
                continue;
            }

            $app_batch[] = $mapped_row;

            $assignments = $this->build_assignments_for_application($mapped_row);
            foreach ($assignments as $a) {
                $assignment_batch[] = $a;
            }

            $history_rows = $this->build_history_for_application($mapped_row);
            foreach ($history_rows as $h) {
                $history_batch[] = $h;
            }

            if (count($app_batch) >= $app_batch_size) {
                DB::table('user_service_applications')->insert($app_batch);
                $app_batch = [];
            }

            if (count($assignment_batch) >= $assignment_batch_size) {
                DB::table('application_workflow_assignments')->insert($assignment_batch);
                $assignment_batch = [];
            }

            if (count($history_batch) >= $history_batch_size) {
                DB::table('application_workflow_history')->insert($history_batch);
                $history_batch = [];
            }
        }

        if (!empty($app_batch)) {
            DB::table('user_service_applications')->insert($app_batch);
        }

        if (!empty($assignment_batch)) {
            DB::table('application_workflow_assignments')->insert($assignment_batch);
        }

        if (!empty($history_batch)) {
            DB::table('application_workflow_history')->insert($history_batch);
        }
    }

    protected function map_row_to_db($row, int $excel_row_number): ?array
    {
        $noc_details_id         = $row['noc_details_id'] ?? null;
        $noc_master_id          = $row['noc_master_id'] ?? null;
        $old_user_id            = $row['old_user_id'] ?? null;
        $application_id         = $row['applicationid'] ?? null;
        $final_fee              = $row['final_fee'] ?? null;
        $payment_status_raw     = strtolower($row['paymentstatus'] ?? '');
        $application_status_raw = $row['application_status'] ?? '';
        $noc_type_raw           = $row['noc_type'] ?? null;
        $noc_cert_url_raw       = $row['noc_certificate'] ?? null;
        $noc_cert_number        = $row['noc_certificate_number'] ?? null;
        $noc_app_date_raw       = $row['noc_application_date'] ?? null;
        $noc_exp_date_raw       = $row['noc_expiry_date'] ?? null;
        $noc_gen_date_raw       = $row['noc_generation_date'] ?? null;
        $app_date_raw           = $row['application_date'] ?? null;

        if (empty($noc_master_id) || empty($noc_details_id)) {
            $this->skipped_rows[] = [
                'row'         => $excel_row_number,
                'noc_id'      => $noc_details_id,
                'old_user_id' => $old_user_id,
                'reason'      => 'missing_required_fields',
            ];
            return null;
        }

        $service_id = $this->service_id_map[$noc_master_id] ?? null;
        if ($service_id === null) {
            $this->skipped_rows[] = [
                'noc_master_id' => $noc_master_id,
                'row'           => $excel_row_number,
                'noc_id'        => $noc_details_id,
                'old_user_id'   => $old_user_id,
                'reason'        => 'service_not_found',
            ];
            return null;
        }

        $user_id = null;
        if (!empty($old_user_id)) {
            $user_id = $this->user_id_map[$old_user_id] ?? null;
            if ($user_id === null) {
                $this->skipped_rows[] = [
                    'row'         => $excel_row_number,
                    'noc_id'      => $noc_details_id,
                    'old_user_id' => $old_user_id,
                    'reason'      => 'user_not_found',
                ];
                return null;
            }
        }

        $payment_map = [
            'unpaid' => 'pending',
            'paid'   => 'success',
        ];
        $payment_status = $payment_map[$payment_status_raw] ?? null;

        $status_key = strtolower(str_replace(' ', '_', $application_status_raw));
        $status_map = [
            'draft'                         => 'draft',
            'noc_issued'                    => 'noc_issued',
            'submitted'                     => 'saved',
            'acknowledged'                  => 'under_review',
            'approved'                      => 'approved',
            'approved_beyond_timeline'      => 'approved',
            'clarification_required'        => 'send_back',
            'extra_payment_paid'            => 're_submitted',
            'extra_payment_raised'          => 'extra_payment',
            'forward_to_approval_authority' => 'under_review',
            'pending_beyond_timeline'       => 'pending',
            're_submitted'                  => 're_submitted',
            'rejected'                      => 'rejected',
        ];
        $status = $status_map[$status_key] ?? 'saved';

        $renewal = null;
        if ($noc_type_raw === 'New' || $noc_type_raw === 'Other') {
            $renewal = 'no';
        } elseif ($noc_type_raw === 'Renewal') {
            $renewal = 'yes';
        }

        $noc_certificate = null;
        if (!empty($noc_cert_url_raw)) {
            $path      = parse_url($noc_cert_url_raw, PHP_URL_PATH);
            $file_name = $path ? basename($path) : null;

            if ($file_name && $user_id) {
                $noc_certificate = "uploads/{$user_id}/application/{$file_name}";
            } elseif ($file_name) {
                $noc_certificate = $file_name;
            } else {
                $noc_certificate = $noc_cert_url_raw;
            }
        }

        $noc_app_date     = $this->parse_date($noc_app_date_raw);
        $noc_expiry_date  = $this->parse_date($noc_exp_date_raw);
        $noc_generation   = $this->parse_datetime($noc_gen_date_raw);
        $application_date = $this->parse_datetime($app_date_raw);

        return [
            'id'                   => (int) $noc_details_id,
            'user_id'              => $user_id,
            'service_id'           => $service_id,
            'renewal'              => $renewal,
            'applicationId'        => $application_id,
            'application_date'     => $application_date,
            'status'               => $status,
            'final_fee'            => $final_fee ?: null,
            'payment_status'       => $payment_status,
            'NOC_application_date' => $noc_app_date,
            'NOC_expiry_date'      => $noc_expiry_date,
            'NOC_certificate'      => $noc_certificate,
            'NOC_letter_number'    => $noc_cert_number ?: null,
            'NOC_letter_date'      => $noc_app_date,
            'NOC_generationDate'   => $noc_generation,
        ];
    }

    protected function build_assignments_for_application(array $app_row): array
    {
        $application_id = $app_row['id'] ?? null;
        $service_id     = $app_row['service_id'] ?? null;
        $app_status     = $app_row['status'] ?? null;

        if (!$application_id || !$service_id) {
            return [];
        }

        if (!isset($this->service_flows_map[$service_id])) {
            return [];
        }

        $status_to_step_type = [
            'saved'         => 'validation',
            'pending'       => 'validation',
            're_submitted'  => 'validation',
            'extra_payment' => 'validation',
            'send_back'     => 'validation',
            'under_review'  => 'review',
            'approved'      => 'approval',
            'rejected'      => 'approval',
            'noc_issued'    => 'approval',
        ];

        $app_status_to_assignment = [
            'saved'         => 'saved',
            'pending'       => 'pending',
            're_submitted'  => 're_submitted',
            'extra_payment' => 'extra_payment',
            'send_back'     => 'send_back',
            'under_review'  => 'in_progress',
            'approved'      => 'approved',
            'rejected'      => 'rejected',
            'noc_issued'    => 'approved',
        ];

        $active_step_type     = $status_to_step_type[$app_status] ?? null;
        $active_assign_status = $app_status_to_assignment[$app_status] ?? 'pending';

        $now   = Carbon::now();
        $rows  = [];
        $flows = $this->service_flows_map[$service_id];

        foreach ($flows as $flow) {
            $step_status = 'pending';
            if ($active_step_type !== null && $flow->step_type === $active_step_type) {
                $step_status = $active_assign_status;
            }

            $rows[] = [
                'application_id'   => $application_id,
                'service_id'       => $service_id,
                'step_number'      => $flow->step_number,
                'step_type'        => $flow->step_type,
                'department_id'    => $flow->department_id,
                'hierarchy_level'  => $flow->hierarchy_level,
                'status'           => $step_status,
                'action_taken_by'  => null,
                'action_taken_at'  => null,
                'remarks'          => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        return $rows;
    }

    protected function build_history_for_application(array $app_row): array
    {
        $application_id = $app_row['id'] ?? null;
        $service_id     = $app_row['service_id'] ?? null;
        $app_status     = $app_row['status'] ?? null;

        if (!$application_id || !$service_id) {
            return [];
        }

        if (!isset($this->service_flows_map[$service_id])) {
            return [];
        }

        $status_to_step_type = [
            'saved'         => 'validation',
            'pending'       => 'validation',
            're_submitted'  => 'validation',
            'extra_payment' => 'validation',
            'send_back'     => 'validation',
            'under_review'  => 'review',
            'approved'      => 'approval',
            'rejected'      => 'approval',
            'noc_issued'    => 'approval',
        ];

        $app_status_to_history = [
            'saved'         => 'saved',
            'pending'       => 'pending',
            're_submitted'  => 're_submitted',
            'extra_payment' => 'extra_payment',
            'send_back'     => 'send_back',
            'under_review'  => 'in_progress',
            'approved'      => 'approved',
            'rejected'      => 'rejected',
            'noc_issued'    => 'approved',
        ];

        $active_step_type  = $status_to_step_type[$app_status] ?? null;
        $history_status    = $app_status_to_history[$app_status] ?? null;

        if ($active_step_type === null || $history_status === null) {
            return [];
        }

        $flows = $this->service_flows_map[$service_id];

        $action_time = null;
        if (in_array($app_status, ['approved', 'rejected', 'noc_issued'], true)) {
            $action_time = $app_row['NOC_generationDate'] ?? null;
        } else {
            $action_time = $app_row['application_date'] ?? null;
        }

        foreach ($flows as $flow) {
            if ($flow->step_type === $active_step_type) {
                $now = Carbon::now();

                return [[
                    'application_id'         => $application_id,
                    'service_id'             => $service_id,
                    'step_number'            => $flow->step_number,
                    'step_type'              => $flow->step_type,
                    'department_id'          => $flow->department_id,
                    'hierarchy_level'        => $flow->hierarchy_level,
                    'action_taken_by'        => null,
                    'action_taken_at'        => $action_time,
                    'status'                 => $history_status,
                    'status_file'            => null,
                    'remarks'                => null,
                    'external_status'        => null,
                    'external_payment_amount'=> null,
                    'external_payment_status'=> null,
                    'external_noc_url'       => null,
                    'external_noc_file'      => null,
                    'source'                 => 'native',
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ]];
            }
        }

        return [];
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
