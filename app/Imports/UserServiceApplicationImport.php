<?php

namespace App\Imports;

use App\Models\ServiceMaster;
use App\Models\User;
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

    public function __construct()
    {
        $this->service_id_map = ServiceMaster::pluck('id', 'old_id')->toArray();
        $this->user_id_map    = User::pluck('id', 'old_id')->toArray();
    }

    public function collection(Collection $rows)
    {
        // dd($rows);
        DB::disableQueryLog();

        $batch      = [];
        $batch_size = 500;

        foreach ($rows as $index => $row) {
            $row_number = $row['#'] ?? ($index + 1);

            $mapped_row = $this->map_row_to_db($row, $row_number);

            if ($mapped_row === null) {
                continue;
            }

            $batch[] = $mapped_row;

            if (count($batch) >= $batch_size) {
                DB::table('user_service_applications')->insert($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('user_service_applications')->insert($batch);
        }
    }

    protected function map_row_to_db($row, int $excel_row_number): ?array
    {
        $noc_details_id      = $row['noc_details_id'] ?? null;
        $noc_master_id       = $row['noc_master_id'] ?? null;
        $old_user_id         = $row['old_user_id'] ?? null;
        $application_id      = $row['applicationid'] ?? null;
        $final_fee           = $row['final_fee'] ?? null;
        $payment_status_raw  = strtolower($row['paymentstatus'] ?? '');
        $application_status_raw = $row['application_status'] ?? '';
        $noc_type_raw        = $row['noc_type'] ?? null;
        $noc_cert_url_raw    = $row['noc_certificate'] ?? null;
        $noc_cert_number     = $row['noc_certificate_number'] ?? null;
        $noc_app_date_raw    = $row['noc_application_date'] ?? null;
        $noc_exp_date_raw    = $row['noc_expiry_date'] ?? null;
        $noc_gen_date_raw    = $row['noc_generation_date'] ?? null;
        $app_date_raw        = $row['application_date'] ?? null;

        if (empty($noc_master_id)) {
            
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
                'row'         => $excel_row_number,
                'noc_id'      => $noc_details_id,
                'old_user_id' => $old_user_id,
                'reason'      => 'service_not_found',
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
            'draft'                        => 'draft',
            'noc_issued'                   => 'noc_issued',
            'submitted'                    => 'saved',
            'acknowledged'                 => 'under_review',
            'approved'                     => 'approved',
            'approved_beyond_timeline'     => 'approved',
            'clarification_required'       => 'send_back',
            'extra_payment_paid'           => 're_submitted',
            'extra_payment_raised'         => 'extra_payment',
            'forward_to_approval_authority' => 'under_review',
            'pending_beyond_timeline'      => 'pending',
            're_submitted'                 => 're_submitted',
            'rejected'                     => 'rejected',
        ];
        $status = $status_map[$status_key] ?? 'saved';

        $renewal = null;
        if ($noc_type_raw === 'New') {
            $renewal = 'no';
        } elseif ($noc_type_raw === 'Renewal') {
            $renewal = 'yes';
        }

        $noc_certificate = null;
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

        $noc_app_date     = $this->parse_date($noc_app_date_raw);
        $noc_expiry_date  = $this->parse_date($noc_exp_date_raw);
        $noc_generation   = $this->parse_datetime($noc_gen_date_raw);
        $application_date = $this->parse_datetime($app_date_raw);

        return [
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
