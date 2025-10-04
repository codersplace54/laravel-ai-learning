<?php

namespace App\Http\Controllers\Incentive;

use App\Http\Controllers\Controller;
use App\Models\IncentiveWorkflowHistory;
use Illuminate\Http\Request;
use App\Models\UserIncentiveApplication;
use App\Models\ProformaQuestionnaire;
use App\Models\Proforma;
use App\Models\Scheme;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserIncentiveApplicationController extends Controller
{
    public function user_proforma_application_store(Request $request)
    {
        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $is_save_only = $request->save_data == 1;
            // dd($request->file());
            $request->validate([
                'save_data'        => 'required|integer|in:0,1',
                'scheme_id'        => 'required|integer|exists:schemes,id',
                'proforma_id'      => 'required|integer|exists:proformas,id',
                'application_type' => 'required|in:eligibility,claim',
                'eligibility_application_id' => 'exclude_unless:application_type,claim|required_unless:save_data,1|integer|exists:user_incentive_applications,id',
                'claim_type'                 => 'exclude_unless:application_type,claim|required_unless:save_data,1|in:one_time,monthly,quarterly,half_yearly,annually',
                'claim_period_start'         => 'exclude_unless:application_type,claim|required_unless:save_data,1|date',
                'claim_period_end'           => 'exclude_unless:application_type,claim|required_unless:save_data,1|date|after_or_equal:claim_period_start',
                'files'        => 'nullable|array',
                'files.*'      => 'array',
                'files.*.*'    => 'file|max:10240|mimes:pdf,jpg,jpeg,png,avif,webp',
                'form_answers_json'  => 'required_unless:save_data,1',
            ]);

            DB::beginTransaction();

            $find_application = [
                'user_id'          => $user->id,
                'scheme_id'        => $request->scheme_id,
                'proforma_id'      => $request->proforma_id,
                'application_type' => $request->application_type,
            ];

            if ($request->application_type === 'claim' && $request->save_data === 0) {
                $find_application['claim_period_start'] = $request->claim_period_start;
                $find_application['claim_period_end']   = $request->claim_period_end;
            }

            $application = UserIncentiveApplication::firstOrNew($find_application);

            $existing_answers = $application->form_answers_json;

            if (is_string($existing_answers)) {
                $existing_answers = json_decode($existing_answers, true) ?: [];
            }
            if (!is_array($existing_answers)) {
                $existing_answers = [];
            }
            // dd("hello");
            $incoming_answers = $request->form_answers_json
                ? json_decode($request->form_answers_json, true)
                : [];

            $answers = $existing_answers;

            foreach ($incoming_answers as $qid => $payload) {
                if (!isset($answers[$qid])) {
                    $answers[$qid] = ['value' => null, 'files' => []];
                }
                if (array_key_exists('value', $payload)) {
                    $answers[$qid]['value'] = $payload['value'];
                }
            }

            $remove_file_ids_by_question = $request->input('remove_file_ids', []);

            /* remove files only when asked
               payload example: { "remove_file_ids": { "12": ["uuid1","uuid2"] } }
               steps:
               1) get files array for that question
               2) skip files not in remove list; delete ones that are
               3) if nothing left and no value, drop the answer key so required check catches it
            */

            foreach ($remove_file_ids_by_question as $question_id => $file_ids_to_remove) {
                $existing_files = $answers[$question_id]['files'] ?? [];

                if (empty($existing_files)) {
                    continue;
                }

                $files_after_removal = [];

                foreach ($existing_files as $file) {
                    $should_delete = in_array($file['file_id'], $file_ids_to_remove, true);

                    if ($should_delete) {
                        Storage::disk('public')->delete($file['path']);
                        continue;
                    }
                    $files_after_removal[] = $file;
                }

                if (empty($files_after_removal) && empty($answers[$question_id]['value'])) {
                    unset($answers[$question_id]);
                } else {
                    $answers[$question_id]['files'] = $files_after_removal;
                }
            }

            /* when files are uploaded:
               1) save under user/proforma/question folders
               2) append to existing files
               3) keep value as-is (or null if not given)
            */
            foreach ($request->file('files', []) as $question_id => $uploaded_files) {
                $new_files_for_question = [];

                foreach ($uploaded_files as $uploaded_file) {
                    if (!$uploaded_file) continue;

                    $uuid        = (string) Str::uuid();
                    $ext         = $uploaded_file->getClientOriginalExtension();
                    $filename    = $uuid . '.' . ($ext ?: 'bin');

                    $storage_path = $uploaded_file->storeAs("uploads/proformas/{$user->id}", $filename, 'public');

                    $new_files_for_question[] = [
                        'file_id' => $uuid,
                        'path'    => $storage_path,
                        'url'     => asset("storage/{$storage_path}"),
                        'name'    => $uploaded_file->getClientOriginalName(),
                        'mime'    => $uploaded_file->getClientMimeType(),
                        'size'    => $uploaded_file->getSize(),
                    ];
                }

                $existing_files = $answers[$question_id]['files'] ?? [];
                if (!is_array($existing_files)) {
                    $existing_files = [];
                }

                $answers[$question_id]['files'] = array_values(array_merge($existing_files, $new_files_for_question));
                $answers[$question_id]['value'] = $answers[$question_id]['value'] ?? null;
                
            }


            $application->form_answers_json = $answers;

            if ($request->application_type === 'claim') {
                $application->eligibility_application_id = $request->input('eligibility_application_id');
                $application->claim_type = $request->input('claim_type');
                $application->claim_period_start = $request->input('claim_period_start');
                $application->claim_period_end   = $request->input('claim_period_end');
            }

            if (!$application->exists) {
                $application->workflow_status = 'draft';
            }

            $application->save();

            if (!$is_save_only) {
                $required_questions = ProformaQuestionnaire::where('proforma_id', $request->proforma_id)
                    ->where('status', 1)
                    ->where('is_required', 'yes')
                    ->pluck('id')
                    ->all();

                $existing_questions = $answers;

                $missing_questions = [];

                foreach ($required_questions as $required_question) {
                    if (!array_key_exists($required_question, $existing_questions)) {
                        $missing_questions[] = $required_question;
                    }
                }

                if (!empty($missing_questions)) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 0,
                        'message' => 'Some required fields are missing.',
                        'missing_question_ids' => $missing_questions
                    ], 422);
                }

                if (empty($application->application_no)) {
                    $application->application_no = 'INE-' . date('y') . '-' . str_pad((string)$application->id, 6, '0', STR_PAD_LEFT);
                }

                $previous_workflow_status = $application->workflow_status ?? 'draft';
                $application->workflow_status = 'under_review_da';
                $application->submitted_at    = now();
                $application->save();

                IncentiveWorkflowHistory::insert([
                    'application_id' => $application->id,
                    'from_status'    => $previous_workflow_status,
                    'to_status'      => 'under_review_da',
                    'action'         => 'submitted',
                    'action_taken_by' => $user->id,
                    'remarks'        => $request->input('remarks'),
                    'meta'           => null,
                    'action_taken_at' => now(),
                ]);

                DB::commit();

                return response()->json([
                    'status'  => 1,
                    'message' => 'Application Submitted successfully.',
                    'data'    => $application,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Draft saved successfully.',
                'data'    => $application,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function user_incentive_scheme_list(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $data = Scheme::select('id', 'code', 'title')->get();

            return response()->json([
                'status'  => 1,
                'message' => 'Schemes fetched successfully.',
                'data'    => $data,
            ]);
        } catch (\Exception $e) {

            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }


    public function user_eligibility_proforma_list(Request $request)
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'scheme_id' => ['required', 'integer', 'exists:schemes,id'],
            ]);

            $user_id = Auth::id();

            $eligibility_proformas = Proforma::query()
                ->where('scheme_id', $request->scheme_id)
                ->where('proforma_type', 'eligibility')
                ->where('status', 1)
                ->orderBy('display_order')
                ->orderBy('id', 'desc')
                ->with('applications', function ($q) use ($request, $user_id) {
                    $q->where('user_id', $user_id)
                        ->where('scheme_id', $request->scheme_id)
                        ->where('application_type', 'eligibility')
                        ->orderByDesc('id')
                        ->orderBy('id', 'desc')
                        ->select('id', 'proforma_id', 'application_no', 'submitted_at', 'decided_at', 'workflow_status');
                })
                ->select('id', 'scheme_id', 'code', 'title', 'description')
                ->get();

            if ($eligibility_proformas->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No proforma found for the given scheme_id.',
                ], 404);
            }

            $response_data = $eligibility_proformas->map(function ($proforma) {
                $application = $proforma->applications->first();

                return [
                    'proforma_id'   => $proforma->id,
                    'application_code' => $proforma->code,
                    'application_type' => $proforma->title,
                    'application_id'   => $application?->application_no,
                    'applied_on'       => $application?->submitted_at?->format('d/m/Y'),
                    'certificate_issued_or_rejected_on' => $application?->decided_at?->format('d/m/Y'),
                    'workflow_status' => $application?->workflow_status,
                ];
            });

            return response()->json([
                'status' => 1,
                'message' => 'Eligibility proforma fetched successfully.',
                'data' => $response_data,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function user_claim_proforma_list(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'scheme_id' => ['required', 'integer', 'exists:schemes,id'],
            ]);

            $user_id = Auth::id();

            // fetch claim proformas + last user application for this scheme
            $claim_proformas = Proforma::query()
                ->where('scheme_id', $request->scheme_id)
                ->where('proforma_type', 'claim')
                ->where('status', 1)
                ->orderBy('display_order')
                ->orderBy('id', 'desc')
                ->with(['applications' => function ($q) use ($request, $user_id) {
                    $q->where('user_id', $user_id)
                        ->where('scheme_id', $request->scheme_id)
                        ->where('application_type', 'claim')
                        ->orderByDesc('id')
                        ->select('id', 'proforma_id', 'application_no', 'submitted_at', 'decided_at', 'workflow_status', 'claim_type', 'claim_period_start', 'claim_period_end');
                }])
                ->select('id', 'scheme_id', 'code', 'title', 'description', 'claim_type', 'depends_on_proforma_ids')
                ->get();

            if ($claim_proformas->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No claim proforma found for the given scheme_id.',
                ], 404);
            }

            $response_data = $claim_proformas->map(function ($proforma) use ($user_id, $request) {
                $last_application = $proforma->applications->first();

                // decode dependency list (JSON or null)
                $deps = [];
                if (!empty($proforma->depends_on_proforma_ids)) {
                    $tmp = json_decode($proforma->depends_on_proforma_ids, true);
                    if (is_array($tmp)) {
                        $deps = $tmp;
                    }
                }

                // does user have approved eligibility from any dependency proforma?
                $eligible_to_claim = true;
                if (!empty($deps)) {
                    $eligible_to_claim = UserIncentiveApplication::query()
                        ->where('user_id', $user_id)
                        ->where('scheme_id', $request->scheme_id)
                        ->where('application_type', 'eligibility')
                        ->where('workflow_status', 'approved')
                        ->whereIn('proforma_id', $deps)
                        ->exists();
                }

                // already claimed check for one_time (UX flag)
                $already_claimed_one_time = false;
                if ($proforma->claim_type === 'one_time') {
                    $already_claimed_one_time = UserIncentiveApplication::query()
                        ->where('user_id', $user_id)
                        ->where('scheme_id', $request->scheme_id)
                        ->where('proforma_id', $proforma->id)
                        ->where('application_type', 'claim')
                        ->where('claim_type', 'one_time')
                        ->whereIn('workflow_status', [
                            'draft',
                            'submitted',
                            'under_review_da',
                            'under_review_gm',
                            'approved',
                            'sent_back'
                        ])
                        ->exists();
                }

                return [
                    'proforma_id'        => $proforma->id,
                    'application_code'   => $proforma->code,
                    'application_type'   => $proforma->title,
                    'claim_type'         => $proforma->claim_type,
                    'eligible_to_claim'  => $eligible_to_claim,         // frontend: enable/disable Apply
                    'already_claimed_one_time' => $already_claimed_one_time, // frontend: hide/disable for one-time
                    'last_application_no' => $last_application?->application_no,
                    'last_applied_on'    => $last_application?->submitted_at?->format('d/m/Y'),
                    'last_decided_on'    => $last_application?->decided_at?->format('d/m/Y'),
                    'last_status'        => $last_application?->workflow_status,
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Claim proforma fetched successfully.',
                'data'    => $response_data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function user_proforma_questionnaire_view(Request $request)
    {
        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'proforma_id' => 'required|integer|exists:proformas,id',
            ]);

            $proforma_questionnaires = ProformaQuestionnaire::where('proforma_id', $request->proforma_id)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

            foreach ($proforma_questionnaires as $question) {
                $question->upload_rule = $question->upload_rule
                    ? json_decode($question->upload_rule, true)
                    : null;
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Proforma questionnaires fetched successfully.',
                'data'    => $proforma_questionnaires,
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }
}
