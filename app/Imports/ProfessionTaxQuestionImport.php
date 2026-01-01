<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProfessionTaxQuestionImport implements ToCollection, WithHeadingRow
{
    public array $skipped_rows = [];
    public array $updated_rows = [];

    public function collection(Collection $rows)
    {
        DB::disableQueryLog();
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $batch_updates = [];
        $valid_nids = [];

        foreach ($rows as $index => $row) {
            $row_number = $index + 1;
            
            $nid = $row['field_ptax_noc_details_ref'] ?? null;
            $uid = $row['uid'] ?? null;
            $application_data = $row['application_data'] ?? null;

            if (empty($nid)) {
                $this->skipped_rows[] = [
                    'row' => $row_number,
                    'nid' => $nid,
                    'reason' => 'Missing nid',
                ];
                continue;
            }

            if (empty($application_data)) {
                $this->skipped_rows[] = [
                    'row' => $row_number,
                    'nid' => $nid,
                    'reason' => 'Missing application_data',
                ];
                continue;
            }

            $decoded_data = html_entity_decode($application_data);
            $json_data = json_decode($decoded_data, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->skipped_rows[] = [
                    'row' => $row_number,
                    'nid' => $nid,
                    'reason' => 'Invalid JSON in application_data',
                ];
                continue;
            }

            $batch_updates[$nid] = [
                'application_data' => json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'row_number' => $row_number,
                'uid' => $uid
            ];
            $valid_nids[] = $nid;
        }

        if (empty($valid_nids)) {
            return;
        }

        $existing_apps = DB::table('user_service_applications')
            ->whereIn('old_id', $valid_nids)
            ->pluck('id', 'old_id')
            ->toArray();

        foreach ($batch_updates as $nid => $data) {
            if (isset($existing_apps[$nid])) {
                DB::table('user_service_applications')
                    ->where('id', $existing_apps[$nid])
                    ->update([
                        'application_data' => $data['application_data'],
                        'updated_at' => now(),
                    ]);

                $this->updated_rows[] = [
                    'row' => $data['row_number'],
                    'nid' => $nid,
                    'uid' => $data['uid'],
                ];
            } else {
                $this->skipped_rows[] = [
                    'row' => $data['row_number'],
                    'nid' => $nid,
                    'reason' => 'Application not found',
                ];
            }
        }
    }
}