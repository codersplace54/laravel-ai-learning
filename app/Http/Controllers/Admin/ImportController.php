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
use App\Imports\ProfessionTaxApplicationImport;
use App\Imports\ProfessionTaxQuestionImport;
use App\Imports\ProfessionTaxCertificateImport;
use App\Imports\CommonApplicationImport;
use App\Imports\UserIncentiveApplicationImport;

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
                'json_files.*' => 'nullable|file|mimes:json,txt',
                'json_text' => 'nullable|string',
            ]);

            if (!$request->hasFile('json_files') && empty($request->json_text)) {
                return back()
                    ->withInput()
                    ->with('error', 'Please upload JSON files or paste JSON text.');
            }

            // User::truncate();
            $all_skipped_rows = [];
            $total_imported = 0;
            $total_skipped = 0;

            if ($request->hasFile('json_files')) {
                foreach ($request->file('json_files') as $file) {
                    $json_string = file_get_contents($file->getRealPath());
                    $decoded_data = json_decode($json_string, true);

                    if ($decoded_data === null && json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }

                    $users_array = (isset($decoded_data[0]) && is_array($decoded_data[0]))
                        ? $decoded_data
                        : [$decoded_data];

                    $import = new UserImport();
                    $import->import($users_array);

                    $all_skipped_rows = array_merge($all_skipped_rows, $import->skipped_rows ?? []);
                    $total_imported += $import->imported_count;
                    $total_skipped += $import->skipped_count;
                }
            } else {
                $decoded_data = json_decode($request->json_text, true);

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

                $all_skipped_rows = $import->skipped_rows ?? [];
                $total_imported = $import->imported_count;
                $total_skipped = $import->skipped_count;
            }

            $skipped_count = count($all_skipped_rows);

            $skipped_grouped = collect($all_skipped_rows)
                ->groupBy(fn($r) => $r['reason'] ?? 'unknown')
                ->map(fn($items) => [
                    'count' => $items->count(),
                    'rows'  => $items->values()->all(),
                ])
                ->toArray();

            $message = "Import completed. Imported: {$total_imported}, Skipped: {$total_skipped}.";

            return back()->with([
                'success'         => $message,
                'skipped_count'   => $skipped_count,
                'skipped_grouped' => $skipped_grouped,
                'skipped_rows'    => $all_skipped_rows,
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
        $all_history_skipped_rows = [];

        foreach ($files as $file) {
            $import = new PartnershipRegistrationImport();
            Excel::import($import, $file);

            $all_skipped_rows = array_merge($all_skipped_rows, $import->skipped_rows ?? []);
            $all_assignment_skipped_rows = array_merge($all_assignment_skipped_rows, $import->assignment_skipped_rows ?? []);
            $all_history_skipped_rows = array_merge($all_history_skipped_rows, $import->history_skipped_rows ?? []);
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

        $history_skipped_grouped = collect($all_history_skipped_rows)
            ->groupBy('reason')
            ->toArray();

        return back()->with([
            'success'                    => 'Partnership registration import completed successfully.',
            'skipped_count'              => $skipped_count,
            'skipped_grouped'            => $grouped,
            'assignment_skipped_rows'    => $all_assignment_skipped_rows,
            'assignment_skipped_count'   => count($all_assignment_skipped_rows),
            'assignment_skipped_grouped' => $assignment_skipped_grouped,
            'history_skipped_rows'       => $all_history_skipped_rows,
            'history_skipped_count'      => count($all_history_skipped_rows),
            'history_skipped_grouped'    => $history_skipped_grouped,
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

    public function import_profession_tax_form()
    {
        return view('admin.import.profession_tax');
    }

    public function import_profession_tax(Request $request)
    {
        $request->validate([
            'excel_files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        // UserServiceApplication::truncate();

        $files = $request->file('excel_files');
        $all_skipped_rows = [];
        $all_assignment_skipped_rows = [];

        foreach ($files as $file) {
            $import = new ProfessionTaxApplicationImport();
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
            'success'                    => 'Profession Tax applications import completed successfully.',
            'skipped_count'              => $skipped_count,
            'skipped_grouped'            => $grouped,
            'assignment_skipped_rows'    => $all_assignment_skipped_rows,
            'assignment_skipped_count'   => count($all_assignment_skipped_rows),
            'assignment_skipped_grouped' => $assignment_skipped_grouped,
        ]);
    }

    public function import_profession_tax_questions_form()
    {
        return view('admin.import.profession_tax_questions');
    }

    public function import_profession_tax_questions(Request $request)
    {
        $request->validate([
            'excel_files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $files = $request->file('excel_files');
        $all_skipped_rows = [];
        $all_updated_rows = [];

        foreach ($files as $file) {
            $import = new ProfessionTaxQuestionImport();
            Excel::import($import, $file);

            $all_skipped_rows = array_merge($all_skipped_rows, $import->skipped_rows ?? []);
            $all_updated_rows = array_merge($all_updated_rows, $import->updated_rows ?? []);
        }

        $skipped_count = count($all_skipped_rows);
        $updated_count = count($all_updated_rows);

        $grouped = collect($all_skipped_rows)
            ->groupBy(fn($r) => $r['reason'] ?? 'unknown')
            ->map(fn($items) => [
                'count' => $items->count(),
                'rows'  => $items->values()->all(),
            ])
            ->toArray();

        return back()->with([
            'success'        => "Profession Tax questions import completed. Updated: {$updated_count}, Skipped: {$skipped_count}.",
            'skipped_count'  => $skipped_count,
            'updated_count'  => $updated_count,
            'skipped_grouped' => $grouped,
        ]);
    }

    public function import_profession_tax_certificate_form()
    {
        return view('admin.import.profession_tax_certificate');
    }

    public function import_profession_tax_certificate(Request $request)
    {
        $request->validate([
            'excel_files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $files = $request->file('excel_files');
        $all_skipped_rows = [];
        $all_assignment_skipped_rows = [];
        $all_history_skipped_rows = [];

        foreach ($files as $file) {
            $import = new ProfessionTaxCertificateImport();
            Excel::import($import, $file);

            $all_skipped_rows = array_merge($all_skipped_rows, $import->skipped_rows ?? []);
            $all_assignment_skipped_rows = array_merge($all_assignment_skipped_rows, $import->assignment_skipped_rows ?? []);
            $all_history_skipped_rows = array_merge($all_history_skipped_rows, $import->history_skipped_rows ?? []);
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

        $history_skipped_grouped = collect($all_history_skipped_rows)
            ->groupBy('reason')
            ->toArray();

        return back()->with([
            'success'                    => 'Profession Tax Certificate import completed successfully.',
            'skipped_count'              => $skipped_count,
            'skipped_grouped'            => $grouped,
            'assignment_skipped_rows'    => $all_assignment_skipped_rows,
            'assignment_skipped_count'   => count($all_assignment_skipped_rows),
            'assignment_skipped_grouped' => $assignment_skipped_grouped,
            'history_skipped_rows'       => $all_history_skipped_rows,
            'history_skipped_count'      => count($all_history_skipped_rows),
            'history_skipped_grouped'    => $history_skipped_grouped,
        ]);
    }

    public function import_legal_metrology_form()
    {
        return view('admin.import.legal_metrology');
    }

    public function import_legal_metrology(Request $request)
    {
        $request->validate([
            'excel_files.*' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $files = $request->file('excel_files');
        $all_skipped_rows = [];
        $all_assignment_skipped_rows = [];

        foreach ($files as $file) {
            $import = new CommonApplicationImport();
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
            'success'                    => 'Legal Metrology applications import completed successfully.',
            'skipped_count'              => $skipped_count,
            'skipped_grouped'            => $grouped,
            'assignment_skipped_rows'    => $all_assignment_skipped_rows,
            'assignment_skipped_count'   => count($all_assignment_skipped_rows),
            'assignment_skipped_grouped' => $assignment_skipped_grouped,
        ]);
    }

    public function import_user_incentive_applications_form()
    {
        return view('admin.import.user_incentive_applications');
    }

    public function import_user_incentive_applications(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $file = $request->file('excel_file');

        $import = new UserIncentiveApplicationImport();
        Excel::import($import, $file);

        $skipped_rows = $import->skipped_rows ?? [];
        $skipped_count = count($skipped_rows);

        $grouped = collect($skipped_rows)
            ->groupBy(fn($r) => $r['reason'] ?? 'unknown')
            ->map(fn($items) => [
                'count' => $items->count(),
                'rows'  => $items->values()->all(),
            ])
            ->toArray();

        return back()->with([
            'success'        => 'User incentive applications import completed successfully.',
            'skipped_count'  => $skipped_count,
            'skipped_rows'   => $skipped_rows,
            'skipped_grouped' => $grouped,
        ]);
    }
}
