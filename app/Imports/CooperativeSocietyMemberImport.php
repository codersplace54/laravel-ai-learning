<?php

namespace App\Imports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CooperativeSocietyMemberImport implements ToCollection, WithHeadingRow
{
    protected int $service_id = 2;
    protected string $member_section_key = 'Member Details';

    protected array $member_excel_question_id_map = [
        'field_soc_member_name'          => 193,
        'field_soc_member_father_name'   => 196,
        'field_soc_member_age'           => 199,
        'field_soc_member_sex'           => 203,
        'field_soc_member_profession'    => 205,
        'field_soc_no_of_share'          => 207,
        'field_soc_amount'               => 208,
        'field_soc_member_signature_url' => 211,
        'field_managing_committee'       => 216,
        'field_member_designation'       => 217,
    ];

    public function collection(Collection $rows)
    {
        DB::disableQueryLog();

        $members_by_nid = [];

        foreach ($rows as $row) {

            $nid = (int) ($row['nid'] ?? 0);
            if (!$nid) {
                continue;
            }

            $payload = [];

            foreach ($this->member_excel_question_id_map as $column => $qid) {
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
                $members_by_nid[$nid] = $payload;
            }
        }

        if (empty($members_by_nid)) {
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

            $member_section = $application_data[$this->member_section_key] ?? null;
            if (!is_array($member_section)) {
                continue;
            }

            $app_user_id = $app->user_id ?? null;

            if (!empty($member_section) && is_array($member_section[0] ?? null)) {
                $changed = false;
                foreach ($member_section as $mIndex => $memberObj) {
                    if (!is_array($memberObj)) {
                        continue;
                    }

                    foreach ($memberObj as $qid => $val) {
                        if (!is_string($val) || $val === '') {
                            continue;
                        }

                        if (str_starts_with($val, 'https://swaagatbackend.tripura.gov.in/')) {
                            $filename = basename($val);
                            $member_section[$mIndex][$qid] = $app_user_id ? "uploads/{$app_user_id}/applications/{$filename}" : "uploads/{$filename}";
                            $changed = true;
                        }
                    }
                }

                if (!$changed) {
                    continue;
                }

                $application_data[$this->member_section_key] = $member_section;

            // replace with member payloads and normalize file urls inside payloads
            } else {
                $member_objects = [];

                foreach ($member_section as $nid) {
                    $nid = (int) $nid;
                    if ($nid && isset($members_by_nid[$nid])) {
                        $m = $members_by_nid[$nid];

                        foreach ($m as $qid => $val) {
                            if (!is_string($val) || $val === '') {
                                continue;
                            }
                            if (str_starts_with($val, 'https://swaagatbackend.tripura.gov.in/')) {
                                $filename = basename($val);
                                $m[$qid] = $app_user_id ? "uploads/{$app_user_id}/applications/{$filename}" : "uploads/{$filename}";
                            }
                        }

                        $member_objects[] = $m;
                    }
                }

                if (empty($member_objects)) {
                    continue;
                }

                $application_data[$this->member_section_key] = $member_objects;
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
