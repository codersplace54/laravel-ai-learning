<?php

namespace App\Imports;

use App\Models\Proforma;
use App\Models\User;
use App\Models\UserIncentiveApplication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class UserIncentiveApplicationImport implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];

    protected array $user_id_map = [];
    protected array $proforma_map = [];

    public function __construct()
    {
        $this->user_id_map = User::pluck('id', 'old_id')->toArray();
        
        $this->proforma_map = Proforma::with('scheme')
            ->get()
            ->mapWithKeys(function ($proforma) {
                return [
                    strtolower($proforma->title) => [
                        'id' => $proforma->id,
                        'scheme_id' => $proforma->scheme_id,
                    ]
                ];
            })
            ->toArray();
    }

    public function collection(Collection $rows)
    {
        DB::disableQueryLog();

        $application_batch = [];
        $batch_size = 500;

        foreach ($rows as $index => $row) {
            $row_number = $row['#'] ?? ($index + 1);

            $mapped_row = $this->map_user_incentive_application($row, $row_number);
            if ($mapped_row === null) {
                continue;
            }

            $application_batch[] = $mapped_row;

            if (count($application_batch) >= $batch_size) {
                $this->save_applications($application_batch);
                $application_batch = [];
            }
        }

        if (!empty($application_batch)) {
            $this->save_applications($application_batch);
        }
    }

    protected function save_applications(array $applications_batch): void
    {
        if (empty($applications_batch)) {
            return;
        }

        $old_ids = array_column($applications_batch, 'old_id');
        $existing_old_ids = DB::table('user_incentive_applications')
            ->whereIn('old_id', $old_ids)
            ->pluck('old_id')
            ->toArray();

        $filtered_batch = [];
        foreach ($applications_batch as $app) {
            if (in_array($app['old_id'], $existing_old_ids)) {
                $this->skipped_rows[] = [
                    'row' => $app['row_number'] ?? 'N/A',
                    'nid' => $app['old_id'],
                    'user_id' => $app['user_id'] ?? 'N/A',
                    'reason' => 'Duplicate entry (already exists)',
                ];
            } else {
                unset($app['row_number']);
                $filtered_batch[] = $app;
            }
        }

        if (empty($filtered_batch)) {
            return;
        }

        DB::table('user_incentive_applications')->insert($filtered_batch);
    }

    protected function map_user_incentive_application($row, int $row_number): ?array
    {
        $nid = $row['nid'] ?? null;
        $user_id_old = $row['userid'] ?? null;
        $form_name = $row['form_name'] ?? null;
        $certificate_number = $row['certificate_number'] ?? null;
        $certificate_upload_date_raw = $row['certificate_upload_date'] ?? null;
        $application_date_raw = $row['date_of_application'] ?? null;
        $application_number = $row['application_number'] ?? null;
        $certificate_file = $row['certificate_file'] ?? null;
        $completion_date_raw = $row['completion_date'] ?? null;
        $status_raw = $row['status'] ?? null;

        if (empty($nid) || empty($user_id_old)) {
            $this->skipped_rows[] = [
                'row' => $row_number,
                'nid' => $nid,
                'user_id' => $user_id_old,
                'reason' => 'Missing required fields (NID or UserID)',
            ];
            return null;
        }

        $user_id = $this->user_id_map[$user_id_old] ?? null;
        if ($user_id === null) {
            $this->skipped_rows[] = [
                'row' => $row_number,
                'nid' => $nid,
                'user_id' => $user_id_old,
                'reason' => 'User not found',
            ];
            return null;
        }

        if (empty($form_name)) {
            $this->skipped_rows[] = [
                'row' => $row_number,
                'nid' => $nid,
                'user_id' => $user_id_old,
                'reason' => 'Form name is empty',
            ];
            return null;
        }

        $form_name_lower = strtolower($form_name);
        $proforma_data = $this->proforma_map[$form_name_lower] ?? null;

        if ($proforma_data === null) {
            $this->skipped_rows[] = [
                'row' => $row_number,
                'nid' => $nid,
                'user_id' => $user_id_old,
                'form_name' => $form_name,
                'reason' => 'Proforma not found',
            ];
            return null;
        }

        $proforma_id = $proforma_data['id'];
        $scheme_id = $proforma_data['scheme_id'];

        $workflow_status = $this->map_status($status_raw);
        if ($workflow_status === null) {
            $this->skipped_rows[] = [
                'row' => $row_number,
                'nid' => $nid,
                'user_id' => $user_id_old,
                'status' => $status_raw,
                'reason' => 'Status not mapped',
            ];
            return null;
        }

        $certificate_upload_date = $this->parse_date($certificate_upload_date_raw);
        $application_date = $this->parse_date($application_date_raw);
        $completion_date = $this->parse_date($completion_date_raw);
        $certificate_path = $this->build_certificate_path($certificate_file);

        $now = Carbon::now();

        return [
            'row_number' => $row_number,
            'old_id' => (int) $nid,
            'user_id' => $user_id,
            'scheme_id' => $scheme_id,
            'application_no' => $application_number,
            'proforma_id' => $proforma_id,
            'application_type' => 'eligibility',
            'application_date' => $application_date,
            'workflow_status' => $workflow_status,
            'eligibility_certificate_no' => $certificate_number ?: null,
            'eligibility_certificate_path' => $certificate_path,
            'certificate_upload_date' => $certificate_upload_date,
            'completion_date' => $completion_date,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    protected function map_status(?string $status_raw): ?string
    {

        $status_lower = strtolower(trim($status_raw));

        $status_map = [
            'draft' => 'draft',
            'eligibility certificate issued' => 'approved_by_gm',
            'forwarded to dic gm' => 'submitted',
            'query raised by dic district' => 'submitted',
        ];

        return $status_map[$status_lower] ?? 'submitted';
    }

    protected function build_certificate_path(?string $file_path): ?string
    {
        if ($file_path === null || $file_path === '') {
            return null;
        }

        $file_path = trim($file_path);
        
        if (str_contains($file_path, 'sites/default/files/')) {
            $pos = strpos($file_path, 'sites/default/files/');
            return substr($file_path, $pos);
        }

        return ltrim($file_path, '/');
    }

    protected function parse_date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        if (is_numeric($value)) {
            try {
                $excel_date = (int)$value;
                $unix_date = ($excel_date - 25569) * 86400;
                return date('Y-m-d', $unix_date);
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            return Carbon::createFromFormat('d-m-Y', $value)->format('Y-m-d');
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable $e2) {
                return null;
            }
        }
    }
}
