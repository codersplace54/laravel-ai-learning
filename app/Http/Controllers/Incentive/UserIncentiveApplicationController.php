<?php

namespace App\Http\Controllers\Incentive;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseDetail;
use App\Models\Incentive;
use App\Models\IncentiveWorkflowHistory;
use Illuminate\Http\Request;
use App\Models\UserIncentiveApplication;
use App\Models\ProformaQuestionnaire;
use App\Models\Proforma;
use App\Models\Scheme;
use App\Models\DepartmentUser;
use App\Models\User;
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
                'application_id'   => 'nullable|integer|exists:user_incentive_applications,id',
                'save_data'        => 'required|integer|in:0,1',
                'proforma_id'      => 'required|integer|exists:proformas,id',
                'files'        => 'nullable|array',
                'files.*'      => 'array',
                'files.*.*'    => 'file|max:10240|mimes:pdf,jpg,jpeg,png,avif,webp',
                'form_answers_json'  => 'required_unless:save_data,1',
            ]);

            if (!$is_save_only) {
                $errors = $this->validate_required_questions($request);

                $this->validate_proforma_file_inputs($request);

                if (!empty($errors)) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'Validation failed.',
                        'errors'  => $errors,
                    ], 422);
                }
            }

            DB::beginTransaction();

            $proforma = Proforma::where('id', $request->proforma_id)->first();

            if (!$is_save_only && $proforma && $proforma->proforma_type === 'claim') {

                $existing_application = UserIncentiveApplication::where('id', $request->application_id)
                    ->where('user_id', $user->id)
                    ->first();

                $is_draft_submission = $existing_application && $existing_application->workflow_status === 'draft';

                // A user could change the proforma_id in the URL to open a claim form they are not eligible for
                $can_apply = $this->can_apply_for_this_claim($user->id, $proforma);
                if ($can_apply == false) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'You cannot submit this claim. Required eligibility application(s) are not approved by GM/SLC.',
                    ], 422);
                }

                if (!$is_draft_submission) {
                    $can_reapply = $this->can_reapply_for_claim($user->id, $proforma);
                    if (!$can_reapply) {
                        return response()->json([
                            'status'  => 0,
                            'message' => 'Re-apply not allowed.',
                        ], 422);
                    }
                }
            }

            $application = UserIncentiveApplication::where('id', $request->application_id)
            ->where('user_id', Auth::id())
            ->first();

            if (!$application) {
                $application = new UserIncentiveApplication();
            }
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

                    $storage_path = $uploaded_file->storeAs("uploads/{$user->id}/incentive_applications", $filename, 'public');

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

                // $answers[$question_id]['files'] = array_values(array_merge($existing_files, $new_files_for_question));
                $answers[$question_id]['files'] = $new_files_for_question ?: $existing_files;

                $answers[$question_id]['value'] = $answers[$question_id]['value'] ?? null;
            }


            $application->form_answers_json = $answers;

            $application->claim_type = $proforma->claim_type;

            $answers_array = $answers;
            $subsidy = $this->build_subsidy_report($request->proforma_id, $answers_array);

            $application->subsidy_report = json_encode($subsidy, JSON_UNESCAPED_UNICODE);

            if (!$application->exists) {
                $application->workflow_status = 'draft';
            }

            $application->application_type = $proforma->proforma_type;
            $application->proforma_id = $proforma->id;
            $application->scheme_id = $proforma->scheme->id;
            $application->user_id = Auth::id();
            $application->district_id = $user->district_id;
            if (!$application->submitted_at) {
                $application->application_date = now();
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

                if (empty($application->application_no) && $request->save_data !== 1 ) {
                    $application->application_no = 'INE-' . date('y') . '-' . str_pad((string)$application->id, 6, '0', STR_PAD_LEFT);
                }


                $previous_status = $application->workflow_status;

                $previous_workflow_status = $application->workflow_status ?? 'draft';

                if (in_array($previous_workflow_status, ['sent_back_by_da', 'sent_back_by_gm', 'sent_back_by_slc'])) {
                    $application->workflow_status = 're_submitted';
                } else {
                    $application->workflow_status = 'submitted';
                }
                if (!$application->submitted_at) {
                    $application->submitted_at = now();
                }
                $application->user_id = Auth::id();
                $application->save();

                if ($application->workflow_status == 'submitted' && ($previous_status == 'draft' || $previous_status == null)) {
                    $used_count = UserIncentiveApplication::where('user_id', Auth::id())
                        ->where('proforma_id', $proforma->id)
                        ->where('application_type', 'claim')
                        ->count();


                    $application->remaining_claim = $proforma->max_claim_count - $used_count;
                    $application->save();
                }

                IncentiveWorkflowHistory::insert([
                    'application_id' => $application->id,
                    'from_status'    => $previous_workflow_status,
                    'to_status'      => $application->workflow_status,
                    'action_taken_by' => $user->id,
                    'remarks'        => $request->input('remarks'),
                    'action_taken_at' => now(),
                ]);

                DB::commit();

                $application->subsidy_report = json_decode($application->subsidy_report, true);
                return response()->json([
                    'status'  => 1,
                    'message' => 'Application Submitted successfully.',
                    'data'    => array_merge($application->toArray(), ['application_id' => $application->id]),
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Draft saved successfully.',
                'data'    => array_merge($application->toArray(), ['application_id' => $application->id]),
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

            $dob = User::where('id', Auth::id())->value('dob');

            if (!$dob) {
                return response()->json(
                    [
                        'data'    => [],
                        'status' => 1,
                        'message' => 'Date of business establishment not found.'
                    ],
                    422
                );
            }

            $data = Scheme::query()
                ->where('status', 1)
                ->whereDate('policy_start_date', '<=',  $dob)
                ->whereDate('policy_end_date', '>=', $dob)
                ->select('id', 'code', 'title')->get();

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
                'scheme_id'      => ['required', 'integer', 'exists:schemes,id'],
                'application_no' => 'nullable|string|max:50',
                'application_code' => 'nullable|string|max:100',
                'proforma_name'  => 'nullable|string|max:255',
                'status'         => 'nullable|string',
            ]);

            $user_id = Auth::id();

            $eligibility_proformas = Proforma::query()
                ->where('scheme_id', $request->scheme_id)
                ->where('proforma_type', 'eligibility')
                ->where('status', 1)
                ->when($request->filled('application_code'), fn($q) => $q->where('code', 'like', '%' . $request->application_code . '%'))
                ->when($request->filled('proforma_name'), fn($q) => $q->where('title', 'like', '%' . $request->proforma_name . '%'))
                ->orderBy('display_order')
                ->orderBy('id', 'desc')
                ->with(['applications' => function ($q) use ($request, $user_id) {
                    $q->where('user_id', $user_id)
                        ->where('scheme_id', $request->scheme_id)
                        ->where('application_type', 'eligibility')
                        ->when($request->filled('application_no'), fn($q) => $q->where('application_no', 'like', '%' . $request->application_no . '%'))
                        ->when($request->filled('status'), fn($q) => $q->where('workflow_status', $request->status))
                        ->orderByDesc('id')
                        ->select('id', 'proforma_id', 'application_no', 'submitted_at', 'decided_at', 'workflow_status', 'eligibility_certificate_no', 'eligibility_certificate_path');
                }])
                ->select('id', 'scheme_id', 'code', 'title', 'description')
                ->get();

            if ($eligibility_proformas->isEmpty()) {
                return response()->json([
                    'data' => [],
                    'status' => 1,
                    'message' => 'No proforma found for the given scheme_id.',
                ], 404);
            }

            $response_data = $eligibility_proformas->map(function ($proforma) {
                $application = $proforma->applications->first();

                return [
                    'application_id'   => $application?->id,
                    'proforma_id'   => $proforma->id,
                    'application_code' => $proforma->code,
                    'application_type' => $proforma->title,
                    'proforma_details' => $proforma->description,
                    'application_no'   => $application?->application_no,
                    'applied_on'       => $application?->submitted_at?->format('d/m/Y'),
                    'certificate_issued_or_rejected_on' => $application?->decided_at?->format('d/m/Y'),
                    'workflow_status'  => $application ? $this->status_label($application->workflow_status) : null,
                    'raw_status'  => $application?->workflow_status,
                    'is_editable'     => $application ? $this->is_application_editable($application) : false,
                    'eligibility_certificate_no'   => $application?->eligibility_certificate_no,
                    'eligibility_certificate_path' => $application?->eligibility_certificate_path ? asset('storage/' . $application->eligibility_certificate_path) : null,
                ];
            });

            if ($request->filled('application_no') || $request->filled('status')) {
                $response_data = $response_data->filter(fn($item) => $item['application_id'] !== null)->values();
            }

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

            $user_eligibility_applications = UserIncentiveApplication::query()
                ->where('user_id', $user_id)
                ->where('application_type', 'eligibility')
                ->where('workflow_status', 'noc_issued')
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

            // only keep claim proformas where all depends_on_proforma_ids are noc_issued by this user
            $noc_issued_proforma_ids = $user_eligibility_applications->pluck('proforma_id')->toArray();

            $eligible_claim_proforma_ids = array_filter($eligible_claim_proforma_ids, function ($claim_proforma_id) use ($noc_issued_proforma_ids) {
                $depends_on = Proforma::where('id', $claim_proforma_id)->value('depends_on_proforma_ids');
                $depends_on = json_decode($depends_on, true) ?? [];
                return count($depends_on) > 0 && count(array_diff($depends_on, $noc_issued_proforma_ids)) === 0;
            });

            $eligible_claim_proforma_ids = array_values(array_unique($eligible_claim_proforma_ids));

            // also include proformas where user already has a claim application (to show existing applications)
            $user_claim_proform_ids = UserIncentiveApplication::query()
                ->where('user_id', $user_id)
                ->where('application_type', 'claim')
                ->orderByDesc('decided_at')
                ->pluck('proforma_id')
                ->unique()
                ->values()
                ->toArray();

            // only merge claim proforma ids that are also fully eligible
            $user_claim_proform_ids = array_filter($user_claim_proform_ids, function ($claim_proforma_id) use ($noc_issued_proforma_ids) {
                $depends_on = Proforma::where('id', $claim_proforma_id)->value('depends_on_proforma_ids');
                $depends_on = json_decode($depends_on, true) ?? [];
                return count($depends_on) > 0 && count(array_diff($depends_on, $noc_issued_proforma_ids)) === 0;
            });

            $eligible_claim_proforma_ids = array_values(array_unique(array_merge($eligible_claim_proforma_ids, $user_claim_proform_ids)));

            $request->validate([
                'application_no'   => 'nullable|string|max:50',
                'application_code' => 'nullable|string|max:100',
                'proforma_name'    => 'nullable|string|max:255',
                'status'           => 'nullable|string',
            ]);

            $claim_proformas = Proforma::query()
                ->whereIn('id', $eligible_claim_proforma_ids)
                ->where('status', 1)
                ->where('proforma_type', 'claim')
                ->when($request->filled('application_code'), fn($q) => $q->where('code', 'like', '%' . $request->application_code . '%'))
                ->when($request->filled('proforma_name'), fn($q) => $q->where('title', 'like', '%' . $request->proforma_name . '%'))
                ->orderBy('display_order')
                ->orderByDesc('id')
                ->with(['applications' => function ($q) use ($user_id, $request) {
                    $q->where('user_id', $user_id)
                        ->when($request->filled('application_no'), fn($q) => $q->where('application_no', 'like', '%' . $request->application_no . '%'))
                        ->when($request->filled('status'), fn($q) => $q->where('workflow_status', $request->status))
                        ->orderByDesc('id')
                        ->select('id', 'proforma_id', 'application_no', 'submitted_at', 'decided_at', 'workflow_status', 'user_id', 'subsidy_report', 'remaining_claim', 'application_type');
                }])
                ->select('id', 'scheme_id', 'code', 'title', 'description', 'claim_type', 'max_claim_count')
                ->get();

            $response_data = [];

            foreach ($claim_proformas as $proforma) {

                $can_reapply_for_claim = $this->can_reapply_for_claim($user_id, $proforma);
                $has_applications = isset($proforma->applications) && count($proforma->applications) > 0;

                $send_back_statuses = ['sent_back_by_da', 'sent_back_by_gm', 'sent_back_by_slc'];

                if (!$has_applications) {
                    $response_data[] = [
                        'application_id'   => null,
                        'scheme_id'        => $proforma->scheme_id,
                        'proforma_id'      => $proforma->id,
                        'application_code' => $proforma->code,
                        'application_type' => $proforma->title,
                        'proforma_details' => $proforma->description,
                        'claim_type'       => $proforma->claim_type,
                        'application_no'   => null,
                        'applied_on'       => null,
                        'approved_on'      => null,
                        'workflow_status'  => null,
                        'raw_status'       => null,
                        'is_editable'      => false,
                        'is_send_back'     => false,
                        'can_reapply'      => false,
                        'claimed_amount'   => null,
                        'approved_amount'  => null,
                        'disbursed_amount' => null,
                        'remaining_claim'  => null,
                    ];
                    continue;
                }

                $application = $proforma->applications->first();
                if ($application) {
                    $subsidyReport = json_decode($application->subsidy_report);

                    $claimed  = $subsidyReport->totals->claimed ?? 0;
                    $approved = $subsidyReport->totals->approved ?? 0;
                    $disbursed = $subsidyReport->totals->disbursed ?? 0;

                    $response_data[] = [
                        'application_id'   => $application->id,
                        'scheme_id'        => $proforma->scheme_id,
                        'proforma_id'      => $proforma->id,
                        'application_code' => $proforma->code,
                        'application_type' => $proforma->title,
                        'proforma_details' => $proforma->description,
                        'claim_type'       => $proforma->claim_type,
                        'application_no'   => $application->application_no,
                        'applied_on'       => $application->submitted_at ? $application->submitted_at->format('d/m/Y') : null,
                        'approved_on'      => $application->decided_at ? $application->decided_at->format('d/m/Y') : null,
                        'workflow_status'  => $application ? $this->status_label($application->workflow_status) : null,
                        'raw_status'       => $application?->workflow_status,
                        'is_editable'      => $this->is_application_editable($application),
                        'is_send_back'     => in_array($application->workflow_status, $send_back_statuses, true),
                        'can_reapply'      => $can_reapply_for_claim,
                        'claimed_amount'   => $claimed,
                        'approved_amount'  => $approved,
                        'disbursed_amount' => $disbursed,
                        'remaining_claim'  => $application->remaining_claim,
                    ];
                }
            }

            if ($request->filled('application_no') || $request->filled('status')) {
                $response_data = array_values(array_filter($response_data, fn($item) => $item['application_id'] !== null));
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Claim proforma fetched successfully.',
                'data'    => $response_data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
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
                $proforma = Proforma::where('id', $request->proforma_id)->where('status', 1)->first();
            }

            $questions = ProformaQuestionnaire::where('proforma_id', $proforma->id)
                ->where('status', 1)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

            $questions_with_answers = $questions->map(function ($question) use ($answers) {
                $data = $question->toArray();
                $data['upload_rule'] = $question->upload_rule
                    ? json_decode($question->upload_rule, true)
                    : null;

                $data['sample_format'] = $question->sample_format
                    ? asset(Storage::url($question->sample_format))
                    : null;

                $data['value'] = $answers[$question->id]['value'] ?? null;
                $data['files'] = $answers[$question->id]['files'] ?? [];

                return $data;
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
                        'is_editable'      => $this->is_application_editable($application),
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
                'department'     => 'nullable|string|in:DA,GM,SLC',
                'status' => 'nullable|string|in:submitted,re_submitted,approved_by_da,rejected_by_da,sent_back_by_da,noc_issued,claim_approved_by_gm,rejected_by_gm,sent_back_by_gm,under_review_slc,claim_approved_by_slc,rejected_by_slc,sent_back_by_slc',                
                'scheme_id'      => 'nullable|integer|exists:schemes,id',
                'proforma_id'    => 'nullable|integer|exists:proformas,id',
                'applicant_name' => 'nullable|string|max:255',
                'applicant_phone' => 'nullable|string|max:20',
                'date_from'      => 'nullable|date',
                'date_to'        => 'nullable|date|after_or_equal:date_from',
            ]);

            $user = auth()->user();

            $department_users = DepartmentUser::where('user_id', $user->id)->get();

            $designation = $department_users->first()?->designation;

            if (!$designation) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No department/designation mapped to your account. Contact admin to assign one.',
                ]);
            }

            $is_slc = $designation === 'State Level Committee';

            $applications = UserIncentiveApplication::with(['proforma', 'user.district']);

            if (!$is_slc) {
                $assigned_district_codes = $department_users->pluck('district_id')->filter()->unique()->values()->all();
                $applications->whereIn(DB::raw('COALESCE(user_incentive_applications.district_id, 272)'), $assigned_district_codes);
            }


            if ($designation == 'Dealing Assistant') {

                $applications->whereIn('workflow_status', ['submitted','re_submitted', 'approved_by_da', 'rejected_by_da', 'sent_back_by_da']);
            } elseif ($designation == "General Manager") {

                $applications->whereIn('workflow_status', ['approved_by_da', 'noc_issued', 'claim_approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm', 'under_review_slc']);
            } elseif ($designation == "State Level Committee") {

                $applications->whereIn('workflow_status', ['under_review_slc', 'claim_approved_by_slc', 'rejected_by_slc', 'sent_back_by_slc']);
            } else {

                return response()->json([
                    'status'  => 0,
                    'message' => 'Invalid designation. Only Dealing Assistant, General Manager, or State Level Committee can access applications.',
                ]);
            }

            if ($request->filled('status')) {
                $applications->where('workflow_status', $request->status);
            }
            if ($request->filled('scheme_id')) {
                $applications->where('scheme_id', $request->scheme_id);
            }
            if ($request->filled('proforma_id')) {
                $applications->where('proforma_id', $request->proforma_id);
            }
            if ($request->filled('applicant_name')) {
                $applications->whereHas('user', fn($q) => $q->where('authorized_person_name', 'like', '%' . $request->applicant_name . '%'));
            }
            if ($request->filled('applicant_phone')) {
                $applications->whereHas('user', fn($q) => $q->where('mobile_no', 'like', '%' . $request->applicant_phone . '%'));
            }
            if ($request->filled('date_from')) {
                $applications->whereDate('submitted_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
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

            $request->validate([
                'application_id' => 'required|integer|exists:user_incentive_applications,id',
                'new_status'     => 'required | string',
                'remarks'        => 'nullable|string'
                    . '|required_if:new_status,rejected_by_da'
                    . '|required_if:new_status,sent_back_by_da'
                    . '|required_if:new_status,rejected_by_gm'
                    . '|required_if:new_status,sent_back_by_gm'
                    . '|required_if:new_status,rejected_by_slc'
                    . '|required_if:new_status,sent_back_by_slc',
                'approved_items' => 'nullable',
                'review_file' => 'nullable|file',
                'eligibility_certificate_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
            ]);

            $application_type = UserIncentiveApplication::where('id', $request->application_id)->value('application_type');
            $is_gm_noc_issuance = $request->new_status === 'noc_issued' && $application_type === 'eligibility';

            if ($is_gm_noc_issuance && !$request->hasFile('eligibility_certificate_file')) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Validation failed.',
                    'errors'  => ['eligibility_certificate_file' => ['The eligibility certificate file is required when issuing NOC.']],
                ], 422);
            }

            DB::beginTransaction();

            $new_status = $request->new_status;

            $user = User::with('department_user')->find(Auth::id());

            $designation = $user?->department_user?->designation;

            if ($designation === 'Dealing Assistant') {

                $allowed_statuses = ['approved_by_da', 'rejected_by_da', 'sent_back_by_da'];
            } elseif ($designation === 'General Manager') {

                $allowed_statuses = ['noc_issued', 'claim_approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm'];
            } elseif ($designation === 'State Level Committee') {

                $allowed_statuses = ['claim_approved_by_slc', 'rejected_by_slc', 'sent_back_by_slc'];
            } else {

                return response()->json([
                    'status'  => 0,
                    'message' => 'Invalid designation. Only DA, GM, or SLC can update status.',
                ], 422);
            }


            if (!in_array($new_status, $allowed_statuses, true)) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'You do not have authority to set this status.',
                ], 422);
            }

            $approved_items = $request->input('approved_items');

            $application = UserIncentiveApplication::with('proforma')->find($request->application_id);

            $previous_status = $application->workflow_status;
            $new_status      = $request->new_status;

            $application->current_reviewer_user_id = Auth::id();

            if (($new_status === 'noc_issued') && $application->application_type === 'eligibility') {
                if (empty($application->eligibility_certificate_no)) {
                    $application->eligibility_certificate_no = 'ELG-' . date('y') . '-' . str_pad((string)$application->id, 6, '0', STR_PAD_LEFT);
                }
            }

            if (($new_status === 'claim_approved_by_gm') && $application->application_type === 'claim') {

                if ($application->subsidy_report) {
                    $report = json_decode($application->subsidy_report, true) ?: [];

                    if (!empty($report['subsidy_items'])) {

                        $approved_total = 0.0;

                        foreach ($report['subsidy_items'] as &$subsidy_item) {

                            if (!isset($subsidy_item['approved']) || $subsidy_item['approved'] === null) {
                                $subsidy_item['approved'] = $subsidy_item['claimed'];
                            }

                            if (!isset($subsidy_item['status']) || $subsidy_item['status'] === 'eligible') {
                                $subsidy_item['status'] = 'approved';
                            }

                            $approved_total += $subsidy_item['approved'];
                        }

                        unset($subsidy_item);

                        $report['totals']['approved'] = round($approved_total, 2);

                        $application->subsidy_report = json_encode($report, JSON_UNESCAPED_UNICODE);
                    }
                }
            }


            $approved_total = 0.0;

            if (
                in_array($new_status, ['claim_approved_by_gm', 'claim_approved_by_slc', 'approved_by_da'], true)
                && $application->application_type === 'claim'
                && $application->subsidy_report
                && !empty($approved_items)
            ) {

                $report = json_decode($application->subsidy_report, true) ?: [];

                if (!empty($report['subsidy_items']) && is_array($report['subsidy_items'])) {
                    $approved_total = 0.0;

                    foreach ($report['subsidy_items'] as &$item) {
                        $qid = (string) ($item['question_id'] ?? '');

                        if ($approved_items && array_key_exists($qid, $approved_items)) {
                            $approved_value = (float) $approved_items[$qid];
                            if ($approved_value > (float) ($item['claimed'] ?? 0)) {
                                DB::rollBack();
                                return response()->json([
                                    'status'  => 0,
                                    'message' => 'Approved amount cannot exceed claimed amount for "' . ($item['label'] ?? $qid) . '".',
                                ], 422);
                            }
                            $item['approved'] = $approved_value;
                        }

                        $claimed  = ($item['claimed'] ?? 0);
                        $approved = ($item['approved'] ?? 0);

                        if ($approved <= 0) {
                            $item['status'] = 'rejected';
                        } elseif ($approved < $claimed) {
                            $item['status'] = 'partial';
                        } else {
                            $item['status'] = 'approved';
                        }

                        $approved_total += $approved;
                    }
                    unset($item);

                    $report['totals']['approved'] = round($approved_total, 2);
                    $application->subsidy_report  = json_encode($report, JSON_UNESCAPED_UNICODE);
                }

                if ($new_status === 'claim_approved_by_gm' && $approved_total > 500000) {
                    $new_status = 'under_review_slc';
                }
            }

            $application->workflow_status = $new_status;

            $final_statuses = ['rejected_by_slc', 'rejected_by_da', 'rejected_by_gm', 'noc_issued', 'claim_approved_by_slc', 'claim_approved_by_gm'];
            if (in_array($new_status, $final_statuses, true)) {
                $application->decided_at = now();
            }

            if ($request->file('eligibility_certificate_file')) {
                $file = $request->eligibility_certificate_file;
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $eligibility_certificate_path = $file->storeAs("uploads/{$user->id}/incentive_certificates", $filename, 'public');
                $application->eligibility_certificate_path = $eligibility_certificate_path;
            }
                        $application->save();


            if ($request->file('review_file')) {
                $file = $request->review_file;
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $review_file = $file->storeAs("uploads/{$user->id}/incentive_applications", $filename, 'public');
            }

            IncentiveWorkflowHistory::create([
                'application_id'  => $application->id,
                'from_status'     => $previous_status,
                'to_status'       => $new_status,
                'review_file'     => $review_file ?? null,
                'action_taken_by' => Auth::id(),
                'remarks'         => $request->input('remarks'),
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

    public function application_workflow_history(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'application_id' => 'required|integer|exists:user_incentive_applications,id',
            ]);

            $history = IncentiveWorkflowHistory::where('application_id', $request->application_id)
                ->orderBy('action_taken_at')
                ->with(['user:id,authorized_person_name,email_id'])
                ->get()
                ->map(function ($history) {
                    return [
                        'date'        => $history->action_taken_at->format('d/m/Y'),
                        'user_name'   => optional($history->user)->authorized_person_name,
                        'from_status' => $history->from_status ? $this->status_label($history->from_status) : null,
                        'to_status'   => $history->to_status ? $this->status_label($history->to_status) : null,
                        'remarks'     => $history->remarks ?? null,
                    ];
                });

            return response()->json([
                'status'  => 1,
                'message' => 'Application history fetched successfully.',
                'data'    => $history,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function application_details(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'application_id' => 'required|integer|exists:user_incentive_applications,id',
            ]);

            $user = User::with('department_user')->find(Auth::id());
            $designation = $user?->department_user?->designation;

            if (!$designation) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No department/designation mapped to your account. Contact admin.',
                ], 422);
            }

            $application = UserIncentiveApplication::where('id', $request->application_id)->with(['proforma', 'user'])->first();

            if ($designation === 'Dealing Assistant') {
                $allowed = ['submitted', 're_submitted', 'approved_by_da', 'rejected_by_da', 'sent_back_by_da'];
            } elseif ($designation === 'General Manager') {
                $allowed = ['approved_by_da', 'noc_issued', 'claim_approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm', 'under_review_slc'];
            } elseif ($designation === 'State Level Committee') {
                $allowed = ['under_review_slc', 'claim_approved_by_slc', 'rejected_by_slc', 'sent_back_by_slc'];
            } else {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Invalid designation. Only DA, GM, or SLC can view application details.',
                ], 422);
            }

            if (!in_array($application->workflow_status, $allowed, true)) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'You do not have authority to view this application.',
                ], 403);
            }

            $answers = $application->form_answers_json;
            if (is_string($answers)) {
                $answers = json_decode($answers, true) ?: [];
            }
            if (!is_array($answers)) {
                $answers = [];
            }

            $questions = ProformaQuestionnaire::where('proforma_id', $application->proforma->id)
                ->where('status', 1)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

            $questions_with_answers = $questions->map(function ($question) use ($answers) {
                $answer = $answers[$question->id] ?? ['value' => null, 'files' => []];
                return [
                    'question_id' => $question->id,
                    'question'    => $question->question_label,
                    'answer'      => $answer['value'] ?? null,
                    'files'       => $answer['files'] ?? [],
                ];
            });

            $subsidy_report = $application->application_type === 'claim' && $application->subsidy_report
                ? json_decode($application->subsidy_report, true)
                : null;

            $latest_workflow_history = IncentiveWorkflowHistory::where('application_id', $application->id)
                ->orderByDesc('action_taken_at')
                ->orderByDesc('id')
                ->first(['remarks', 'review_file']);

            $designation_statuses = [
                'Dealing Assistant'     => ['approved_by_da', 'rejected_by_da', 'sent_back_by_da'],
                'General Manager'       => ['noc_issued', 'claim_approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm', 'under_review_slc'],
                'State Level Committee' => ['claim_approved_by_slc', 'rejected_by_slc', 'sent_back_by_slc'],
            ];

            $action_already_taken = in_array(
                $application->workflow_status,
                $designation_statuses[$designation] ?? [],
                true
            );

            $data = [
                'id'                           => $application->id,
                'application_no'               => $application->application_no,
                'user_id'                      => $application->user_id,
                'scheme'                       => $application->proforma->scheme->title,
                'proforma'                     => $application->proforma->title,
                'applicant_name'               => $application->user->authorized_person_name,
                'application_type'             => $application->application_type,
                'workflow_status'              => $application->workflow_status,
                'remarks'                      => $latest_workflow_history?->remarks,
                'review_file'                  => $latest_workflow_history?->review_file ? asset('storage/' . $latest_workflow_history->review_file) : null,
                'current_reviewer_user_id'     => $application->current_reviewer_user_id,
                'submitted_at'                 => optional($application->submitted_at)->toDateTimeString(),
                'decided_at'                   => optional($application->decided_at)->toDateTimeString(),
                'eligibility_certificate_no'   => $application->eligibility_certificate_no,
                'eligibility_certificate_path' => $application->eligibility_certificate_path ? asset('storage/' . $application->eligibility_certificate_path) : null,
                'claim_type'                   => $application->claim_type,
                'remaining_claim'              => $application->remaining_claim,
                'claim_calculated'             => $application->claim_calculated,
                'form_answers_json'            => $questions_with_answers,
                ...($application->application_type === 'claim' ? ['subsidy_report' => $subsidy_report] : []),
                'created_at'                   => optional($application->created_at)->toDateTimeString(),
                'updated_at'                   => optional($application->updated_at)->toDateTimeString(),
                'action_already_taken'         => $action_already_taken,
            ];

            return response()->json([
                'status'  => 1,
                'message' => 'Application details fetched successfully.',
                'data'    => $data,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function track_application(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'application_id' => 'required|integer|exists:user_incentive_applications,id',
            ]);

            $application = UserIncentiveApplication::with(['proforma.scheme', 'user'])
                ->find($request->application_id);

            $auth_user   = User::with('department_user')->find(Auth::id());
            $designation = $auth_user?->department_user?->designation;

            if ($designation) {
                $allowed_map = [
                    'Dealing Assistant'     => ['submitted', 're_submitted', 'approved_by_da', 'rejected_by_da', 'sent_back_by_da'],
                    'General Manager'       => ['approved_by_da', 'noc_issued', 'claim_approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm', 'under_review_slc'],
                    'State Level Committee' => ['under_review_slc', 'claim_approved_by_slc', 'rejected_by_slc', 'sent_back_by_slc'],
                ];
                $allowed = $allowed_map[$designation] ?? [];
                if (!empty($allowed) && !in_array($application->workflow_status, $allowed, true)) {
                    return response()->json(['status' => 0, 'message' => 'You do not have authority to track this application.'], 403);
                }
            } else {
                if ($application->user_id !== Auth::id()) {
                    return response()->json(['status' => 0, 'message' => 'You do not have authority to track this application.'], 403);
                }
            }

            $history = IncentiveWorkflowHistory::where('application_id', $application->id)
                ->orderBy('action_taken_at')
                ->with('user:id,authorized_person_name,email_id')
                ->get();

            $latest       = $history->last();
            $last_approved = $history->whereIn('to_status', ['approved_by_da', 'noc_issued', 'claim_approved_by_gm', 'claim_approved_by_slc'])->last();

            $history_data = $history->map(function ($h) {
                return [
                    'id'                    => $h->id,
                    'from_status'           => $h->from_status,
                    'from_status_label'     => $this->status_label($h->from_status),
                    'to_status'             => $h->to_status,
                    'to_status_label'       => $this->status_label($h->to_status),
                    'remarks'               => $h->remarks,
                    'review_file'           => $h->review_file ? asset('storage/' . $h->review_file) : null,
                    'action_taken_at'       => optional($h->action_taken_at)->toDateTimeString(),
                    'action_taken_by'       => optional($h->user)->authorized_person_name,
                    'action_taken_email_id' => optional($h->user)->email_id,
                    'is_completed'          => true,
                ];
            })->values();

            $is_claim = $application->application_type === 'claim';
            if ($is_claim) {
                $pipeline = [
                    ['step' => 1, 'role' => 'Dealing Assistant',    'to_status' => 'approved_by_da',        'label' => 'Review by Dealing Assistant'],
                    ['step' => 2, 'role' => 'General Manager',      'to_status' => 'claim_approved_by_gm',  'label' => 'Approval by General Manager'],
                    ['step' => 3, 'role' => 'State Level Committee', 'to_status' => 'claim_approved_by_slc', 'label' => 'Final Approval by SLC (if applicable)'],
                ];
            } else {
                $pipeline = [
                    ['step' => 1, 'role' => 'Dealing Assistant', 'to_status' => 'approved_by_da', 'label' => 'Review by Dealing Assistant'],
                    ['step' => 2, 'role' => 'General Manager',   'to_status' => 'noc_issued',     'label' => 'NOC Issuance by General Manager'],
                ];
            }

            $completed_to_statuses = $history->pluck('to_status')->toArray();

            $terminal_statuses = [
                'rejected_by_da', 'rejected_by_gm', 'rejected_by_slc',
                'noc_issued', 'claim_approved_by_slc', 'claim_approved_by_gm',
            ];
            $is_terminal = in_array($application->workflow_status, $terminal_statuses, true);

            // Append upcoming steps not yet completed
            foreach ($pipeline as $step) {
                $already_done = in_array($step['to_status'], $completed_to_statuses, true);
                if (!$already_done && !$is_terminal) {
                    $history_data->push([
                        'step'                  => $step['step'],
                        'role'                  => $step['role'],
                        'label'                 => $step['label'],
                        'to_status'             => $step['to_status'],
                        'to_status_label'       => $this->status_label($step['to_status']),
                        'from_status'           => null,
                        'from_status_label'     => null,
                        'remarks'               => null,
                        'review_file'           => null,
                        'action_taken_at'       => null,
                        'action_taken_by'       => null,
                        'action_taken_email_id' => null,
                        'is_completed'          => false,
                    ]);
                }
            }

            $data = [
                'application_id'               => $application->id,
                'application_no'               => $application->application_no,
                'applicant_name'               => optional($application->user)->authorized_person_name,
                'application_date'             => $application->application_date,
                'scheme'                       => optional(optional($application->proforma)->scheme)->title,
                'proforma'                     => optional($application->proforma)->title,
                'application_type'             => $application->application_type,
                'workflow_status'              => $application->workflow_status,
                'workflow_status_label'        => $this->status_label($application->workflow_status),
                'submitted_at'                 => optional($application->submitted_at)->toDateTimeString(),
                'decided_at'                   => optional($application->decided_at)->toDateTimeString(),
                'eligibility_certificate_no'   => $application->eligibility_certificate_no,
                'eligibility_certificate_path' => $application->eligibility_certificate_path
                    ? asset('storage/' . $application->eligibility_certificate_path)
                    : null,
                'last_action_by'               => optional(optional($latest)->user)->authorized_person_name,
                'last_action_email'            => optional(optional($latest)->user)->email_id,
                'last_action_at'               => optional(optional($latest)->action_taken_at)->toDateTimeString(),
                'last_approved_by'             => optional(optional($last_approved)->user)->authorized_person_name,
                'last_approved_email'          => optional(optional($last_approved)->user)->email_id,
                'last_approved_at'             => optional(optional($last_approved)->action_taken_at)->toDateTimeString(),
                'history_data'                 => $history_data,
            ];

            return response()->json([
                'status'  => 1,
                'message' => 'Application tracking details fetched successfully.',
                'data'    => $data,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }


    public function preview_subsidy_report(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'proforma_id'       => 'required|integer|exists:proformas,id',
                'form_answers_json' => 'required|string',
            ]);

            $answers = json_decode($request->form_answers_json, true) ?? [];

            $subsidy = $this->build_subsidy_report($request->proforma_id, $answers);

            return response()->json([
                'status'  => 1,
                'message' => 'Subsidy report preview generated successfully.',
                'data'    => $subsidy,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }


    private function build_subsidy_report($proformaId, $answers): array
    {

        $claim_questions = ProformaQuestionnaire::where('proforma_id', $proformaId)
            ->where('status', 1)
            ->where('is_claim', 'yes')
            ->get(['id', 'question_label', 'claim_per_unit', 'claim_percentage']);

        $subsidy_items = [];
        $claim_total = 0.0;

        foreach ($claim_questions as $q) {
            $qid = (string)$q->id;
            $value = $answers[$qid]['value'] ?? null;
            $base = (int)($value);

            $basis = null;
            $claimed = 0.0;
            $rate_per_unit = null;
            $percentage  = null;

            if (!empty($q->claim_per_unit) && $q->claim_per_unit > 0) {
                $basis = 'per_unit';
                $rate_per_unit = $q->claim_per_unit;
                $claimed = round($base * $rate_per_unit, 2);
            } elseif (!empty($q->claim_percentage) && $q->claim_percentage > 0) {
                $basis = 'percentage';
                $percentage = $q->claim_percentage;
                $claimed = round($base * ($percentage / 100), 2);
            } else {
                continue;
            }

            $claim_total += $claimed;

            $subsidy_items[] = [
                'question_id'   => (int)$q->id,
                'label'         => $q->question_label,
                'basis'         => $basis,
                'base_value'    => $base,
                'rate_per_unit' => $rate_per_unit,
                'percentage'    => $percentage,
                'claimed'       => $claimed,
                'approved'      => null,
                'status'        => 'eligible',
                'remarks'       => null,
            ];
        }

        return [
            'subsidy_items' => $subsidy_items,
            'payments' => [],
            'totals' => [
                'claimed'   => round($claim_total, 2),
                'approved'  => 0.0,
                'disbursed' => 0.0,
            ],
        ];
    }

    private function validate_proforma_file_inputs(Request $request): void
    {
        $proforma_id = $request->proforma_id;

        $file_questions = ProformaQuestionnaire::where('proforma_id', $proforma_id)
            ->whereIn('question_type', ['file'])
            ->where('status', 1)
            ->get(['id', 'question_type', 'upload_rule']);

        if ($file_questions->isEmpty()) {
            return;
        }

        $rules = [];

        foreach ($file_questions as $question) {

            $field_key_list  = 'files.' . $question->id;
            $field_key_items = 'files.' . $question->id . '.*';

            $list_rules  = 'nullable|array';
            $item_rules  = 'file';

            $upload_rule = $question->upload_rule;
            if ($upload_rule) {
                $upload_rule = json_decode($upload_rule, true);
            }

            $allowed_mimes = (!empty($upload_rule['mimes'])) ? $upload_rule['mimes'] : [];

            $max_size_mb = isset($upload_rule['max_size_mb']) ? $upload_rule['max_size_mb'] : null;
            $min_files   = isset($upload_rule['min_files'])   ? $upload_rule['min_files']   : null;
            $max_files   = isset($upload_rule['max_files'])   ? $upload_rule['max_files']   : null;

            if (!empty($min_files) && $min_files > 0) {
                $list_rules = 'nullable|array|min:' . $min_files;
            }
            if (!empty($max_files) && $max_files > 0) {
                $list_rules .= '|max:' . $max_files;
            }

            if (!empty($allowed_mimes)) {
                $item_rules .= '|mimes:' . implode(',', $allowed_mimes);
            }

            if (!empty($max_size_mb) && $max_size_mb > 0) {
                $item_rules .= '|max:' . ($max_size_mb * 1024);
            }

            $rules[$field_key_list]  = $list_rules;
            $rules[$field_key_items] = $item_rules;
        }

        if (!empty($rules)) {
            $request->validate($rules);
        }
    }

    private function is_application_editable(UserIncentiveApplication $application): bool
    {
        if ($application->workflow_status === 'draft') {
            return true;
        }
        if (!$application->application_no) {
            return false;
        }
        $editable_statuses = ['sent_back_by_da', 'sent_back_by_gm', 'sent_back_by_slc'];
        return in_array($application->workflow_status, $editable_statuses, true);
    }


    private function status_label(?string $status)
    {
        if ($status === null || $status === '') {
            return $status;
        }

        static $labels = [
            'draft'                 => 'Draft',
            'submitted'             => 'Submitted to DA',
            're_submitted'          => 'Re-submitted to DA',
            'approved_by_da'        => 'Forwarded to GM',
            'sent_back_by_da'       => 'Query raised by DA',
            'rejected_by_da'        => 'Rejected by DA',
            'noc_issued'            => 'Noc Issued',
            'sent_back_by_gm'       => 'Query raised by GM',
            'rejected_by_gm'        => 'Rejected by GM',
            'claim_approved_by_gm'  => 'Approved by GM',
            'under_review_slc'      => 'Under Review SLC',
            'claim_approved_by_slc' => 'Approved by SLC',
            'sent_back_by_slc'      => 'Query raised by SLC',
            'rejected_by_slc'       => 'Rejected by SLC',
        ];

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    private function validate_required_questions(Request $request)
    {
        if ($request->input('save_data') === 1) {
            return [];
        }

        $proforma_id = $request->input('proforma_id');
        $answers = $request->form_answers_json;
        $answers     = json_decode($answers, true);

        $questions = ProformaQuestionnaire::query()
            ->select('id', 'question_label', 'display_order', 'is_required', 'question_type')
            ->where('proforma_id', $proforma_id)
            ->where('status', 1)
            ->where('is_required', 'yes')
            ->orderBy('display_order')
            ->get();
        $errors = [];

        foreach ($questions as $q) {

            $qid   = (string) $q->id;
            $value = $answers[$qid]['value'] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $is_empty = ($value === null) || ($value === '') || (is_array($value) && count($value) === 0);

            if ($is_empty) {
                if ($q->question_type === 'file' && $request->hasFile("files.$qid")) {
                    continue;
                }

                $errors["answers.$qid.value"] = "The '{$q->question_label}' field is required.";
            }
        }
        return $errors;
    }

    private function can_reapply_for_claim(int $user_id, Proforma $proforma): bool
    {
        if ($proforma->claim_type === 'one_time') {
            $already_submitted = UserIncentiveApplication::where('user_id', $user_id)
                ->where('proforma_id', $proforma->id)
                ->where('application_type', 'claim')
                ->whereNotNull('submitted_at')
                ->whereNotIn('workflow_status', ['draft'])
                ->exists();

            return !$already_submitted;
        }

        $gap_months_map = [
            'monthly'       => 1,
            'quarterly'     => 3,
            'half_yearly'   => 6,
            'annually'      => 12,
            'biennially'    => 24,
            'triennially'   => 36,
            'quinquenially' => 60,
        ];
        $gap_months = $gap_months_map[$proforma->claim_type] ?? null;

        if ($gap_months === null) {
            return false;
        }

        $latest = UserIncentiveApplication::select('submitted_at', 'remaining_claim')
            ->where('user_id', $user_id)
            ->where('proforma_id', $proforma->id)
            ->where('application_type', 'claim')
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->first();
        if (!$latest || !$latest->submitted_at) {
            return true;
        }

        if ($latest->remaining_claim !== null && $latest->remaining_claim <= 0) {
            return false;
        }

        $next_allowed_on = Carbon::parse($latest->submitted_at)->addMonths($gap_months);
        return now()->greaterThanOrEqualTo($next_allowed_on);
    }

    private function can_apply_for_this_claim($user_id, Proforma $proforma): bool
    {
        $proforma_depends_on =  json_decode($proforma->depends_on_proforma_ids);

        $approved_eligibilty = UserIncentiveApplication::query()
            ->where('user_id', $user_id)
            ->whereIn('proforma_id', $proforma_depends_on)
            ->where('application_type', 'eligibility')
            ->whereIn('workflow_status', ['noc_issued'])
            ->select('proforma_id')
            ->distinct()
            ->count('proforma_id');

        return $approved_eligibilty === count($proforma_depends_on);
    }

    public function incentive_dashboard(Request $request)
    {
        try {
            $request->validate([
                'scheme_id'          => 'nullable|integer|exists:schemes,id',
                'proforma_id'        => 'nullable|integer|exists:proformas,id',
                'district_code'      => 'nullable|string',
                'application_status' => 'nullable|string',
                'from_date'          => 'nullable|date',
                'to_date'            => 'nullable|date|after_or_equal:from_date',
            ]);

            $base = UserIncentiveApplication::query()
                ->join('proformas', 'proformas.id', '=', 'user_incentive_applications.proforma_id')
                ->join('schemes', 'schemes.id', '=', 'user_incentive_applications.scheme_id')
                ->join('users', 'users.id', '=', 'user_incentive_applications.user_id')
                ->leftJoin('tripura_master_data as tmd', 'tmd.district_code', '=', 'users.district_id')
                ->whereNotNull('user_incentive_applications.submitted_at')
                ->when($request->filled('scheme_id'),          fn($q) => $q->where('user_incentive_applications.scheme_id', $request->scheme_id))
                ->when($request->filled('proforma_id'),        fn($q) => $q->where('user_incentive_applications.proforma_id', $request->proforma_id))
                ->when($request->filled('district_code'),      fn($q) => $q->where('users.district_id', $request->district_code))
                ->when($request->filled('application_status'), fn($q) => $q->where('user_incentive_applications.workflow_status', $request->application_status))
                ->when($request->filled('from_date'),          fn($q) => $q->whereDate('user_incentive_applications.submitted_at', '>=', $request->from_date))
                ->when($request->filled('to_date'),            fn($q) => $q->whereDate('user_incentive_applications.submitted_at', '<=', $request->to_date));

            $all_ids = (clone $base)->pluck('user_incentive_applications.id');

            $total_received  = $all_ids->count();
            $total_approved  = (clone $base)->whereIn('user_incentive_applications.workflow_status', ['noc_issued', 'claim_approved_by_gm', 'claim_approved_by_slc', 'approved_by_da'])->count();
            $total_disbursed = (clone $base)->whereIn('user_incentive_applications.workflow_status', ['claim_approved_by_slc', 'claim_approved_by_gm'])->count();

            $processing_times = UserIncentiveApplication::whereIn('id', $all_ids)
                ->whereNotNull('decided_at')
                ->selectRaw('DATEDIFF(decided_at, submitted_at) as days')
                ->pluck('days')
                ->filter(fn($d) => $d >= 0)
                ->sort()
                ->values();

            $count       = $processing_times->count();
            $avg_time    = $count > 0 ? round($processing_times->avg(), 2) : null;
            $min_time    = $count > 0 ? $processing_times->min() : null;
            $max_time    = $count > 0 ? $processing_times->max() : null;
            $median_time = null;
            if ($count > 0) {
                $mid         = intdiv($count, 2);
                $median_time = $count % 2 === 0
                    ? round(($processing_times[$mid - 1] + $processing_times[$mid]) / 2, 2)
                    : $processing_times[$mid];
            }

            $proforma_rows = (clone $base)
                ->select(
                    'proformas.id as proforma_id',
                    'proformas.title as proforma_name',
                    'schemes.title as scheme_name',
                    DB::raw('COUNT(user_incentive_applications.id) as application_received'),
                    DB::raw('SUM(user_incentive_applications.workflow_status IN ("noc_issued","claim_approved_by_gm","claim_approved_by_slc","approved_by_da")) as approved'),
                    DB::raw('SUM(user_incentive_applications.workflow_status IN ("claim_approved_by_slc","claim_approved_by_gm")) as disbursed'),
                    DB::raw('AVG(CASE WHEN user_incentive_applications.decided_at IS NOT NULL THEN DATEDIFF(user_incentive_applications.decided_at, user_incentive_applications.submitted_at) END) as avg_processing_time'),
                    DB::raw('MIN(CASE WHEN user_incentive_applications.decided_at IS NOT NULL THEN DATEDIFF(user_incentive_applications.decided_at, user_incentive_applications.submitted_at) END) as min_time'),
                    DB::raw('MAX(CASE WHEN user_incentive_applications.decided_at IS NOT NULL THEN DATEDIFF(user_incentive_applications.decided_at, user_incentive_applications.submitted_at) END) as max_time')
                )
                ->groupBy('proformas.id', 'proformas.title', 'schemes.title')
                ->orderBy('proformas.id')
                ->get();

            $proforma_ids = $proforma_rows->pluck('proforma_id');

            $decided_days_by_proforma = UserIncentiveApplication::whereIn('user_incentive_applications.id', $all_ids)
                ->whereNotNull('decided_at')
                ->whereIn('proforma_id', $proforma_ids)
                ->selectRaw('proforma_id, DATEDIFF(decided_at, submitted_at) as days')
                ->get()
                ->groupBy('proforma_id');

            $table_data = $proforma_rows->map(fn($row, $index) => [
                'sl_no'               => $index + 1,
                'proforma_name'       => $row->proforma_name,
                'scheme_name'         => $row->scheme_name,
                'application_received'=> (int) $row->application_received,
                'approved'            => (int) $row->approved,
                'disbursed'           => (int) $row->disbursed,
                'avg_processing_time' => $row->avg_processing_time ? round($row->avg_processing_time, 2) : null,
                'min_time'            => $row->min_time,
                'max_time'            => $row->max_time,
                'median_time'         => $this->calculate_median(
                    ($decided_days_by_proforma[$row->proforma_id] ?? collect())->pluck('days')
                ),
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Incentive dashboard data fetched successfully.',
                'data'    => [
                    'counts'     => [
                        'application_received' => $total_received,
                        'approved'             => $total_approved,
                        'disbursed'            => $total_disbursed,
                        'avg_processing_time'  => $avg_time,
                        'median_time'          => $median_time,
                        'min_time'             => $min_time,
                        'max_time'             => $max_time,
                    ],
                    'table_data' => $table_data,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    private function calculate_median($days): ?float
    {
        $sorted = $days->filter(fn($d) => $d >= 0)->sort()->values();
        $count  = $sorted->count();
        if ($count === 0) return null;
        $mid = intdiv($count, 2);
        return $count % 2 === 0
            ? round(($sorted[$mid - 1] + $sorted[$mid]) / 2, 2)
            : (float) $sorted[$mid];
    }
}
