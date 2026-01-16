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
            
            $partner_section = $application_data['Partner Details'] ?? null;
            
            if (!is_array($partner_section) || empty($partner_section)) {
                continue;
            }
            
            $need_update = false;
            
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
            return "storage/sites/default/files/{$filename}";
        }
        
        if (str_starts_with($path, 'uploads/')) {
            $filename = basename($path);
            return "storage/sites/default/files/{$filename}";
        }
        
        return $path;
    }
}
