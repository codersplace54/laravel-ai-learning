<?php

namespace App\Imports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PartnershipPartnerAppDataCorrection implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];
    public int $updated_count = 0;

    protected int $service_id = 9;
    protected string $partner_section_key = 'Partner Details';

    protected array $new_partner_question_id_map = [
        'field_psps_name'                => 122, // Name of the Partner
        'field_psps_father_name'         => 131, // Father's Name
        'field_psps_address'             => 132, // Permanent Address
        'field_psps_date_of_joining'     => 1109, // Date of Joining at this Firm
        'field_psps_dob'                 => 1108, // Date of Birth
        'field_partner_pan'              => 143, // PAN Card
        'field_partner_voter_id'         => 145, // Voter Id
        'field_partner_aadhar'           => 141, // Aadhar Card
        'field_psps_mobile'              => 1107, // Mobile No
        'field_psps_designation'         => 128, // Designation
        'field_psps_profession'          => 1106, // Profession
        'field_psps_sex'                 => 137, // Sex
        'field_psps_social_status'       => 1096, // Social Status
        'field_pspd_ratio'               => 1111, // Profit Sharing Ratio
        'field_psps_capital_contribution' => 1110, // Capital contribution
    ];

    public function collection(Collection $rows)
    {
        DB::disableQueryLog();

        $partners_by_nid = [];

        foreach ($rows as $row) {
            $nid = (int) ($row['nid'] ?? 0);
            if (!$nid) {
                continue;
            }

            $payload = [];

            foreach ($this->new_partner_question_id_map as $column => $qid) {
                if (!isset($row[$column])) {
                    continue;
                }

                $value = $row[$column];
                
                // Handle date columns
                if (in_array($column, ['field_psps_dob', 'field_psps_date_of_joining'])) {
                    if (is_numeric($value)) {
                        $value = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
                    } elseif (is_string($value)) {
                        $value = trim($value);
                        if ($value !== '') {
                            try {
                                $value = Carbon::createFromFormat('d-m-Y', $value)->format('Y-m-d');
                            } catch (\Exception $e) {
                            }
                        }
                    }
                } else {
                    $value = trim((string) $value);
                }
                
                if ($value === '') {
                    continue;
                }

                $payload[(string) $qid] = $value;
            }

            if (!empty($payload)) {
                $partners_by_nid[$nid] = $payload;
            }
        }

        if (empty($partners_by_nid)) {
            return;
        }

        $apps = DB::table('user_service_applications')
            ->where('service_id', $this->service_id)
            ->whereNotNull('old_id')
            ->select(['id', 'user_id', 'application_data'])
            ->get();

        if ($apps->isEmpty()) {
            return;
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $batch_updates = [];
        $batch_size = 500;

        foreach ($apps as $app) {
            $application_data = json_decode($app->application_data, true);
            if (!is_array($application_data)) {
                continue;
            }

            $partner_section = $application_data[$this->partner_section_key] ?? null;
            if (!is_array($partner_section)) {
                continue;
            }

            $app_user_id = $app->user_id ?? null;

            if (!empty($partner_section) && is_array($partner_section[0] ?? null)) {
                $changed = false;
                foreach ($partner_section as $pIndex => $partnerObj) {
                    if (!is_array($partnerObj)) {
                        continue;
                    }

                    foreach ($partnerObj as $qid => $val) {
                        if (!is_string($val) || $val === '') {
                            continue;
                        }

                        if (str_starts_with($val, 'uploads/sites/')) {
                            $filename = basename($val);
                            $partner_section[$pIndex][$qid] = "https://swaagatbackend.tripura.gov.in/new/storage/sites/default/files/{$filename}";
                            $changed = true;
                        }
                    }
                }

                if (!$changed) {
                    continue;
                }

                $application_data[$this->partner_section_key] = $partner_section;
            } else {
                $partner_objects = [];

                foreach ($partner_section as $nid) {
                    $nid = (int) $nid;
                    if ($nid && isset($partners_by_nid[$nid])) {
                        $p = $partners_by_nid[$nid];

                        foreach ($p as $qid => $val) {
                            if (!is_string($val) || $val === '') {
                                continue;
                            }
                            if (str_starts_with($val, 'uploads/sites/')) {
                                $filename = basename($val);
                                $p[$qid] = "https://swaagatbackend.tripura.gov.in/new/storage/sites/default/files/{$filename}";
                            }
                        }

                        $partner_objects[] = $p;
                    }
                }

                if (empty($partner_objects)) {
                    continue;
                }

                $application_data[$this->partner_section_key] = $partner_objects;
            }

            $batch_updates[] = [
                'id' => $app->id,
                'application_data' => json_encode($application_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            if (count($batch_updates) >= $batch_size) {
                $this->batch_update($batch_updates, $now);
                $batch_updates = [];
            }
        }

        if (!empty($batch_updates)) {
            $this->batch_update($batch_updates, $now);
        }
    }

    protected function batch_update(array $batch_updates, string $now): void
    {
        if (empty($batch_updates)) {
            return;
        }

        $ids = array_column($batch_updates, 'id');
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
}
