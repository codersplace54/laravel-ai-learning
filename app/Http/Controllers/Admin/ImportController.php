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
use Illuminate\Support\Facades\DB;
use App\Imports\CooperativeSocietyApplicationImport;
use App\Imports\CooperativeSocietyMemberImport;
use App\Imports\PartnershipRegistrationImport;
use App\Imports\PartnershipPartnerImport;

class ImportController extends Controller
{
    public function import_society_members_form()
    {
        return view('admin.import.society_members');
    }

    public function import_society_members(Request $request)
    {
        $request->validate([
            'excel_files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $files = $request->file('excel_files');
        $all_skipped_rows = [];

        foreach ($files as $file) {
            $import = new CooperativeSocietyMemberImport();
            Excel::import($import, $file);

            $all_skipped_rows = array_merge($all_skipped_rows, $import->skipped_rows ?? []);
        }

        $skipped_count = count($all_skipped_rows);

        $grouped = collect($all_skipped_rows)
            ->groupBy(fn($r) => $r['reason'] ?? 'unknown')
            ->map(fn($items) => [
                'count' => $items->count(),
                'rows'  => $items->values()->all(),
            ])
            ->toArray();

        return back()->with([
            'success'        => 'Society member details patched successfully.',
            'skipped_count'  => $skipped_count,
            'skipped_grouped' => $grouped,
        ]);
    }

    public function import_society_applications_form()
    {
        return view('admin.import.society_applications');
    }

    public function import_society_applications(Request $request)
    {
        $request->validate([
            'excel_files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        // UserServiceApplication::truncate();
        // ApplicationWorkflowAssignment::truncate();
        // ApplicationWorkflowHistory::truncate();

        $files = $request->file('excel_files');
        $all_skipped_rows = [];
        $all_assignment_skipped_rows = [];

        foreach ($files as $file) {
            $import = new CooperativeSocietyApplicationImport();
            Excel::import($import, $file);

            $all_skipped_rows = array_merge($all_skipped_rows, $import->skipped_rows ?? []);
            $all_assignment_skipped_rows = array_merge($all_assignment_skipped_rows, $import->assignment_skipped_rows ?? []);
        }

        $skipped_count = count($all_skipped_rows);

        $grouped = collect($all_skipped_rows)
            ->groupBy(fn($r) => $r['reason_key'] ?? 'unknown')
            ->map(fn($items) => [
                'count' => $items->count(),
                'rows'  => $items->values()->all(),
            ])
            ->toArray();

        $assignment_skipped_grouped = collect($all_assignment_skipped_rows)
            ->groupBy('reason')
            ->toArray();

        return back()->with([
            'success'                    => 'Society applications import completed successfully.',
            'skipped_count'              => $skipped_count,
            'skipped_grouped'            => $grouped,
            'assignment_skipped_rows'    => $all_assignment_skipped_rows,
            'assignment_skipped_count'   => count($all_assignment_skipped_rows),
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

            $json_string = $request->hasFile('json_file')
                ? file_get_contents($request->file('json_file')->getRealPath())
                : $request->json_text;

            $decoded_data = json_decode($json_string, true);

            if ($decoded_data === null && json_last_error() !== JSON_ERROR_NONE) {
                return back()
                    ->withInput()
                    ->with('error', 'Invalid JSON. Please check the format.');
            }

            $users_array = (isset($decoded_data[0]) && is_array($decoded_data[0]))
                ? $decoded_data
                : [$decoded_data];

            $import = new UserImport();
            $import->import($users_array);

            $skipped_rows  = $import->skipped_rows ?? [];
            $skipped_count = count($skipped_rows);

            $skipped_grouped = collect($skipped_rows)
                ->groupBy(fn($r) => $r['reason'] ?? 'unknown')
                ->map(fn($items) => [
                    'count' => $items->count(),
                    'rows'  => $items->values()->all(),
                ])
                ->toArray();

            $message = "Import completed. Imported: {$import->imported_count}, Skipped: {$import->skipped_count}.";

            return back()->with([
                'success'         => $message,
                'skipped_count'   => $skipped_count,
                'skipped_grouped' => $skipped_grouped,
                'skipped_rows'    => $skipped_rows,
            ]);
        } catch (\Exception $e) {
            return back()
                ->withInput()
                ->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function import_partnership_registration_form()
    {
        return view('admin.import.partnership_registration');
    }

    public function import_partnership_registration(Request $request)
    {
        $request->validate([
            'excel_files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        // DB::statement('SET FOREIGN_KEY_CHECKS=0');
        // DB::table('user_service_applications')->where('id', '>', 1140)->delete();
        // DB::statement('ALTER TABLE user_service_applications AUTO_INCREMENT = 1141');
        // DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $files = $request->file('excel_files');
        $all_skipped_rows = [];
        $all_assignment_skipped_rows = [];

        foreach ($files as $file) {
            $import = new PartnershipRegistrationImport();
            Excel::import($import, $file);

            $all_skipped_rows = array_merge($all_skipped_rows, $import->skipped_rows ?? []);
            $all_assignment_skipped_rows = array_merge($all_assignment_skipped_rows, $import->assignment_skipped_rows ?? []);
        }

        $skipped_count = count($all_skipped_rows);

        $grouped = collect($all_skipped_rows)
            ->groupBy(fn($r) => $r['reason_key'] ?? 'unknown')
            ->map(fn($items) => [
                'count' => $items->count(),
                'rows'  => $items->values()->all(),
            ])
            ->toArray();

        $assignment_skipped_grouped = collect($all_assignment_skipped_rows)
            ->groupBy('reason')
            ->toArray();

        return back()->with([
            'success'                    => 'Partnership registration import completed successfully.',
            'skipped_count'              => $skipped_count,
            'skipped_grouped'            => $grouped,
            'assignment_skipped_rows'    => $all_assignment_skipped_rows,
            'assignment_skipped_count'   => count($all_assignment_skipped_rows),
            'assignment_skipped_grouped' => $assignment_skipped_grouped,
        ]);
    }

    public function import_partnership_partners_form()
    {
        return view('admin.import.partnership_partners');
    }

    public function import_partnership_partners(Request $request)
    {
        $request->validate([
            'excel_files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $files = $request->file('excel_files');
        $all_skipped_rows = [];

        foreach ($files as $file) {
            $import = new PartnershipPartnerImport();
            Excel::import($import, $file);

            $all_skipped_rows = array_merge($all_skipped_rows, $import->skipped_rows ?? []);
        }

        $skipped_count = count($all_skipped_rows);

        $grouped = collect($all_skipped_rows)
            ->groupBy(fn($r) => $r['reason'] ?? 'unknown')
            ->map(fn($items) => [
                'count' => $items->count(),
                'rows'  => $items->values()->all(),
            ])
            ->toArray();

        return back()->with([
            'success'        => 'Partnership partner details patched successfully.',
            'skipped_count'  => $skipped_count,
            'skipped_grouped' => $grouped,
        ]);
    }
}
