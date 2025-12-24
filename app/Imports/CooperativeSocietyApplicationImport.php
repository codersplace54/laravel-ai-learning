<?php

namespace App\Imports;

use App\Models\ServiceMaster;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CooperativeSocietyApplicationImport implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];
    public array $assignment_skipped_rows = [];

    protected array $service_id_map = [];
    protected array $user_id_map = [];
    protected array $service_flows_map = [];
    protected string $member_section_key = 'Member Details';
    protected int $fixed_noc_master_id = 2;

    protected array $excel_question_id_map = [
        'title'                 => 187, // Name of proposed society
        'employer_email'        => 147, // Email Id
        'area_of_operation'     => 165, // Area of operation
        'authorized_share'      => 191, // Authorize Share Capital
        'class_of_society'      => 148, // Class of Society
        'sub_class_of_society'  => 151, // Sub-Class of Society

        // documents 
        'bank_deposit_slip'         => 281, // Bank Deposit Slip with Certificate of Bank Manager
        'capital_details'           => 183, // Capital with details of shares deposit fees etc.
        'cash_account'              => 279, // Cash Account
        'minute_copy_org_meet'      => 222, // Minute copy of the first organization meeting
        'prescribed_by_laws'        => 278, // Prescribed By-laws (Please use normal PDF, in Portrait, leave some space in the footer area)
        'promoter_voter_card'       => 284, // Voter Identity Card/ Aadhar Card for proof of identity of the promoter members
        'special_form_1'            => 271, // Co-operative Special Form No -1
        'special_form_7'            => 273,
        'three_years_project_report' => 280, // 3 (three) years project report, i.e scheme which would be related with objects of the concerned Proposed Society
        'other_documents'           => 285, // Other Documents

        'class_a_qty'   => 184,
        'class_a_price' => 182,
        'class_b_qty'   => 186,
        'class_b_price' => 185,
        'class_c_qty'   => 190,
        'class_c_price' => 189,

        'no_of_members'          => 0,
        'total_admission_fees'   => 219, // Total Admission Fee
        'total_share_value'      => 218, // Total Share Value

        'society_address' => 210, // Address
        'district_id'     => 118, // District
        'subdivision_id'  => 120, // Subdivision

    ];

    public function __construct()
    {
        DB::disableQueryLog();

        $this->service_id_map = ServiceMaster::query()
            ->whereNotNull('old_id')
            ->where('old_id', '!=', '')
            ->pluck('id', 'old_id')
            ->toArray();

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

        // remove excel_row helper before insert
        $insert_batch = [];
        foreach ($service_applications_batch as $app) {
            $tmp = $app;
            unset($tmp['excel_row']);
            $insert_batch[] = $tmp;
        }

        DB::table('user_service_applications')->insert($insert_batch);

        // old_id => new_id map only for this chunk
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
        $history_rows    = [];

        foreach ($service_applications_batch as $application) {
            $old_id = $application['old_id'] ?? null;

            if (!$old_id || !isset($application_id_map[$old_id])) {
                continue;
            }

            $application['id'] = $application_id_map[$old_id];

            $app_status = $application['status'] ?? null;

            if (in_array($app_status, ['draft', 'noc_issued', 'approved', 'rejected'], true)) {
                $this->assignment_skipped_rows[] = [
                    'row'        => $application['excel_row'] ?? null,
                    'old_id'     => $application['old_id'] ?? null,
                    'service_id' => $application['service_id'] ?? null,
                    'status'     => $app_status,
                    'reason'     => 'ignored_due_to_status',
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

    protected function map_user_service_application_with_data($row, int $excel_row_number): ?array
    {
        $noc_details_id = $row['nid'] ?? null;

        $noc_master_id = $this->fixed_noc_master_id;
        $old_user_id = $row['user_id'] ?? null;

        $application_id = $row['application_id'] ?? null;
        $app_date_raw   = $row['application_date'] ?? null;

        $created_at_raw = $row['created_date'] ?? null;

        $payment_status_raw     = strtolower((string) ($row['paymentstatus'] ?? ''));
        $application_status_raw = (string) ($row['application_status'] ?? '');

        if (empty($noc_details_id)) {
            $this->skipped_rows[] = [
                'row'           => $excel_row_number,
                'old_id'        => null,
                'noc_master_id' => $noc_master_id,
                'old_user_id'   => $old_user_id,
                'reason_key'    => 'missing_noc_details_id',
                'reason'        => 'Missing NOC details ID',
            ];
            return null;
        }

        if (empty($old_user_id)) {
            $this->skipped_rows[] = [
                'row'           => $excel_row_number,
                'old_id'        => $noc_details_id,
                'noc_master_id' => $noc_master_id,
                'old_user_id'   => null,
                'reason_key'    => 'missing_old_user_id',
                'reason'        => 'Missing old user ID',
            ];
            return null;
        }

        // if (empty($application_id)) {
        //     $this->skipped_rows[] = [
        //         'row'           => $excel_row_number,
        //         'old_id'        => $noc_details_id,
        //         'noc_master_id' => $noc_master_id,
        //         'old_user_id'   => $old_user_id,
        //         'reason_key'    => 'missing_application_id',
        //         'reason'        => 'Missing application ID',
        //     ];
        //     return null;
        // }

        $service_id = 2;
        if ($service_id === null) {
            $this->skipped_rows[] = [
                'row'           => $excel_row_number,
                'old_id'        => $noc_details_id,
                'noc_master_id' => $noc_master_id,
                'old_user_id'   => $old_user_id,
                'reason_key'    => 'service_not_found',
                'reason'        => 'Service not found for noc_master_id',
            ];
            return null;
        }

        $user_id = $this->user_id_map[$old_user_id] ?? null;

        if ($user_id === null) {
            $this->skipped_rows[] = [
                'row'           => $excel_row_number,
                'old_id'        => $noc_details_id,
                'noc_master_id' => $noc_master_id,
                'old_user_id'   => $old_user_id,
                'reason_key'    => 'user_not_found',
                'reason'        => 'User not found for old_user_id',
            ];
            return null;
        }

        // if (empty($application_status_raw)) {
        //     $this->skipped_rows[] = [
        //         'row'           => $excel_row_number,
        //         'old_id'        => $noc_details_id,
        //         'noc_master_id' => $noc_master_id,
        //         'old_user_id'   => $old_user_id,
        //         'reason_key'    => 'missing_status',
        //         'reason'        => 'Application status is missing',
        //     ];
        //     return null;
        // }

        $status_key = strtolower(str_replace([' ', '-'], '_', $application_status_raw));

        $status_map = [
            'draft'                         => 'draft',
            'submitted'                     => 'saved',
            'acknowledged'                  => 'under_review',
            'approved'                      => 'approved',
            'rejected'                      => 'rejected',
            're_submitted'                  => 're_submitted',
            'pending'                       => 'pending',
            'clarification_required'        => 'send_back',
            'extra_payment_raised'          => 'extra_payment',
            'extra_payment_paid'            => 're_submitted',
            'forward_to_approval_authority' => 'under_review',
            'send_back'                     => 'send_back',
            'noc_issued'                    => 'noc_issued',
            'under_review'                  => 'under_review',
        ];

        $status = $status_map[$status_key] ?? null;

        // if ($status === null) {
        //     $this->skipped_rows[] = [
        //         'row'           => $excel_row_number,
        //         'old_id'        => $noc_details_id,
        //         'noc_master_id' => $noc_master_id,
        //         'old_user_id'   => $user_id,
        //         'reason_key'    => 'status_not_mapped',
        //         'reason'        => 'Status not mapped',
        //         'raw_status'    => $application_status_raw,
        //     ];
        //     return null;
        // }

        $payment_status = 'paid';
        if (!empty($payment_status_raw)) {
            $payment_map = [
                'unpaid' => 'pending',
                'paid'   => 'success',
            ];
            $payment_status = $payment_map[$payment_status_raw] ?? 'pending';
        }

        $created_at = $this->parse_datetime($created_at_raw) ?: now();
        $application_date = $this->parse_datetime($app_date_raw) ?? $created_at;

        $updated_at = $created_at;

        $application_data = $this->build_application_data($row, $user_id);

        $member_ids = $this->parse_member_node_ids($row['member_node_ids'] ?? null);

        if (!empty($member_ids)) {
            $application_data[$this->member_section_key] = $member_ids;
        }

        return [
            'excel_row' => $excel_row_number,

            'old_id'    => (int) $noc_details_id,
            'user_id'   => $user_id,
            'service_id' => $service_id,

            'renewal'          => null,
            'applicationId'    => $application_id,
            'application_date' => $application_date,

            'status'         => $status,
            'final_fee'      => null,
            'payment_status' => $payment_status,

            // not used for this service
            'NOC_application_date' => null,
            'NOC_expiry_date'      => null,
            'NOC_certificate'      => null,
            'license_id'           => null,
            'NOC_letter_date'      => null,
            'NOC_generationDate'   => null,

            'application_data' => json_encode($application_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

            'created_at' => $created_at,
            'updated_at' => $updated_at,
        ];
    }


    private function build_application_data($row, $user_id): array
    {
        $application_data = [];

        foreach ($this->excel_question_id_map as $column_key => $question_id) {

            if (!isset($row[$column_key])) {
                continue;
            }

            $excel_answer = $row[$column_key];

            if ($excel_answer === null) {
                continue;
            }

            $excel_answer = is_string($excel_answer) ? trim($excel_answer) : (string) $excel_answer;

            if ($excel_answer === '') {
                continue;
            }

            // remove wrapping quotes if excel cell had "...."
            $excel_answer = trim($excel_answer, " \t\n\r\0\x0B\"");

            // array of urls
            if (preg_match("/\r\n|\n|\r/", $excel_answer)) {

                $urls = preg_split("/\r\n|\n|\r/", $excel_answer);

                $urls = array_values(array_filter(array_map(function ($u) use ($user_id) {
                    $u = trim((string) $u, " \t\n\r\0\x0B\"");
                    if ($u === '') {
                        return null;
                    }

                    if (str_starts_with($u, 'https://swaagatbackend.tripura.gov.in/')) {
                        return $this->normalizeFilePath($u, $user_id);
                    }

                    return $u;
                }, $urls)));

                if (!empty($urls)) {
                    $application_data[(string) $question_id] = $urls;
                }

                continue;
            }
            if (str_starts_with($excel_answer, 'https://swaagatbackend.tripura.gov.in/')) {
                $excel_answer = $this->normalizeFilePath($excel_answer, $user_id);
            }
            $application_data[(string) $question_id] = $excel_answer;
        }

        return $application_data;
    }

    private function normalizeFilePath(string $url, int $user_id): string
    {
        $filename = basename($url);

        return "uploads/{$user_id}/applications/{$filename}";
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
            $this->assignment_skipped_rows[] = [
                'row'        => $app_row['excel_row'] ?? null,
                'old_id'     => $app_row['old_id'] ?? null,
                'service_id' => $service_id,
                'status'     => $app_status,
                'reason'     => 'service_flow_not_found',
            ];
            return [];
        }

        $app_status_to_assignment = [
            'saved'         => 'saved',
            'pending'       => 'pending',
            're_submitted'  => 're_submitted',
            'extra_payment' => 'extra_payment',
            'send_back'     => 'send_back',
            'under_review'  => 'in_progress',
        ];

        $now   = Carbon::now();
        $flows = $this->service_flows_map[$service_id] ?? [];
        $first_step_flow = $flows[0] ?? null;

        if (!$first_step_flow) {
            return [];
        }

        return [[
            'application_id'   => $application_id,
            'service_id'       => $service_id,
            'step_number'      => $first_step_flow->step_number,
            'step_type'        => $first_step_flow->step_type,
            'department_id'    => $first_step_flow->department_id,
            'hierarchy_level'  => $first_step_flow->hierarchy_level,
            'status'           => $app_status_to_assignment[$app_status] ?? 'saved',
            'action_taken_by'  => null,
            'action_taken_at'  => null,
            'remarks'          => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]];
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

        $app_status_to_history = [
            'saved'         => 'saved',
            'pending'       => 'pending',
            're_submitted'  => 'approved',
            'extra_payment' => 'extra_payment',
            'send_back'     => 'send_back',
            'under_review'  => 'in_progress',
            'approved'      => 'approved',
            'rejected'      => 'rejected',
            'noc_issued'    => 'approved',
        ];

        $history_status = $app_status_to_history[$app_status] ?? 'saved';

        $flows = $this->service_flows_map[$service_id] ?? [];
        $first_step_flow = $flows[0] ?? null;

        if (!$first_step_flow) {
            return [];
        }

        $now = Carbon::now();

        return [[
            'application_id'          => $application_id,
            'service_id'              => $service_id,
            'step_number'             => $first_step_flow->step_number,
            'step_type'               => $first_step_flow->step_type,
            'department_id'           => $first_step_flow->department_id,
            'hierarchy_level'         => $first_step_flow->hierarchy_level,
            'action_taken_by'         => null,
            'action_taken_at'         => $app_row['application_date'] ?? null,
            'status'                  => $history_status,
            'status_file'             => null,
            'remarks'                 => null,
            'external_status'         => null,
            'external_payment_amount' => null,
            'external_payment_status' => null,
            'external_noc_url'        => null,
            'external_noc_file'       => null,
            'source'                  => 'native',
            'created_at'              => $now,
            'updated_at'              => $now,
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

            // "01-07-2025 11.24"
            if (preg_match('/^\d{2}-\d{2}-\d{4}\s+\d{1,2}\.\d{2}$/', $value)) {
                return Carbon::createFromFormat('d-m-Y H.i', $value)
                    ->format('Y-m-d H:i:s');
            }

            // "02-07-2025"
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


    private function parse_member_node_ids($value): array
    {
        if ($value === null) {
            return [];
        }

        $value = is_string($value) ? trim($value) : trim((string) $value);

        if ($value === '') {
            return [];
        }

        $tokens = preg_split('/[,\|\r\n\s]+/', $value);

        $ids = array_values(array_filter(array_map(function ($v) {
            $v = trim((string) $v);
            return ctype_digit($v) ? (int) $v : null;
        }, $tokens)));

        $seen = [];
        $final = [];

        foreach ($ids as $id) {
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $final[] = $id;
            }
        }

        return $final;
    }
}
