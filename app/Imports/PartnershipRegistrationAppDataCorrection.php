<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PartnershipRegistrationAppDataCorrection implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];
    public int $updated_count = 0;

    protected array $new_question_id_map = [
        'firm_duration'         => 117, // Date of Establishment
        'date_of_establishment' => 117, // Date of Establishment
        'location'              => 119, // Location
        'contact_no'            => 1107, // Mobile No
        'email'                 => 303, // Applicant's Email
        'applicant_name'        => 299, // Applicant Name
        'applicant_father_name' => 301, // Applicant's Father Name
        'applicant_dob'         => 322, // Applicant's Date of Birth
        'applicant_address'     => 323, // Applicant's Address
        'firm_name'             => 1100, // Name of the Firm
        'habitation'            => 1101, // Habitation/ Area/ Building
        'kaitan_parcha'         => 159, // Kaitan Parcha / Rent Agreement
        'photos'                => 160, // 2 Photos
        'form_v'                => 162, // Form V
        'form_vi'               => 163, // Form VI
        'partnership_deed'      => 146, // Partnership Deed
        'partner_signature'     => 1124, // Partner Signature
        'principal_place'       => 1102, // Principal Place of Business
        'address_proof'         => 1120, // Self Attested proof of location
        'applicant_photo'       => 1125, // Applicant photo
        'land_agreement'        => 159, // Kaitan Parcha / Rent Agreement
        'witness_document'      => 1117, // Wittness Document
        'differently_abled'     => 1097, // Differently Abled
        'women_entrepreneur'    => 1098, // Women Entrepreneur
        'minority'              => 1099, // Minority
        'nature_of_business'    => 1104, // Nature of business to be carried on

        // partner details 
        'pfr_dob'      => 1108, // Date of Birth
        'pfr_address'  => 132, // Permanent Address
    ];

    protected string $partner_section_key = 'Partner Details';

    public function collection(Collection $rows)
    {
        DB::disableQueryLog();

        $batch_updates = [];
        $batch_size = 500;

        foreach ($rows as $index => $row) {
            $row_number = $row['#'] ?? ($index + 1);
            $update_data = $this->prepare_update_data($row, (int) $row_number);

            if ($update_data) {
                $batch_updates[] = $update_data;
            }

            if (count($batch_updates) >= $batch_size) {
                $this->batch_update($batch_updates);
                $batch_updates = [];
            }
        }

        if (!empty($batch_updates)) {
            $this->batch_update($batch_updates);
        }
    }

    protected function prepare_update_data($row, int $excel_row_number): ?array
    {
        $noc_details_id = $row['nid'] ?? null;

        if (empty($noc_details_id)) {
            $this->skipped_rows[] = [
                'row'    => $excel_row_number,
                'old_id' => null,
                'reason' => 'Missing NOC details ID',
            ];
            return null;
        }

        $application = DB::table('user_service_applications')
            ->where('old_id', $noc_details_id)
            ->first();

        if (!$application) {
            $this->skipped_rows[] = [
                'row'    => $excel_row_number,
                'old_id' => $noc_details_id,
                'reason' => 'Application not found',
            ];
            return null;
        }

        $new_application_data = $this->build_corrected_application_data($row, $application->user_id);

        $partners_ids = $this->parse_member_node_ids($row['partners'] ?? null);
        if (!empty($partners_ids)) {
            $new_application_data[$this->partner_section_key] = $partners_ids;
        }

        return [
            'id' => $application->id,
            'application_data' => json_encode($new_application_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    protected function batch_update(array $batch_updates): void
    {
        if (empty($batch_updates)) {
            return;
        }

        $ids = array_column($batch_updates, 'id');
        $now = now()->format('Y-m-d H:i:s');
        $cases = [];

        foreach ($batch_updates as $update) {
            $id = (int) $update['id'];
            $data = DB::connection()->getPdo()->quote($update['application_data']);
            $cases[] = "WHEN {$id} THEN {$data}";
        }

        $ids_list = implode(',', $ids);
        $case_sql = implode(' ', $cases);

        DB::statement("
            UPDATE user_service_applications
            SET application_data = CASE id {$case_sql} END,
                updated_at = '{$now}'
            WHERE id IN ({$ids_list})
        ");

        $this->updated_count += count($batch_updates);
    }

    private function build_corrected_application_data($row, $user_id): array
    {
        $application_data = [];

        foreach ($this->new_question_id_map as $column_key => $question_id) {
            if (!isset($row[$column_key])) {
                continue;
            }

            $excel_answer = $row[$column_key];

            if ($excel_answer === null) {
                continue;
            }

            // Handle date columns (117 and 322)
            if (in_array($question_id, [117, 322])) {
                if (is_numeric($excel_answer)) {
                    $excel_answer = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($excel_answer)->format('Y-m-d');
                } elseif (is_string($excel_answer)) {
                    $excel_answer = trim($excel_answer);
                    if ($excel_answer !== '') {
                        try {
                            $excel_answer = \Carbon\Carbon::createFromFormat('d-m-Y', $excel_answer)->format('Y-m-d');
                        } catch (\Exception $e) {
                            // Keep original if parsing fails
                        }
                    }
                }
            } else {
                $excel_answer = is_string($excel_answer) ? trim($excel_answer) : (string) $excel_answer;
            }

            if ($excel_answer === '') {
                continue;
            }

            if ($question_id === 0) {
                continue;
            }

            $excel_answer = trim($excel_answer, " \t\n\r\0\x0B\"");

            if (preg_match("/\r\n|\n|\r/", $excel_answer)) {
                $urls = preg_split("/\r\n|\n|\r/", $excel_answer);

                $urls = array_values(array_filter(array_map(function ($u) use ($user_id) {
                    $u = trim((string) $u, " \t\n\r\0\x0B\"");
                    if ($u === '') {
                        return null;
                    }

                    if (str_starts_with($u, 'uploads/sites/')) {
                        return $this->normalize_file_path($u, $user_id);
                    }

                    return $u;
                }, $urls)));

                if (!empty($urls)) {
                    $application_data[(string) $question_id] = $urls;
                }

                continue;
            }

            if (str_starts_with($excel_answer, 'uploads/sites/')) {
                $excel_answer = $this->normalize_file_path($excel_answer, $user_id);
            }

            $application_data[(string) $question_id] = $excel_answer;
        }

        return $application_data;
    }

    private function normalize_file_path(string $url, int $user_id): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $filename = basename($url);
        
        return "https://swaagatbackend.tripura.gov.in/new/storage/sites/default/files/{$filename}";
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
