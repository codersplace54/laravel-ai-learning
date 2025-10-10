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
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

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

            $request->validate([
                'save_data'        => 'required|integer|in:0,1',
                'scheme_id'        => 'required|integer|exists:schemes,id',
                'proforma_id'      => 'required|integer|exists:proformas,id',
                'application_type' => 'required|in:eligibility,claim',
                'eligibility_application_id' => 'exclude_unless:application_type,claim|required_unless:save_data,1|integer|exists:user_incentive_applications,id',
                'claim_type'                 => 'exclude_unless:application_type,claim|required_unless:save_data,1|in:one_time,monthly,quarterly,half_yearly,annually,biennially,triennially,quinquenially',
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

            $application = UserIncentiveApplication::firstOrNew($find_application);

            $existing_answers = $application->form_answers_json;

            if (is_string($existing_answers)) {
                $existing_answers = json_decode($existing_answers, true) ?: [];
            }
            if (!is_array($existing_answers)) {
                $existing_answers = [];
            }

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

            $user_id = Auth::id();

            // for eligibility
            $user_eligibility_applications = UserIncentiveApplication::query()
                ->where('user_id', $user_id)
                ->where('application_type', 'eligibility')
                ->where('workflow_status', 'approved')
                ->orderByDesc('decided_at')
                ->get();

            $eligible_claim_proforma_ids = [];

            foreach ($user_eligibility_applications as $application) {
                $proforma_ids = Proforma::whereJsonContains('depends_on_proforma_ids', $application->proforma_id)
                    ->where('status', 1)
                    ->pluck('id')
                    ->toArray();

                $eligible_claim_proforma_ids = array_merge($eligible_claim_proforma_ids, $proforma_ids);
            }

            // for claim
            $user_claim_applications = UserIncentiveApplication::query()
                ->where('user_id', $user_id)
                ->where('application_type', 'claim')
                ->where('workflow_status', 'approved')
                ->orderByDesc('decided_at')
                ->with('proforma')
                ->get()
                ->unique('proforma_id')
                ->values();

            /* 
                decided_at => newest first
                unique('proforma_id') => the latest claim
                values() => reindex
            */

            $claim_period_months = [
                'monthly'     => 1,
                'quarterly'   => 3,
                'half_yearly' => 6,
                'annually'    => 12,
                'biennially'  => 24,
                'triennially' => 36,
                'quinquenially' => 60,
            ];

            foreach ($user_claim_applications as $application) {

                $claim_type = $application->claim_type;

                if ($application->proforma->status != 1 || $claim_type === 'one_time') {
                    continue;
                }

                if (!is_null($application->remaining_claim) && $application->remaining_claim < 1) {
                    continue;
                }
                $months_gap = $claim_period_months[$claim_type] ?? null;

                // Later need to handle for processing application
                if (!$months_gap || empty($application->decided_at)) {
                    continue;
                }

                $last_claim_approved_on = Carbon::parse($application->decided_at);
                $next_claim_allowed_on  = $last_claim_approved_on->copy()->addMonths($months_gap);

                if (now()->greaterThanOrEqualTo($next_claim_allowed_on)) {
                    $eligible_claim_proforma_ids[] = $application->proforma->id;
                }
            }

            $eligible_claim_proforma_ids = array_values($eligible_claim_proforma_ids);

            $eligible_proformas = Proforma::whereIn('id', $eligible_claim_proforma_ids)->get();

            return response()->json([
                'status'  => 1,
                'message' => 'Claim proforma fetched successfully.',
                'data'    => $eligible_proformas,
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
                'application_id' => 'required_without:proforma_id|nullable|integer|exists:user_incentive_applications,id',
                'proforma_id'    => 'required_without:application_id|nullable|integer|exists:proformas,id',
            ]);

            $application = null;
            $proforma    = null;
            $answers     = [];

            if ($request->filled('application_id')) {
                $application = UserIncentiveApplication::where('id', $request->application_id)
                    ->with('proforma')
                    ->first();

                $proforma = $application->proforma;

                $answers = $application->form_answers_json ?? [];
            } else {
                $proforma = Proforma::where('id', $request->proforma_id)->first();
            }

            $questions = ProformaQuestionnaire::where('proforma_id', $proforma->id)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

            $questions_with_answers = $questions->map(function ($question) use ($answers) {
                return [
                    'id'             => $question->id,
                    'question_label' => $question->question_label,
                    'question_type'  => $question->question_type,
                    'is_required'    => $question->is_required,
                    'options'        => $question->options,
                    'default_value'  => $question->default_value,
                    'group_label'    => $question->group_label,
                    'display_width'  => $question->display_width,
                    'display_order'  => $question->display_order,
                    'upload_rule'    => $question->upload_rule ? json_decode($question->upload_rule, true) : null,
                    'value'          => $answers[$question->id]['value'] ?? null,
                    'files'          => $answers[$question->id]['files'] ?? [],
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Proforma questions with user answers fetched successfully.',
                'data'    => [
                    'application' => $application ? [
                        'application_id'   => $application->id,
                        'application_no'   => $application->application_no,
                        'workflow_status'  => $application->workflow_status,
                        'application_type' => $application->application_type,
                    ] : null,
                    'questions' => $questions_with_answers,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }


    public function get_department_applications(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'department'     => 'nullable|string|in:DA,GM',
                'status'         => 'nullable|string|in:submitted,approved_by_da,rejected_by_da,sent_back_by_da,approved_by_gm,rejected_by_gm,sent_back_by_gm',
                'scheme_id'      => 'nullable|integer|exists:schemes,id',
                'proforma_id'    => 'nullable|integer|exists:proformas,id',
                'applicant_name' => 'nullable|string|max:255',
                'applicant_phone' => 'nullable|string|max:20',
                'date_from'      => 'nullable|date',
                'date_to'        => 'nullable|date|after_or_equal:date_from',
            ]);

            $applications = UserIncentiveApplication::with(['proforma', 'user']);
            if ($request->department) {
                if ($request->department == 'DA') {
                    $applications->whereIn('workflow_status', ['submitted', 'approved_by_da', 'rejected_by_da', 'sent_back_by_da']);
                } elseif ($request->department == "GM") {
                    $applications->whereIn('workflow_status', ['approved_by_da', 'approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm']);
                }
            }

            if ($request->status) {
                $applications->where('workflow_status', $request->status);
            }

            if ($request->scheme_id) {
                $applications->where('scheme_id', $request->scheme_id);
            }

            if ($request->proforma_id) {
                $applications->where('proforma_id', $request->proforma_id);
            }

            if ($request->applicant_name) {
                $applications->whereHas('user', function ($user) use ($request) {
                    $user->where('authorized_person_name', 'like', '%' . $request->applicant_name . '%');
                });
            }
            if ($request->applicant_phone) {
                $applications->whereHas('user', function ($user) use ($request) {
                    $user->where('mobile_no', 'like', '%' . $request->applicant_phone . '%');
                });
            }

            if ($request->date_from && $request->date_to) {
                $applications->whereBetween('submitted_at', [$request->date_from, $request->date_to]);
            } elseif ($request->date_from) {
                $applications->whereDate('submitted_at', '>=', $request->date_from);
            } elseif ($request->date_to) {
                $applications->whereDate('submitted_at', '<=', $request->date_to);
            }

            $applications = $applications->orderByDesc('submitted_at')->get();

            $data = $applications->map(function ($application) {
                return [
                    'application_id'  => $application->id,
                    'application_no'  => $application->application_no,
                    'applicant_name'  => $application->user->authorized_person_name,
                    'application_type' => $application->application_type,
                    'workflow_status' => $application->workflow_status,
                    'current_reviewer_user_id' => $application->current_reviewer_user_id,
                    'submitted_at'    => optional($application->submitted_at)->toDateTimeString(),
                    'decided_at'      => optional($application->decided_at)->toDateTimeString(),
                ];
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Applications fetched successfully.',
                'data'    => $data,
            ]);
        } catch (\Exception $e) {

            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }


    public function update_application_status(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $allowed_by_department = [
                'DA' => ['approved_by_da', 'rejected_by_da', 'sent_back_by_da'],
                'GM' => ['approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm'],
            ];

            $request->validate([
                'application_id' => 'required|integer|exists:user_incentive_applications,id',
                'department'     => 'required|string|in:DA,GM',
                'new_status'     => ['required', 'string', Rule::in($allowed_by_department[$request->department] ?? [])],
                'remarks'        => 'nullable|string|required_if:new_status,rejected_by_da|required_if:new_status,sent_back_by_da|required_if:new_status,rejected_by_gm|required_if:new_status,sent_back_by_gm',
            ]);

            DB::beginTransaction();

            $application = UserIncentiveApplication::with('proforma')->find($request->application_id);

            $previous_status = $application->workflow_status;
            $new_status      = $request->new_status;

            $application->workflow_status         = $new_status;
            $application->current_reviewer_user_id = Auth::id();

            $final_statuses = ['approved_by_gm', 'rejected_by_da', 'rejected_by_gm'];
            if (in_array($new_status, $final_statuses, true)) {
                $application->decided_at = now();
            }

            if ($new_status === 'approved_by_gm' && $application->application_type === 'eligibility') {
                if (empty($application->eligibility_certificate_no)) {
                    $application->eligibility_certificate_no = 'ELG-' . date('y') . '-' . str_pad((string)$application->id, 6, '0', STR_PAD_LEFT);
                }
            }

            $application->save();

            $action_map = [
                'approved_by_da'  => 'da_approved',
                'rejected_by_da'  => 'da_rejected',
                'sent_back_by_da' => 'da_sent_back',
                'approved_by_gm'  => 'gm_approved',
                'rejected_by_gm'  => 'gm_rejected',
                'sent_back_by_gm' => 'gm_sent_back',
            ];

            IncentiveWorkflowHistory::create([
                'application_id'  => $application->id,
                'from_status'     => $previous_status,
                'to_status'       => $new_status,
                'action'          => $action_map[$new_status] ?? 'status_updated',
                'action_taken_by' => Auth::id(),
                'remarks'         => $request->input('remarks'),
                'meta'            => null,
                'action_taken_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Application status updated successfully.',
                'data'    => [
                    'application_id'  => $application->id,
                    'application_no'  => $application->application_no,
                    'application_type' => $application->application_type,
                    'workflow_status' => $application->workflow_status,
                    'decided_at'      => optional($application->decided_at)->toDateTimeString(),
                    'eligibility_certificate_no' => $application->eligibility_certificate_no,
                ],
            ]);
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
}