<?php

namespace App\Imports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PartnershipPartnerImport implements ToCollection, WithHeadingRow
{
    protected int $service_id = 9;
    protected string $partner_section_key = 'Partner Details';

    protected array $partner_excel_question_id_map = [
        'field_psps_name'                => 128,  // Partner name
        'field_psps_father_name'         => 321,  // Partner's Father Name
        'field_psps_address'             => 323,  // Partner Address
        'field_psps_date_of_joining'     => 136,  // Partner's Date of joining
        'field_psps_dob'                 => 137,  // Partner's DOB
        'field_partner_pan'              => 141,  // Partner's PAN
        'field_partner_voter_id'         => 143,  // Partner's Voter ID
        'field_partner_aadhar'           => 301,  // Partner's Aadhar
        'field_psps_mobile'              => 322,  // Partner Phone Number
        'field_psps_designation'         => 1094, // Partner's Designation
        'field_psps_profession'          => 1093, // Partner's Profession
        'field_psps_sex'                 => 1095, // Gender
        'field_psps_social_status'       => 1096, // Social Status
        'field_pspd_ratio'               => 1097, // Partnership Ratio (%)
        'field_psps_capital_contribution' => 1098, // Partner's Capital Contribution (₹)
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

            foreach ($this->partner_excel_question_id_map as $column => $qid) {
                if (!isset($row[$column])) {
                    continue;
                }

                $value = trim((string) $row[$column]);
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
            ->select(['id', 'user_id', 'application_data'])
            ->get();

        if ($apps->isEmpty()) {
            return;
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $updates = [];

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
                            $partner_section[$pIndex][$qid] = $app_user_id ? "uploads/{$app_user_id}/applications/{$filename}" : "uploads/{$filename}";
                            $changed = true;
                        }
                    }
                }

                if (!$changed) {
                    continue;
                }

                $application_data[$this->partner_section_key] = $partner_section;

            // replace with partner payloads and normalize file urls inside payloads
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
                                $p[$qid] = $app_user_id ? "uploads/{$app_user_id}/applications/{$filename}" : "uploads/{$filename}";
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

            $updates[] = [
                'id'               => $app->id,
                'service_id'       => $this->service_id,
                'application_data' => json_encode($application_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at'       => $now,
            ];
        }

        foreach (array_chunk($updates, 1000) as $chunk) {

            $ids = [];
            $cases = [];

            foreach ($chunk as $row) {
                $id = (int) $row['id'];
                $ids[] = $id;

                $application_data = addslashes($row['application_data']);
                $updated_at = $row['updated_at'];

                $cases[] = "WHEN {$id} THEN '{$application_data}'";
            }

            if (empty($ids)) {
                continue;
            }

            $ids_list = implode(',', $ids);
            $case_sql = implode(' ', $cases);

            DB::statement("
        UPDATE user_service_applications
        SET application_data = CASE id
            {$case_sql}
        END,
        updated_at = '{$updated_at}'
        WHERE id IN ({$ids_list})
    ");
        }
    }
}