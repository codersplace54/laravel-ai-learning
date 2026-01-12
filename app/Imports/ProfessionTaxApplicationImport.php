<?php

namespace App\Imports;

use App\Models\ServiceMaster;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProfessionTaxApplicationImport implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];

    protected array $user_id_map = [];

    public function __construct()
    {
        DB::disableQueryLog();
        $this->user_id_map = User::pluck('id', 'old_id')->toArray();
    }

    public function collection(Collection $rows)
    {
        DB::disableQueryLog();

        $service_application_batch = [];
        $batch_size = 500;

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

        $history_rows = [];

        foreach ($service_applications_batch as $application) {
            $old_id = $application['old_id'] ?? null;

            if (!$old_id || !isset($application_id_map[$old_id])) {
                continue;
            }

            $application['id'] = $application_id_map[$old_id];

            foreach ($this->build_history_for_application($application) as $h) {
                $history_rows[] = $h;
            }
        }

        foreach (array_chunk($history_rows, 2000) as $chunk) {
            DB::table('application_workflow_history')->insert($chunk);
        }
    }

    protected function map_user_service_application_with_data($row, int $excel_row_number): ?array
    {
        $noc_details_id = $row['noc_details_id'] ?? null;
        $old_user_id = $row['old_user_id'] ?? null;
        $application_id = $row['applicationid'] ?? null;
        $app_date_raw = $row['application_date'] ?? null;
        $payment_status_raw = strtolower((string) ($row['paymentstatus'] ?? ''));
        $application_status_raw = (string) ($row['application_status'] ?? '');

        if (empty($noc_details_id)) {
            $this->skipped_rows[] = [
                'row' => $excel_row_number,
                'old_id' => null,
                'old_user_id' => $old_user_id,
                'reason_key' => 'missing_noc_details_id',
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

        $service_id = 12;
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
            'acknowledged' => 'approved',
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

        $status = $status_map[$status_key] ?? 'under_review';

        $payment_status = 'success';
        if (!empty($payment_status_raw)) {
            $payment_map = [
                'unpaid' => 'pending',
                'paid' => 'success',
            ];
            $payment_status = $payment_map[$payment_status_raw] ?? 'pending';
        }

        $created_at = $this->parse_datetime($app_date_raw) ?: now();
        $application_date = $created_at;
        $updated_at = $created_at;

        $noc_type_raw = $row['noc_type'] ?? null;
        $renewal = null;
        if ($noc_type_raw === 'New' || $noc_type_raw === 'Other') {
            $renewal = 'no';
        } elseif ($noc_type_raw === 'Renewal') {
            $renewal = 'yes';
        }

        $noc_certificate = null;
        $noc_cert_url_raw = $row['noc_certificate'] ?? null;
        if (!empty($noc_cert_url_raw)) {
            $path = parse_url($noc_cert_url_raw, PHP_URL_PATH);
            $file_name = $path ? basename($path) : null;

            if ($file_name && $user_id) {
                $noc_certificate = "uploads/{$user_id}/application/{$file_name}";
            } elseif ($file_name) {
                $noc_certificate = $file_name;
            } else {
                $noc_certificate = $noc_cert_url_raw;
            }
        }

        $noc_certificate_number = $row['noc_certificate_number'] ?? null;

        $noc_application_date = $this->parse_datetime($row['noc_application_date'] ?? null);
        $noc_expiry_date = $this->parse_datetime($row['noc_expiry_date'] ?? null);
        $noc_generation_date = $this->parse_datetime($row['noc_generation_date'] ?? null);

        return [
            'excel_row' => $excel_row_number,
            'old_id' => (int) $noc_details_id,
            'user_id' => $user_id,
            'service_id' => $service_id,
            'renewal' => $renewal,
            'applicationId' => $application_id,
            'application_date' => $application_date,
            'status' => $status,
            'final_fee' => $row['final_fee'] ?? null,
            'payment_status' => $payment_status,
            'NOC_application_date' => $noc_application_date,
            'NOC_expiry_date' => $noc_expiry_date,
            'NOC_certificate' => $noc_certificate,
            'license_id' => null,
            'NOC_letter_date' => null,
            'NOC_generationDate' => $noc_generation_date,
            'application_data' => null,
            'created_at' => $created_at,
            'updated_at' => $updated_at,
        ];
    }



    protected function build_history_for_application(array $app_row): array
    {
        $application_id = $app_row['id'] ?? null;
        $service_id = $app_row['service_id'] ?? null;
        $app_status = $app_row['status'] ?? null;

        if (!$application_id || !$service_id) {
            return [];
        }

        $app_status_to_history = [
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

        $history_status = $app_status_to_history[$app_status] ?? 'saved';
        $now = Carbon::now();

        return [[
            'application_id' => $application_id,
            'service_id' => $service_id,
            'step_number' => 1,
            'step_type' => 'validation',
            'department_id' => null,
            'hierarchy_level' => null,
            'action_taken_by' => null,
            'action_taken_at' => $app_row['application_date'] ?? null,
            'status' => $history_status,
            'status_file' => null,
            'remarks' => null,
            'external_status' => null,
            'external_payment_amount' => null,
            'external_payment_status' => null,
            'external_noc_url' => null,
            'external_noc_file' => null,
            'source' => 'native',
            'created_at' => $now,
            'updated_at' => $now,
        ]];
    }

    protected function parse_datetime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)
                )->format('Y-m-d H:i:s');
            }

            $value = trim((string) $value);

            if (preg_match('/^\d{2}-\d{2}-\d{4}\s+\d{1,2}\.\d{2}$/', $value)) {
                return Carbon::createFromFormat('d-m-Y H.i', $value)
                    ->format('Y-m-d H:i:s');
            }

            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $value)) {
                return Carbon::createFromFormat('d-m-Y', $value)
                    ->startOfDay()
                    ->format('Y-m-d H:i:s');
            }

            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
