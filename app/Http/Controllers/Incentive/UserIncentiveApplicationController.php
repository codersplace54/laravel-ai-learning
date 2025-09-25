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

class UserIncentiveApplicationController extends Controller
{
    public function user_proforma_application_store(Request $request)
    {
        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $is_save_only = $request->save_data === 1;

            $request->validate([
                'save_data'        => 'required|integer|in:0,1',
                'scheme_id'        => 'required|integer|exists:schemes,id',
                'proforma_id'      => 'required|integer|exists:proformas,id',
                'application_type' => 'required|in:eligibility,claim',
                'eligibility_application_id' => 'exclude_unless:application_type,claim|required_unless:save_data,1|integer|exists:user_incentive_applications,id',
                'claim_type'                 => 'exclude_unless:application_type,claim|required_unless:save_data,1|in:one_time,monthly,quarterly',
                'claim_period_start'         => 'exclude_unless:application_type,claim|required_unless:save_data,1|date',
                'claim_period_end'           => 'exclude_unless:application_type,claim|required_unless:save_data,1|date|after_or_equal:claim_period_start',
                'files'        => 'nullable|array',
                'files.*'      => 'array',
                'files.*.*'    => 'file|max:10240|mimes:pdf,jpg,jpeg,png',
                'form_answers_json'  => 'required_unless:save_data,1',
            ]);

            DB::beginTransaction();

            $application = UserIncentiveApplication::firstOrNew([
                'user_id'          => $user->id,
                'scheme_id'        => $request->scheme_id,
                'proforma_id'      => $request->proforma_id,
                'application_type' => $request->application_type,
            ]);

            $form_answers_json = json_decode($request->form_answers_json, true);

            $answers = array_replace(
                $application->form_answers_json ?? [],
                $form_answers_json ?? []
            );

            foreach ($request->file('files', []) as $question_id => $files) {
                if (!empty($answers[$question_id]['files'])) {
                    foreach ($answers[$question_id]['files'] as $old) {
                        if (!empty($old['path'])) {
                            Storage::disk('public')->delete($old['path']);
                        }
                    }
                }

                $descriptors = [];
                foreach ($files as $uploaded) {
                    $filename = 'proforma.' . $uploaded->getClientOriginalExtension();
                    $path = $uploaded->storeAs("uploads/proformas/{$user->id}", $filename, 'public');
                    
                    $descriptors[] = [
                        'path' => $path,
                        'url'  => asset("storage/{$path}"),
                        'name' => $uploaded->getClientOriginalName(),
                        'mime' => $uploaded->getClientMimeType(),
                        'size' => $uploaded->getSize(),
                    ];
                }

                $answers[$question_id] = [
                    'value' => $answers[$question_id]['value'] ?? null,
                    'files' => $descriptors,
                ];
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

                $existing_questions = $application->form_answers_json ?? [];
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
                    'data'    => [
                        'application_id'  => $application->id,
                        'application_no'  => $application->application_no,
                        'workflow_status' => $application->workflow_status,
                        'submitted_at'    => optional($application->submitted_at)->toDateTimeString(),
                    ],
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Draft saved successfully.',
                'data'    => [
                    'application_id'  => $application->id,
                    'application_no'  => $application->application_no,
                    'workflow_status' => $application->workflow_status,
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

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
        } catch (\Throwable $e) {

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
        } catch (\Throwable $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }
}
