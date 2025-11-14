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

            $errors = $this->validate_required_questions($request);

            $this->validate_proforma_file_inputs($request);

            if (!empty($errors)) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Validation failed.',
                    'errors'  => $errors,
                ], 422);
            }

            DB::beginTransaction();

            $proforma = Proforma::where('id', $request->proforma_id)->first();

            if (!$is_save_only && $proforma && $proforma->proforma_type === 'claim') {

                // A user could change the proforma_id in the URL to open a claim form they are not eligible for
                $can_apply = $this->can_apply_for_this_claim($user->id, $proforma);
                if ($can_apply == false) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'You cannot submit this claim. Required eligibility application(s) are not approved by GM/SLC.',
                    ], 422);
                }

                // Prevents users from re-submitting claims too soon or beyond allowed count.
                $can_reapply = $this->can_reapply_for_claim($user->id, $proforma);
                if (!$can_reapply) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'Re-apply not allowed.',
                    ], 422);
                }
            }

            $application = UserIncentiveApplication::where('id', $request->application_id)->first();

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


                $previous_status = $application->workflow_status;

                $previous_workflow_status = $application->workflow_status ?? 'draft';

                $application->workflow_status = 'submitted';
                $application->submitted_at    = now();
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
                    'to_status'      => 'submitted',
                    'action_taken_by' => $user->id,
                    'remarks'        => $request->input('remarks'),
                    'action_taken_at' => now(),
                ]);

                DB::commit();

                $application->subsidy_report = json_decode($application->subsidy_report, true);
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

            $proposed_date_of_commissioning = EnterpriseDetail::where('user_id', Auth::id())->value('proposed_date_of_commissioning');

            if (!$proposed_date_of_commissioning) {
                return response()->json(
                    [
                        'data'    => [],
                        'status' => 1,
                        'message' => 'Proposed commissioning date not found.'
                    ],
                    422
                );
            }

            $data = Scheme::query()
                ->whereDate('policy_start_date', '<=',  $proposed_date_of_commissioning)
                ->whereDate('policy_end_date', '>=', $proposed_date_of_commissioning)
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
                    'workflow_status' => $application?->workflow_status,
                    'is_editable'     => $application  ? $this->is_application_editable($application) : true,
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

    public function user_claim_proforma_list()
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $user_id = Auth::id();

            $user_eligibility_applications = UserIncentiveApplication::query()
                ->where('user_id', $user_id)
                ->where('application_type', 'eligibility')
                ->whereIn('workflow_status', ['approved_by_gm', 'approved_by_slc'])
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

            $user_claim_proform_ids = UserIncentiveApplication::query()
                ->where('user_id', $user_id)
                ->where('application_type', 'claim')
                ->orderByDesc('decided_at')
                ->with('proforma')
                ->get()
                ->unique('proforma_id')
                ->values()
                ->pluck('proforma_id')
                ->toArray();

            $eligible_claim_proforma_ids = array_merge($eligible_claim_proforma_ids, $user_claim_proform_ids);

            $claim_proformas = Proforma::query()
                ->whereIn('id', $eligible_claim_proforma_ids)
                ->where('status', 1)
                ->where('proforma_type', 'claim')
                ->orderBy('display_order')
                ->orderByDesc('id')
                ->with(['applications' => function ($q) use ($user_id) {
                    $q->where('user_id', $user_id)
                        ->orderByDesc('id')
                        ->select('id', 'proforma_id', 'application_no', 'submitted_at', 'decided_at', 'workflow_status', 'user_id', 'subsidy_report');
                }])
                ->select('id', 'scheme_id', 'code', 'title', 'description', 'claim_type')
                ->get();

            $response_data = [];

            foreach ($claim_proformas as $proforma) {

                $can_reapply_for_claim = $this->can_reapply_for_claim($user_id, $proforma);
                $has_applications = isset($proforma->applications) && count($proforma->applications) > 0;

                if (!$has_applications) {

                    $response_data[] = [
                        'application_id'   => null,
                        'scheme_id'        => $proforma->scheme_id,
                        'proforma_id'      => $proforma->id,
                        'application_code' => $proforma->code,
                        'application_type' => $proforma->title,
                        'proforma_details' => $proforma->description,
                        'application_no'   => null,
                        'applied_on'       => null,
                        'approved_on'      => null,
                        'workflow_status'  => null,
                        'is_editable'      => true,
                        'can_reapply'      => false, // Don't show reapply button if user don't have even applied once
                        'claimed_amount'   => null,
                        'approved_amount'  => null,
                        'disbursed_amount' => null,
                    ];
                    continue;
                }


                foreach ($proforma->applications as $application) {

                    $subsidyReport = json_decode($application->subsidy_report);

                    $claimed = $subsidyReport->totals->claimed ?? 0;
                    $approved = $subsidyReport->totals->approved ?? 0;
                    $disbursed = $subsidyReport->totals->disbursed ?? 0;

                    $response_data[] = [
                        'application_id'   => $application->id,
                        'scheme_id'        => $proforma->scheme_id,
                        'proforma_id'      => $proforma->id,
                        'application_code' => $proforma->code,
                        'application_type' => $proforma->title,
                        'proforma_details' => $proforma->description,
                        'application_no'   => $application->application_no,
                        'applied_on'       => $application->submitted_at ? $application->submitted_at->format('d/m/Y') : null,
                        'approved_on'      => $application->decided_at ? $application->decided_at->format('d/m/Y') : null,
                        'workflow_status'  => $this->status_label($application->workflow_status),
                        'is_editable'      => $this->is_application_editable($application),
                        'can_reapply'      => $can_reapply_for_claim,
                        'claimed_amount'   => $claimed,
                        'approved_amount'  => $approved,
                        'disbursed_amount' => $disbursed,
                    ];
                }
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
                $proforma = Proforma::where('id', $request->proforma_id)->first();
            }

            $questions = ProformaQuestionnaire::where('proforma_id', $proforma->id)
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
                'department'     => 'nullable|string|in:DA,GM',
                'status'         => 'nullable|string|in:submitted,approved_by_da,rejected_by_da,sent_back_by_da,approved_by_gm,rejected_by_gm,sent_back_by_gm',
                'scheme_id'      => 'nullable|integer|exists:schemes,id',
                'proforma_id'    => 'nullable|integer|exists:proformas,id',
                'applicant_name' => 'nullable|string|max:255',
                'applicant_phone' => 'nullable|string|max:20',
                'date_from'      => 'nullable|date',
                'date_to'        => 'nullable|date|after_or_equal:date_from',
            ]);

            $user = User::where('id', auth()->user()->id)->with('department_user')->first();

            $designation = $user ? $user?->department_user?->designation : null;

            $department_user_district_code = $user?->district_id;

            if (!$designation) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No department/designation mapped to your account. Contact admin to assign one.',
                ]);
            }

            $is_slc = $designation === 'State Level Committee';

            $applications = UserIncentiveApplication::with(['proforma', 'user.district']);

            if (!$is_slc) {
                $applications->whereHas('user', function ($q) use ($department_user_district_code) {
                    $q->where('district_id', $department_user_district_code);
                });
            }


            if ($designation == 'Dealing Assistant') {

                $applications->whereIn('workflow_status', ['submitted', 'approved_by_da', 'rejected_by_da', 'sent_back_by_da']);
            } elseif ($designation == "General Manager") {

                $applications->whereIn('workflow_status', ['approved_by_da', 'approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm']);
            } elseif ($designation == "State Level Committee") {

                $applications->whereIn('workflow_status', ['under_review_slc', 'approved_by_slc', 'rejected_by_slc', 'sent_back_by_slc']);
            } else {

                return response()->json([
                    'status'  => 0,
                    'message' => 'Invalid designation. Only Dealing Assistant, General Manager, or State Level Committee can access applications.',
                ]);
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
                'review_file' => 'nullable | file',
            ]);

            DB::beginTransaction();

            $new_status = $request->new_status;

            $user = User::with('department_user')->find(Auth::id());

            $designation = $user?->department_user?->designation;

            if ($designation === 'Dealing Assistant') {

                $allowed_statuses = ['approved_by_da', 'rejected_by_da', 'sent_back_by_da'];
            } elseif ($designation === 'General Manager') {

                $allowed_statuses = ['approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm'];
            } elseif ($designation === 'State Level Committee') {

                $allowed_statuses = ['approved_by_slc', 'rejected_by_slc', 'sent_back_by_slc'];
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

            $final_statuses = ['approved_by_slc', 'rejected_by_slc', 'rejected_by_da', 'rejected_by_gm'];
            if (in_array($new_status, $final_statuses, true)) {
                $application->decided_at = now();
            }

            if ($new_status === 'approved_by_gm' && $application->application_type === 'eligibility') {
                if (empty($application->eligibility_certificate_no)) {
                    $application->eligibility_certificate_no = 'ELG-' . date('y') . '-' . str_pad((string)$application->id, 6, '0', STR_PAD_LEFT);
                }
            }

            if ($new_status === 'approved_by_gm' && $application->application_type === 'claim') {

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


            if (
                in_array($new_status, ['approved_by_gm', 'approved_by_slc', 'approved_by_da'], true)
                && $application->application_type === 'claim'
                && $application->subsidy_report
            ) {

                $report = json_decode($application->subsidy_report, true) ?: [];

                if (!empty($report['subsidy_items']) && is_array($report['subsidy_items'])) {
                    $approved_total = 0.0;

                    foreach ($report['subsidy_items'] as &$item) {
                        $qid = (string) ($item['question_id'] ?? '');

                        if ($approved_items && array_key_exists($qid, $approved_items)) {
                            $item['approved'] = $approved_items[$qid];
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

                if ($new_status === 'approved_by_gm' && $approved_total > 500000) {
                    $new_status = 'under_review_slc';
                }
            }

            $application->workflow_status = $new_status;
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
                ->with(['user:id,name,authorized_person_name,email'])
                ->get()
                ->map(function ($history) {
                    return [
                        'date'        => $history->action_taken_at->format('d/m/Y'),
                        'user_name'   => optional($history->user)->authorized_person_name,
                        'from_status' => $status_labels[$history->from_status] ?? $history->from_status,
                        'from_status' => $this->status_label($history->from_status),
                        'to_status'   => $this->status_label($history->to_status),
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
                $allowed = ['submitted', 'approved_by_da', 'rejected_by_da', 'sent_back_by_da'];
            } elseif ($designation === 'General Manager') {
                $allowed = ['approved_by_da', 'approved_by_gm', 'rejected_by_gm', 'sent_back_by_gm'];
            } elseif ($designation === 'State Level Committee') {
                $allowed = ['under_review_slc', 'approved_by_slc', 'rejected_by_slc', 'sent_back_by_slc'];
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

            $subsidy_report = $application->subsidy_report ? json_decode($application->subsidy_report, true) : null;

            $latest_workflow_history = IncentiveWorkflowHistory::where('application_id', $application->id)
                ->orderByDesc('action_taken_at')
                ->orderByDesc('id')
                ->first(['remarks', 'review_file']);

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
                'eligibility_certificate_path' => $application->eligibility_certificate_path,
                'claim_type'                   => $application->claim_type,
                'remaining_claim'              => $application->remaining_claim,
                'claim_calculated'             => $application->claim_calculated,
                'form_answers_json'            => $questions_with_answers,
                'subsidy_report'               => $subsidy_report,
                'created_at'                   => optional($application->created_at)->toDateTimeString(),
                'updated_at'                   => optional($application->updated_at)->toDateTimeString(),
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
        $editable_statuses = ['draft', 'sent_back_by_da', 'sent_back_by_gm', 'sent_back_by_slc'];
        return in_array($application->workflow_status, $editable_statuses, true);
    }


    private function status_label(string $status)
    {

        if ($status === null || $status === '') {
            return $status;
        }

        static $labels = [
            'draft'             => 'Draft',
            'submitted'         => 'Submitted to DA',
            'approved_by_da'    => 'Forwarded to GM',
            'sent_back_by_da'   => 'Query raised by DA',
            'rejected_by_da'    => 'Rejected by DA',
            'approved_by_gm'    => 'Approved',
            'sent_back_by_gm'   => 'Query raised by GM',
            'rejected_by_gm'    => 'Rejected by GM',
            'under_review_slc'  => 'Under Review SLC',
            'approved_by_slc'   => 'Approved',
            'sent_back_by_slc'  => 'Query raised by SLC',
            'rejected_by_slc'   => 'Rejected by SLC',
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
            ->select('id', 'question_label', 'display_order', 'is_required')
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
                ->exists();

            return $already_submitted ? false : true;
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
            ->whereIn('workflow_status', ['approved_by_gm', 'approved_by_slc'])
            ->select('proforma_id')
            ->distinct()
            ->count('proforma_id');

        return $approved_eligibilty > 0 ? true : false;
    }
}
