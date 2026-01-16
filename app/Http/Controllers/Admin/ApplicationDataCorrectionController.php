<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\PartnershipRegistrationAppDataCorrection;
use App\Imports\PartnershipPartnerAppDataCorrection;
use Illuminate\Http\Request;
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
}
