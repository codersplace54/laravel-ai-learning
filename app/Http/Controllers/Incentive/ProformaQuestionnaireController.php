<?php

namespace App\Http\Controllers\Incentive;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ProformaQuestionnaire;
use Illuminate\Support\Facades\Storage;

class ProformaQuestionnaireController extends Controller
{
    public function proforma_questionnaire_store(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $validation = $this->validateRules($request);
            if ($validation !== true) {
                return $validation;
            }

            $request->validate([
                'proforma_id'           => 'required|integer|exists:proformas,id',
                'question_label'        => 'required|string',
                'question_type'         => 'required|string',
                'is_required'           => 'required|in:yes,no',
                'options'               => 'nullable|string',
                'default_value'         => 'nullable|string',
                'default_source_table'  => 'nullable|string',
                'default_source_column' => 'nullable|string',
                'display_order'         => 'nullable|integer',
                'group_label'           => 'nullable|string',
                'display_width'         => 'nullable|string',
                'status'                => 'nullable|integer',
                'validation_required'   => 'required|in:yes,no',
                'is_claim'              => 'nullable|in:yes,no',
                'claim_per_unit'        => 'nullable|numeric|min:0',
                'claim_percentage'      => 'nullable|numeric|min:0|max:100',
                'upload_rule'           => 'nullable',
                'upload_rule.max_size_mb'     => 'nullable|integer|max:25',
                'sample_format'         => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:3072',
                'display_rule'          => 'nullable|array',
                'special_relaxation'    => 'nullable|array',
            ]);

            DB::beginTransaction();
            $user = Auth::user();

            $sample_format = null;
            if ($request->hasFile("sample_format")) {
                $file = $request->file("sample_format");
                $filename = str_replace(' ', '_', $file->getClientOriginalName());
                $sample_format = $file->storeAs(
                    "uploads/proforma_questions/{$request->proforma_id}/sample_format",
                    $filename,
                    'public'
                );
            }

            $proforma_questionnaire = ProformaQuestionnaire::create([
                'proforma_id'           => $request->proforma_id,
                'question_label'        => $request->question_label,
                'question_type'         => $request->question_type,
                'is_required'           => $request->is_required,
                'options'               => $request->options,
                'default_value'         => $request->default_value,
                'default_source_table'  => $request->default_source_table,
                'default_source_column' => $request->default_source_column,
                'display_order'         => $request->display_order,
                'group_label'           => $request->group_label,
                'display_width'         => $request->display_width,
                'status'                => $request->status ?? 1,
                'validation_required'   => $request->validation_required,
                'is_claim'              => $request->is_claim,
                'claim_per_unit'        => $request->claim_per_unit,
                'claim_percentage'      => $request->claim_percentage,
                'upload_rule'           => $request->upload_rule ? json_encode($request->upload_rule) : null,
                'display_rule'          => $request->display_rule ? json_encode($request->display_rule) : null,
                'special_relaxation'    => $request->special_relaxation ? json_encode($request->special_relaxation) : null,
                'created_by'            => $user->email_id,
                'sample_format'         => $sample_format,
            ]);

            $proforma_questionnaire->upload_rule = $proforma_questionnaire->upload_rule
                ? json_decode($proforma_questionnaire->upload_rule, true)
                : null;
            $proforma_questionnaire->display_rule = $proforma_questionnaire->display_rule
                ? json_decode($proforma_questionnaire->display_rule, true)
                : null;
            $proforma_questionnaire->special_relaxation = $proforma_questionnaire->special_relaxation
                ? json_decode($proforma_questionnaire->special_relaxation, true)
                : null;

            if ($proforma_questionnaire->sample_format) {
                $proforma_questionnaire->sample_format = asset(Storage::url($proforma_questionnaire->sample_format));
            }

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Proforma questionnaires created successfully.',
                'data'    => $proforma_questionnaire,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Failed to create proforma questionnaires.', 'error' => $e->getMessage()], 500);
        }
    }

    public function proforma_questionnaire_update(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $validation = $this->validateRules($request);
            if ($validation !== true) {
                return $validation;
            }

            $request->validate([
                'id'                    => 'required|integer|exists:proforma_questionnaires,id',
                'proforma_id'           => 'required|integer|exists:proformas,id',
                'question_label'        => 'required|string',
                'question_type'         => 'required|string',
                'is_required'           => 'required|in:yes,no',
                'options'               => 'nullable|string',
                'default_value'         => 'nullable|string',
                'default_source_table'  => 'nullable|string',
                'default_source_column' => 'nullable|string',
                'display_order'         => 'nullable|integer',
                'group_label'           => 'nullable|string',
                'display_width'         => 'nullable|string',
                'status'                => 'nullable|boolean',
                'validation_required'   => 'required|in:yes,no',
                'is_claim'              => 'nullable|in:yes,no',
                'claim_per_unit'        => 'nullable|integer',
                'claim_percentage'      => 'nullable|integer',
                'upload_rule'           => 'nullable',
                'upload_rule.max_size_mb' => 'nullable|integer|max:5',
                'sample_format'         => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:3072',
                'display_rule'          => 'nullable|array',
                'special_relaxation'    => 'nullable|array',
            ]);

            DB::beginTransaction();
            $user = Auth::user();

            $proforma_question = ProformaQuestionnaire::where('id', $request->id)->first();

            $sample_format = $proforma_question->sample_format;
            if ($request->hasFile("sample_format")) {
                if ($sample_format && Storage::disk('public')->exists($sample_format)) {
                    Storage::disk('public')->delete($sample_format);
                }
                $file = $request->file("sample_format");
                $filename = str_replace(' ', '_', $file->getClientOriginalName());
                $sample_format = $file->storeAs(
                    "uploads/proforma_questions/{$request->proforma_id}/sample_format",
                    $filename,
                    'public'
                );
            }
            $proforma_question->update([
                'proforma_id'           => $request->proforma_id,
                'question_label'        => $request->question_label,
                'question_type'         => $request->question_type,
                'is_required'           => $request->is_required,
                'options'               => $request->options ?? $proforma_question->options,
                'default_value'         => $request->default_value ?? $proforma_question->default_value,
                'default_source_table'  => $request->default_source_table ?? $proforma_question->default_source_table,
                'default_source_column' => $request->default_source_column ?? $proforma_question->default_source_column,
                'display_order'         => $request->display_order ?? $proforma_question->display_order,
                'group_label'           => $request->group_label ?? $proforma_question->group_label,
                'display_width'         => $request->display_width ?? $proforma_question->display_width,
                'status'                => $request->status ? $request->status : $proforma_question->status,
                'validation_required'   => $request->validation_required,
                'is_claim'              => $request->is_claim ?? $proforma_question->is_claim,
                'claim_per_unit'        => $request->claim_per_unit ?? $proforma_question->claim_per_unit,
                'claim_percentage'      => $request->claim_percentage ?? $proforma_question->claim_percentage,
                'upload_rule'           => $request->upload_rule ? json_encode($request->upload_rule) : $proforma_question->upload_rule,
                'display_rule'          => $request->display_rule ? json_encode($request->display_rule) : $proforma_question->display_rule,
                'special_relaxation'    => $request->special_relaxation ? json_encode($request->special_relaxation) : $proforma_question->special_relaxation,
                'updated_by'            => $user->email_id,
                'sample_format'         => $sample_format ?? $proforma_question->sample_format,
            ]);

            $proforma_question->upload_rule = $proforma_question->upload_rule
                ? json_decode($proforma_question->upload_rule, true)
                : null;
            $proforma_question->display_rule = $proforma_question->display_rule
                ? json_decode($proforma_question->display_rule, true)
                : null;
            $proforma_question->special_relaxation = $proforma_question->special_relaxation
                ? json_decode($proforma_question->special_relaxation, true)
                : null;
            if ($proforma_question->sample_format) {
                $proforma_question->sample_format = asset(Storage::url($proforma_question->sample_format));
            }

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Proforma questionnaires updated successfully.',
                'data'    => $proforma_question,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Failed to update proforma questionnaires.', 'error' => $e->getMessage()], 500);
        }
    }


    public function proforma_questionnaire_delete(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:proforma_questionnaires,id',
            ]);


            $proforma_questionnaire = ProformaQuestionnaire::where('id', $request->id)->first();

            $proforma_questionnaire->delete();

            return response()->json([
                'status'     => 1,
                'message'    => 'Proforma questionnaire deleted successfully.',
                'deleted_id' => $request->id,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => $e->getMessage()], 500);
        }
    }

    public function proforma_questionnaire_view(Request $request)
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
                $question->display_rule = $question->display_rule
                    ? json_decode($question->display_rule, true)
                    : null;
                $question->special_relaxation = $question->special_relaxation
                    ? json_decode($question->special_relaxation, true)
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

    public function proforma_questionnaire_details(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'questionnaire_id' => 'required|integer|exists:proforma_questionnaires,id',
            ]);

            $proforma_questionnaire = ProformaQuestionnaire::where('id', $request->questionnaire_id)->first();

            $proforma_questionnaire->upload_rule = $proforma_questionnaire->upload_rule
                ? json_decode($proforma_questionnaire->upload_rule, true)
                : null;
            $proforma_questionnaire->display_rule = $proforma_questionnaire->display_rule
                ? json_decode($proforma_questionnaire->display_rule, true)
                : null;
            $proforma_questionnaire->special_relaxation = $proforma_questionnaire->special_relaxation
                ? json_decode($proforma_questionnaire->special_relaxation, true)
                : null;

            if ($proforma_questionnaire->sample_format) {
                $proforma_questionnaire->sample_format = asset(Storage::url($proforma_questionnaire->sample_format));
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Proforma questionnaires details fetched successfully.',
                'data'    => $proforma_questionnaire,
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    private function validateRules(Request $request)
    {
        if ($request->has('display_rule')) {
            foreach ($request->display_rule as $rule) {
                $allowed_keys = ['target_question_id', 'condition_operator', 'start_value', 'end_value'];
                $extra_keys = array_diff(array_keys($rule), $allowed_keys);
                if (!empty($extra_keys)) {
                    return response()->json(['status' => 0, 'message' => 'Invalid keys in display_rule. Only these keys are allowed: ' . implode(', ', $allowed_keys)], 422);
                }
            }
        }

        if ($request->has('special_relaxation')) {
            foreach ($request->special_relaxation as $relaxation) {
                $allowed_keys = ['target_question_id', 'condition_operator', 'start_value', 'end_value', 'is_extra_claim', 'extra_claim_per_unit', 'extra_claim_percentage', 'max_claim_amount'];
                $extra_keys = array_diff(array_keys($relaxation), $allowed_keys);
                if (!empty($extra_keys)) {
                    return response()->json(['status' => 0, 'message' => 'Invalid keys in special_relaxation. Only these keys are allowed: ' . implode(', ', $allowed_keys)], 422);
                }
            }
        }

        return true;
    }
}
