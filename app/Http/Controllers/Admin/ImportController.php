<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Imports\UserServiceApplicationImport;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\UserImport;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\ApplicationWorkflowHistory;
use App\Models\User;
use App\Models\UserServiceApplication;
use Illuminate\Container\Attributes\DB;
use App\Imports\CooperativeSocietyApplicationImport;

class ImportController extends Controller
{
    public function import_society_applications_form()
    {
        return view('admin.import.society_applications');
    }

    public function import_society_applications(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $file = $request->file('excel_file');

        $import = new CooperativeSocietyApplicationImport();
        Excel::import($import, $file);

        $skipped_rows  = $import->skipped_rows ?? [];
        $skipped_count = count($skipped_rows);

        $grouped = collect($skipped_rows)
            ->groupBy(fn($r) => $r['reason_key'] ?? 'unknown')
            ->map(fn($items) => [
                'count' => $items->count(),
                'rows'  => $items->values()->all(),
            ])
            ->toArray();

        $assignment_skipped_rows = $import->assignment_skipped_rows ?? [];
        $assignment_skipped_grouped = collect($assignment_skipped_rows)
            ->groupBy('reason')
            ->toArray();

        return back()->with([
            'success'                    => 'Society applications import completed successfully.',
            'skipped_count'              => $skipped_count,
            'skipped_grouped'            => $grouped,
            'assignment_skipped_rows'    => $assignment_skipped_rows,
            'assignment_skipped_count'   => count($assignment_skipped_rows),
            'assignment_skipped_grouped' => $assignment_skipped_grouped,
        ]);
    }

    public function import_service_application_form()
    {
        return view('admin.import.service_applications');
    }

    public function import_service_applications(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        // UserServiceApplication::truncate();
        // ApplicationWorkflowAssignment::truncate();
        // ApplicationWorkflowHistory::truncate();

        $file = $request->file('excel_file');

        $import = new UserServiceApplicationImport();
        Excel::import($import, $file);

        $skipped_rows = $import->skipped_rows ?? [];
        $skipped_count = count($skipped_rows);

        $grouped = collect($skipped_rows)
            ->groupBy(fn($r) => $r['reason_key'] ?? 'unknown')
            ->map(fn($items) => [
                'count' => $items->count(),
                'rows'  => $items->values()->all(),
            ])
            ->toArray();

        $assignment_skipped_rows = $import->assignment_skipped_rows ?? [];

        $assignment_skipped_grouped = collect($assignment_skipped_rows)
            ->groupBy('reason')
            ->toArray();

        return back()->with([
            'success'        => 'Import completed successfully.',
            'skipped_count'  => $skipped_count,
            'skipped_grouped' => $grouped,
            'assignment_skipped_rows'    => $assignment_skipped_rows,
            'assignment_skipped_count'   => count($assignment_skipped_rows),
            'assignment_skipped_grouped' => $assignment_skipped_grouped,
        ]);
    }

    public function import_users_form()
    {
        return view('admin.import.users');
    }

    public function import_users(Request $request)
    {
        try {
            $request->validate([
                'json_file' => 'nullable|file|mimes:json,txt',
                'json_text' => 'nullable|string',
            ]);
            // User::truncate();

            if (!$request->hasFile('json_file') && empty($request->json_text)) {
                return back()
                    ->withInput()
                    ->with('error', 'Please upload a JSON file or paste JSON text.');
            }

            if ($request->hasFile('json_file')) {
                $json_string = file_get_contents($request->file('json_file')->getRealPath());
            } else {
                $json_string = $request->json_text;
            }

            $decoded_data = json_decode($json_string, true);

            if ($decoded_data === null && json_last_error() !== JSON_ERROR_NONE) {
                return back()
                    ->withInput()
                    ->with('error', 'Invalid JSON. Please check the format.');
            }

            if (isset($decoded_data[0]) && is_array($decoded_data[0])) {
                $users_array = $decoded_data;
            } else {
                $users_array = [$decoded_data];
            }

            $import = new UserImport();
            $import->import($users_array);

            $message = "Import completed. Imported: {$import->imported_count}, Skipped: {$import->skipped_count}.";

            return back()->with([
                'success'      => $message,
                'skipped_rows' => $import->skipped_rows,
            ]);
        } catch (\Exception $e) {
            return back()
                ->withInput()
                ->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }
}
