<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\ServiceQuestionnaire;


class ServiceQuestionnaireController extends Controller
{
    public function service_questionnaire_store(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

            $request->validate([
                'questionnaires' => 'required|array',
                'questionnaires.*.service_id' => 'required|integer|exists:service_masters,id',
                'questionnaires.*.question_label' => 'required|string',
                'questionnaires.*.question_type' => 'required|string',
                'questionnaires.*.is_required' => 'required|in:yes,no',
                'questionnaires.*.options' => 'nullable|string',
                'questionnaires.*.default_value' => 'nullable|string',
                'questionnaires.*.default_source_table' => 'nullable|string',
                'questionnaires.*.default_source_column' => 'nullable|string',
                'questionnaires.*.display_order' => 'nullable|integer',
                'questionnaires.*.group_label' => 'nullable|string',
                'questionnaires.*.display_width' => 'nullable|string',
                'questionnaires.*.status' => 'nullable|boolean',
                'questionnaires.*.validation_required' => 'required|in:yes,no',
                'questionnaires.*.validation_rule' => 'nullable|array',
                'questionnaires.*.is_section' => 'nullable|in:yes,no',
                'questionnaires.*.section_name' => 'nullable|required_if:questionnaires.*.is_section,yes|string',
                'questionnaires.*.sample_format' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:3072',
            ]);

            DB::beginTransaction();

            $service_questionnaire = [];


            foreach ($request->questionnaires as $index => $questionnaire) {

                $sample_format = null;
                if ($request->hasFile("questionnaires.$index.sample_format")) {
                    $file = $request->file("questionnaires.$index.sample_format");
                    $extension = $file->getClientOriginalExtension();
                    $filename = 'sample_format.' . $extension;
                    $sample_format = $file->storeAs(
                        "uploads/service_questions/{$questionnaire['id']}/sample_format",
                        $filename,
                        'public'
                    );
                }

                $service_questionnaire[] = ServiceQuestionnaire::create([
                    'service_id' => $questionnaire['service_id'],
                    'question_label' => $questionnaire['question_label'],
                    'question_type' => $questionnaire['question_type'],
                    'is_required' => $questionnaire['is_required'],
                    'options' => $questionnaire['options'] ?? null,
                    'default_value' => $questionnaire['default_value'] ?? null,
                    'default_source_table' => $questionnaire['default_source_table'] ?? null,
                    'default_source_column' => $questionnaire['default_source_column'] ?? null,
                    'display_order' => $questionnaire['display_order'] ?? null,
                    'group_label' => $questionnaire['group_label'] ?? null,
                    'display_width' => $questionnaire['display_width'] ?? null,
                    'status' => $questionnaire['status'] ?? 1,
                    'validation_required' => $questionnaire['validation_required'],
                    'validation_rule' => json_encode($questionnaire['validation_rule'] ?? null),
                    'is_section' => $questionnaire['is_section'] ?? 'no',
                    'section_name' => $questionnaire['section_name'] ?? null,
                    'created_by' => $admin->email_id,
                    'sample_format' => $sample_format,
                ]);
            }

            foreach ($service_questionnaire as &$service) {
                $service->validation_rule = json_decode($service->validation_rule, true);

                if ($service->sample_format) {
                    $service->sample_format = asset(Storage::url($service->sample_format));
                }
            }


            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service questionnaires created successfully.',
                'data' =>  $service_questionnaire,
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to create service questionnaires.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function service_questionnaire_update(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

            $request->validate([
                'questionnaires' => 'required|array',
                'questionnaires.*.id' => 'required|integer|exists:service_questionnaires,id',
                'questionnaires.*.service_id' => 'required|integer|exists:service_masters,id',
                'questionnaires.*.question_label' => 'required|string',
                'questionnaires.*.question_type' => 'required|string',
                'questionnaires.*.is_required' => 'required|in:yes,no',
                'questionnaires.*.options' => 'nullable|string',
                'questionnaires.*.default_value' => 'nullable|string',
                'questionnaires.*.default_source_table' => 'nullable|string',
                'questionnaires.*.default_source_column' => 'nullable|string',
                'questionnaires.*.display_order' => 'nullable|integer',
                'questionnaires.*.group_label' => 'nullable|string',
                'questionnaires.*.display_width' => 'nullable|string',
                'questionnaires.*.status' => 'nullable|boolean',
                'questionnaires.*.validation_required' => 'required|in:yes,no',
                'questionnaires.*.validation_rule' => 'nullable|array',
                'questionnaires.*.is_section' => 'nullable|in:yes,no',
                'questionnaires.*.section_name' => 'nullable|required_if:questionnaires.*.is_section,yes|string',
                'questionnaires.*.sample_format' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:3072',
            ]);

            DB::beginTransaction();

            $service_questionnaire = [];

            foreach ($request->questionnaires as $index => $questionnaire) {
                $service_question = ServiceQuestionnaire::findOrFail($questionnaire['id']);

                $sample_format = $service_question->sample_format;
                if ($request->hasFile("questionnaires.$index.sample_format")) {

                    if ($sample_format && Storage::disk('public')->exists($sample_format)) {
                        Storage::disk('public')->delete($sample_format);
                    }

                    $file = $request->file("questionnaires.$index.sample_format");
                    $extension = $file->getClientOriginalExtension();
                    $filename = 'sample_format.' . $extension;
                    $sample_format = $file->storeAs("uploads/service_questions/{$questionnaire['id']}/sample_format", $filename, 'public');
                }

                $service_question->update([
                    'service_id' => $questionnaire['service_id'],
                    'question_label' => $questionnaire['question_label'],
                    'question_type' => $questionnaire['question_type'],
                    'is_required' => $questionnaire['is_required'],
                    'options' => $questionnaire['options'] ?? null,
                    'default_value' => $questionnaire['default_value'] ?? null,
                    'default_source_table' => $questionnaire['default_source_table'] ?? null,
                    'default_source_column' => $questionnaire['default_source_column'] ?? null,
                    'display_order' => $questionnaire['display_order'] ?? null,
                    'group_label' => $questionnaire['group_label'] ?? null,
                    'display_width' => $questionnaire['display_width'] ?? null,
                    'status' => $questionnaire['status'] ?? 1,
                    'validation_required' => $questionnaire['validation_required'],
                    'validation_rule' => json_encode($questionnaire['validation_rule'] ?? null),
                    'is_section'   => $questionnaire['is_section'] ?? 'no',
                    'section_name' => $questionnaire['section_name'] ?? null,

                    'updated_by' => $admin->email_id,
                    'sample_format' => $sample_format,
                ]);

                $service_questionnaire[] = $service_question;
            }

            foreach ($service_questionnaire as &$service) {
                $service->validation_rule = json_decode($service->validation_rule, true);

                if ($service->sample_format) {
                    $service->sample_format = asset('storage/' . $service->sample_format);
                }
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Service questionnaires updated successfully.',
                'data' =>  $service_questionnaire
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'success' => 0,
                'message' => 'Failed to update service questionnaires.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function service_questionnaire_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:service_questionnaires,id',
            ]);

            DB::beginTransaction();

            $service_questionnaire = ServiceQuestionnaire::where('id', $request->id)->first();

            if (!$service_questionnaire) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Service Questionnaire not found.'
                ], 404);
            }

            $service_questionnaire->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service Questionnaire deleted successfully.',
                'deleted_id' => $request->id
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function service_questionnaire_view(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service_questionnaires = ServiceQuestionnaire::where('service_id', $request->service_id)->get();

            if ($service_questionnaires->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No questionnaires found for the given service_id.',
                ], 404);
            }

            foreach ($service_questionnaires as $service) {
                $service->validation_rule = json_decode($service->validation_rule, true);

                if ($service->sample_format) {
                    $service->sample_format = asset('storage/' . $service->sample_format);
                }
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service questionnaires fetched successfully.',
                'data' => $service_questionnaires,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function fetch_questionnaire_section(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service_questionnaires = ServiceQuestionnaire::where('service_id', $request->service_id)->where('is_section', 'yes')
                ->pluck('section_name')
                ->unique()
                ->values()
                ->all();

            return response()->json([
                'status' => 1,
                'message' => 'Service questionnaires section fetched successfully.',
                'data' => $service_questionnaires,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
