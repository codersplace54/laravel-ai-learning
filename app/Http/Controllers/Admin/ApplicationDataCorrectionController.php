<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\PartnershipRegistrationAppDataCorrection;
use App\Imports\PartnershipPartnerAppDataCorrection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ApplicationDataCorrectionController extends Controller
{
    public function correction_form()
    {
        return view('admin.import.application_data_correction');
    }

    public function update_partnership_application_data(Request $request)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $files = $request->file('files');
        $total_updated = 0;
        $total_skipped = 0;
        $all_skipped_rows = [];

        foreach ($files as $file) {
            $updater = new PartnershipRegistrationAppDataCorrection();
            Excel::import($updater, $file);

            $total_updated += $updater->updated_count;
            $total_skipped += count($updater->skipped_rows);
            $all_skipped_rows = array_merge($all_skipped_rows, $updater->skipped_rows);
        }

        return back()->with([
            'success' => 'Application data updated successfully',
            'files_processed' => count($files),
            'updated_count' => $total_updated,
            'skipped_count' => $total_skipped,
            'skipped_rows' => $all_skipped_rows,
        ]);
    }

    public function update_partnership_partner_data(Request $request)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $files = $request->file('files');
        $total_updated = 0;
        $total_skipped = 0;
        $all_skipped_rows = [];

        foreach ($files as $file) {
            $updater = new PartnershipPartnerAppDataCorrection();
            Excel::import($updater, $file);

            $total_updated += $updater->updated_count;
            $total_skipped += count($updater->skipped_rows);
            $all_skipped_rows = array_merge($all_skipped_rows, $updater->skipped_rows);
        }

        return back()->with([
            'success' => 'Partner data updated successfully',
            'files_processed' => count($files),
            'updated_count' => $total_updated,
            'skipped_count' => $total_skipped,
            'skipped_rows' => $all_skipped_rows,
        ]);
    }

    public function correct_all_file_paths(Request $request)
    {
        set_time_limit(0);
        
        $updated_count = 0;
        $batch_size = 100;
        $updates_batch = [];
        $total_checked = 0;
        
        $applications = DB::table('user_service_applications')
            ->whereNotNull('old_id')
            ->select(['id', 'application_data', 'NOC_certificate'])
            ->get();

        foreach ($applications as $app) {
            $total_checked++;
            $need_update = false;
            
            $application_data = json_decode($app->application_data, true);
            
            if (is_array($application_data)) {
                $application_data = $this->fix_file_paths_in_array($application_data, $need_update);
            }
            
            $noc_certificate = $app->NOC_certificate;
            if ($noc_certificate && str_starts_with($noc_certificate, 'uploads/')) {
                $filename = basename($noc_certificate);
                $noc_certificate = "https://swaagatbackend.tripura.gov.in/new/storage/sites/default/files/{$filename}";
                $need_update = true;
            }
            
            if ($need_update) {
                $updates_batch[] = [
                    'id' => $app->id,
                    'application_data' => json_encode($application_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'NOC_certificate' => $noc_certificate,
                ];
                
                if (count($updates_batch) >= $batch_size) {
                    $this->execute_batch_update($updates_batch);
                    $updated_count += count($updates_batch);
                    $updates_batch = [];
                }
            }
        }
        
        if (!empty($updates_batch)) {
            $this->execute_batch_update($updates_batch);
            $updated_count += count($updates_batch);
        }

        return back()->with([
            'success' => 'All file paths corrected successfully',
            'updated_count' => $updated_count,
            'total_checked' => $total_checked,
        ]);
    }
    
    public function normalize_to_relative_paths(Request $request)
    {
        set_time_limit(0);
        
        $updated_count = 0;
        $batch_size = 100;
        $updates_batch = [];
        $total_checked = 0;
        
        $applications = DB::table('user_service_applications')
            ->whereNotNull('old_id')
            ->select(['id', 'application_data', 'NOC_certificate'])
            ->get();

        foreach ($applications as $app) {
            $total_checked++;
            $need_update = false;
            
            $application_data = json_decode($app->application_data, true);
            
            if (is_array($application_data)) {
                $application_data = $this->normalize_paths_in_array($application_data, $need_update);
            }
            
            $noc_certificate = $app->NOC_certificate;
            if ($noc_certificate) {
                $normalized = $this->normalize_single_path($noc_certificate);
                if ($normalized !== $noc_certificate) {
                    $noc_certificate = $normalized;
                    $need_update = true;
                }
            }
            
            if ($need_update) {
                $updates_batch[] = [
                    'id' => $app->id,
                    'application_data' => json_encode($application_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'NOC_certificate' => $noc_certificate,
                ];
                
                if (count($updates_batch) >= $batch_size) {
                    $this->execute_batch_update($updates_batch);
                    $updated_count += count($updates_batch);
                    $updates_batch = [];
                }
            }
        }
        
        if (!empty($updates_batch)) {
            $this->execute_batch_update($updates_batch);
            $updated_count += count($updates_batch);
        }

        return back()->with([
            'success' => 'All paths normalized to relative format',
            'updated_count' => $updated_count,
            'total_checked' => $total_checked,
        ]);
    }

    public function update_partnership_application_noc_certificate(Request $request)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $files = $request->file('files');
        $total_updated = 0;
        $total_skipped = 0;
        $all_skipped_rows = [];
        $batch_updates = [];
        $batch_size = 100;

        foreach ($files as $file) {
            $data = Excel::toArray(null, $file);
            $rows = $data[0] ?? [];
            
            if (empty($rows)) {
                continue;
            }
            
            $headers = array_shift($rows);
            $nid_index = array_search('nid', $headers);
            $field_certificate_index = array_search('field_certificate', $headers);
            
            if ($nid_index === false || $field_certificate_index === false) {
                $all_skipped_rows[] = [
                    'file' => $file->getClientOriginalName(),
                    'reason' => 'Missing required columns (nid or field_certificate)'
                ];
                continue;
            }
            
            foreach ($rows as $index => $row) {
                $nid = $row[$nid_index] ?? null;
                $field_certificate = $row[$field_certificate_index] ?? null;
                
                if (empty($nid) || empty($field_certificate)) {
                    $total_skipped++;
                    $all_skipped_rows[] = [
                        'row' => $index + 2,
                        'nid' => $nid,
                        'reason' => 'Missing nid or field_certificate'
                    ];
                    continue;
                }
                
                $filename = basename($field_certificate);
                $noc_certificate_url = "https://swaagatbackend.tripura.gov.in/new/storage/sites/default/files/{$filename}";
                
                $batch_updates[] = [
                    'nid' => $nid,
                    'noc_certificate' => $noc_certificate_url,
                    'row' => $index + 2
                ];
                
                if (count($batch_updates) >= $batch_size) {
                    $updated = $this->execute_noc_batch_update($batch_updates);
                    $total_updated += $updated['updated'];
                    $total_skipped += $updated['skipped'];
                    $all_skipped_rows = array_merge($all_skipped_rows, $updated['skipped_rows']);
                    $batch_updates = [];
                }
            }
        }
        
        if (!empty($batch_updates)) {
            $updated = $this->execute_noc_batch_update($batch_updates);
            $total_updated += $updated['updated'];
            $total_skipped += $updated['skipped'];
            $all_skipped_rows = array_merge($all_skipped_rows, $updated['skipped_rows']);
        }

        return back()->with([
            'success' => 'Partnership NOC certificates updated successfully',
            'files_processed' => count($files),
            'updated_count' => $total_updated,
            'skipped_count' => $total_skipped,
            'skipped_rows' => $all_skipped_rows,
        ]);
    }
    
    private function execute_noc_batch_update($batch_updates)
    {
        if (empty($batch_updates)) {
            return ['updated' => 0, 'skipped' => 0, 'skipped_rows' => []];
        }
        
        $nids = array_column($batch_updates, 'nid');
        $existing_apps = DB::table('user_service_applications')
            ->whereIn('old_id', $nids)
            ->pluck('id', 'old_id')
            ->toArray();
        
        $updates_batch = [];
        $skipped_rows = [];
        
        foreach ($batch_updates as $update) {
            if (!isset($existing_apps[$update['nid']])) {
                $skipped_rows[] = [
                    'row' => $update['row'],
                    'nid' => $update['nid'],
                    'reason' => 'Application not found'
                ];
                continue;
            }
            
            $updates_batch[] = [
                'id' => $existing_apps[$update['nid']],
                'noc_certificate' => $update['noc_certificate']
            ];
        }
        
        if (!empty($updates_batch)) {
            $this->execute_noc_sql_batch_update($updates_batch);
        }
        
        return [
            'updated' => count($updates_batch),
            'skipped' => count($skipped_rows),
            'skipped_rows' => $skipped_rows
        ];
    }
    
    private function execute_noc_sql_batch_update($updates_batch)
    {
        if (empty($updates_batch)) {
            return;
        }
        
        $now = now()->format('Y-m-d H:i:s');
        $ids = [];
        $noc_cases = [];
        
        foreach ($updates_batch as $update) {
            $id = $update['id'];
            $ids[] = $id;
            $noc = addslashes($update['noc_certificate']);
            $noc_cases[] = "WHEN {$id} THEN '{$noc}'";
        }
        
        $ids_list = implode(',', $ids);
        $noc_case_sql = implode(' ', $noc_cases);
        
        DB::statement("
            UPDATE user_service_applications
            SET 
                NOC_certificate = CASE id {$noc_case_sql} END,
                updated_at = '{$now}'
            WHERE id IN ({$ids_list})
        ");
    }

    public function fix_partner_dates(Request $request)
    {
        set_time_limit(0);
        
        $updated_count = 0;
        $batch_size = 100;
        $updates_batch = [];
        
        $applications = DB::table('user_service_applications')
            ->where('service_id', 9)
            ->select(['id', 'application_data'])
            ->get();

        foreach ($applications as $app) {
            $application_data = json_decode($app->application_data, true);
            
            if (!is_array($application_data)) {
                continue;
            }
            
            $need_update = false;
            
            // Fix dates in main application data
            foreach ($application_data as $key => $value) {
                if (is_array($value)) {
                    continue;
                }
                
                // Fix question 117 and 322
                if (($key === '117' || $key === '322') && is_numeric($value)) {
                    $excel_date = (int) $value;
                    $fixed_date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($excel_date)->format('Y-m-d');
                    $application_data[$key] = $fixed_date;
                    $need_update = true;
                }
            }
            
            // Fix dates in Partner Details section
            $partner_section = $application_data['Partner Details'] ?? null;
            
            if (is_array($partner_section) && !empty($partner_section)) {
                foreach ($partner_section as $index => $partner) {
                    if (!is_array($partner)) {
                        continue;
                    }
                    
                    // Fix DOB (question ID 1108)
                    if (isset($partner['1108']) && is_numeric($partner['1108'])) {
                        $excel_date = (int) $partner['1108'];
                        $fixed_date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($excel_date)->format('Y-m-d');
                        $partner_section[$index]['1108'] = $fixed_date;
                        $need_update = true;
                    }
                    
                    // Fix Date of Joining (question ID 1109)
                    if (isset($partner['1109']) && is_numeric($partner['1109'])) {
                        $excel_date = (int) $partner['1109'];
                        $fixed_date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($excel_date)->format('Y-m-d');
                        $partner_section[$index]['1109'] = $fixed_date;
                        $need_update = true;
                    }
                }
                
                if ($need_update) {
                    $application_data['Partner Details'] = $partner_section;
                }
            }
            
            if ($need_update) {
                $updates_batch[] = [
                    'id' => $app->id,
                    'application_data' => json_encode($application_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                
                if (count($updates_batch) >= $batch_size) {
                    $this->execute_date_batch_update($updates_batch);
                    $updated_count += count($updates_batch);
                    $updates_batch = [];
                }
            }
        }
        
        if (!empty($updates_batch)) {
            $this->execute_date_batch_update($updates_batch);
            $updated_count += count($updates_batch);
        }

        return back()->with([
            'success' => 'Partner dates fixed successfully',
            'updated_count' => $updated_count,
        ]);
    }
    
    private function execute_date_batch_update($updates_batch)
    {
        if (empty($updates_batch)) {
            return;
        }
        
        $now = now()->format('Y-m-d H:i:s');
        $ids = [];
        $data_cases = [];
        
        foreach ($updates_batch as $update) {
            $id = $update['id'];
            $ids[] = $id;
            
            $data = addslashes($update['application_data']);
            $data_cases[] = "WHEN {$id} THEN '{$data}'";
        }
        
        $ids_list = implode(',', $ids);
        $data_case_sql = implode(' ', $data_cases);
        
        DB::statement("
            UPDATE user_service_applications
            SET 
                application_data = CASE id {$data_case_sql} END,
                updated_at = '{$now}'
            WHERE id IN ({$ids_list})
        ");
    }
    
    private function execute_batch_update($updates_batch)
    {
        if (empty($updates_batch)) {
            return;
        }
        
        $now = now()->format('Y-m-d H:i:s');
        $ids = [];
        $data_cases = [];
        $noc_cases = [];
        
        foreach ($updates_batch as $update) {
            $id = $update['id'];
            $ids[] = $id;
            
            $data = addslashes($update['application_data']);
            $data_cases[] = "WHEN {$id} THEN '{$data}'";
            
            if ($update['NOC_certificate']) {
                $noc = addslashes($update['NOC_certificate']);
                $noc_cases[] = "WHEN {$id} THEN '{$noc}'";
            } else {
                $noc_cases[] = "WHEN {$id} THEN NULL";
            }
        }
        
        $ids_list = implode(',', $ids);
        $data_case_sql = implode(' ', $data_cases);
        $noc_case_sql = implode(' ', $noc_cases);
        
        DB::statement("
            UPDATE user_service_applications
            SET 
                application_data = CASE id {$data_case_sql} END,
                NOC_certificate = CASE id {$noc_case_sql} END,
                updated_at = '{$now}'
            WHERE id IN ({$ids_list})
        ");
    }

    private function fix_file_paths_in_array($data, &$need_update)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->fix_file_paths_in_array($value, $need_update);
            } elseif (is_string($value)) {
                if (str_contains($value, 'uploads/')) {
                    $filename = basename($value);
                    $data[$key] = "https://swaagatbackend.tripura.gov.in/new/storage/sites/default/files/{$filename}";
                    $need_update = true;
                }
            }
        }
        
        return $data;
    }

    private function normalize_paths_in_array($data, &$need_update)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalize_paths_in_array($value, $need_update);
            } elseif (is_string($value)) {
                $normalized = $this->normalize_single_path($value);
                if ($normalized !== $value) {
                    $data[$key] = $normalized;
                    $need_update = true;
                }
            }
        }
        
        return $data;
    }

    private function normalize_single_path($path)
    {
        if (str_contains($path, 'https://swaagatbackend.tripura.gov.in')) {
            $filename = basename($path);
            return "sites/default/files/{$filename}";
        }
        
        if (str_starts_with($path, 'uploads/')) {
            $filename = basename($path);
            return "sites/default/files/{$filename}";
        }
        
        if (str_starts_with($path, 'storage/sites/default/files/')) {
            $filename = basename($path);
            return "sites/default/files/{$filename}";
        }
        
        return $path;
    }

    public function fix_incorrectly_normalized_paths(Request $request)
    {
        set_time_limit(0);
        
        $updated_count = 0;
        $batch_size = 100;
        $updates_batch = [];
        $total_checked = 0;
        
        $applications = DB::table('user_service_applications')
            ->whereNull('old_id')
            ->select(['id', 'user_id', 'application_data', 'NOC_certificate'])
            ->get();

        foreach ($applications as $app) {
            $total_checked++;
            $need_update = false;
            
            $application_data = json_decode($app->application_data, true);
            
            if (is_array($application_data)) {
                $application_data = $this->revert_paths_in_array($application_data, $need_update, $app->user_id);
            }
            
            $noc_certificate = $app->NOC_certificate;
            if ($noc_certificate && str_starts_with($noc_certificate, 'sites/default/files/')) {
                $filename = basename($noc_certificate);
                $noc_certificate = "uploads/{$app->user_id}/application/{$filename}";
                $need_update = true;
            }
            
            if ($need_update) {
                $updates_batch[] = [
                    'id' => $app->id,
                    'application_data' => json_encode($application_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'NOC_certificate' => $noc_certificate,
                ];
                
                if (count($updates_batch) >= $batch_size) {
                    $this->execute_batch_update($updates_batch);
                    $updated_count += count($updates_batch);
                    $updates_batch = [];
                }
            }
        }
        
        if (!empty($updates_batch)) {
            $this->execute_batch_update($updates_batch);
            $updated_count += count($updates_batch);
        }

        return back()->with([
            'success' => 'Incorrectly normalized paths fixed successfully',
            'updated_count' => $updated_count,
            'total_checked' => $total_checked,
        ]);
    }

    private function revert_paths_in_array($data, &$need_update, $user_id = null)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->revert_paths_in_array($value, $need_update, $user_id);
            } elseif (is_string($value) && str_starts_with($value, 'sites/default/files/')) {
                $filename = basename($value);
                $data[$key] = "uploads/{$user_id}/applications/{$filename}";
                $need_update = true;
            }
        }
        
        return $data;
    }
}
