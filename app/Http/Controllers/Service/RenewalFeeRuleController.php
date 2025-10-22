<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\RenewalFeeRule;
use App\Models\RenewalCycle;
use App\Models\ServiceQuestionnaire;

class RenewalFeeRuleController extends Controller
{
    public function renewal_fee_rule_store(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

            $request->validate([
                'rules' => 'required|array',
                'rules.*.service_id' => 'nullable|integer|exists:service_masters,id',
                'rules.*.renewal_cycle_id' => 'nullable|integer|exists:renewal_cycles,id',
                'rules.*.fee_type' => 'nullable|in:hardcoded,calculated,estimated',
                'rules.*.fixed_fee' => 'nullable|string',
                'rules.*.question_id' => 'nullable|integer|exists:service_questionnaires,id',
                'rules.*.condition_label_question_id' => 'nullable|integer|exists:service_questionnaires,id',
                'rules.*.pre_condition_operator' => 'nullable|in:=,!=,<,<=,>,>=,between',
                'rules.*.pre_condition_value' => 'nullable|string',
                'rules.*.condition_operator' => 'nullable|in:=,!=,<,<=,>,>=,between',
                'rules.*.condition_value_start' => 'nullable|string',
                'rules.*.condition_value_end' => 'nullable|string',
                'rules.*.calculated_fee' => 'nullable|string',
                'rules.*.fixed_calculated_fee' => 'nullable|string',
                'rules.*.per_unit_fee' => 'nullable|string',
                'rules.*.priority' => 'nullable|integer',
                'rules.*.status' => 'nullable|boolean',
                'rules.*.multi_condition' => 'nullable|in:yes,no',
            ]);

            DB::beginTransaction();

            $renewal_fee_rules = [];

            foreach ($request->rules as $rule) {

                $renewal_fee_rule = RenewalFeeRule::create([
                    'service_id' => $rule['service_id'] ?? null,
                    'renewal_cycle_id' => $rule['renewal_cycle_id']  ?? null,
                    'fee_type' => $rule['fee_type'] ?? null,
                    'fixed_fee' => $rule['fixed_fee'] ?? null,
                    'question_id' => $rule['question_id'] ?? null,
                    'condition_label_question_id' => $rule['condition_label_question_id'] ?? null,
                    'pre_condition_operator' => $rule['pre_condition_operator'] ?? null,
                    'condition_operator' => $rule['condition_operator'] ?? null,
                    'pre_condition_value' => $rule['pre_condition_value'] ?? null,
                    'condition_value_start' => $rule['condition_value_start'] ?? null,
                    'condition_value_end' => $rule['condition_value_end'] ?? null,
                    'calculated_fee' => $rule['calculated_fee'] ?? null,
                    'fixed_calculated_fee' => $rule['fixed_calculated_fee'] ?? null,
                    'per_unit_fee' => $rule['per_unit_fee'] ?? null,
                    'priority' => $rule['priority'] ?? null,
                    'status' => $rule['status'] ?? 1,
                    'created_by' => $admin->email_id,
                    'multi_condition' => $rule['multi_condition'] ?? "no",
                ]);

                $renewal_fee_rules[] = $renewal_fee_rule;
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Renewal fee rules saved successfully.',
                'data' => $renewal_fee_rules,
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to save service fee rules.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function renewal_fee_rule_update(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

            $request->validate([
                'rules' => 'required|array',
                'rules.*.id' => 'nullable|integer|exists:renewal_fee_rules,id',
                'rules.*.service_id' => 'nullable|integer|exists:service_masters,id',
                'rules.*.renewal_cycle_id' => 'nullable|integer|exists:renewal_cycles,id',
                'rules.*.fee_type' => 'nullable|in:hardcoded,calculated,estimated',
                'rules.*.fixed_fee' => 'nullable|string',
                'rules.*.question_id' => 'nullable|integer|exists:service_questionnaires,id',
                'rules.*.condition_label_question_id' => 'nullable|integer|exists:service_questionnaires,id',
                'rules.*.pre_condition_operator' => 'nullable|in:=,!=,<,<=,>,>=,between',
                'rules.*.pre_condition_value' => 'nullable|string',
                'rules.*.condition_operator' => 'nullable|in:=,!=,<,<=,>,>=,between',
                'rules.*.condition_value_start' => 'nullable|string',
                'rules.*.condition_value_end' => 'nullable|string',
                'rules.*.calculated_fee' => 'nullable|string',
                'rules.*.fixed_calculated_fee' => 'nullable|string',
                'rules.*.per_unit_fee' => 'nullable|string',
                'rules.*.priority' => 'nullable|integer',
                'rules.*.status' => 'nullable|boolean',
            ]);

            DB::beginTransaction();

            $renewal_fee_rules = [];

            foreach ($request->rules as $rule) {

                $renewal_fee_rule = RenewalFeeRule::findOrFail($rule['id']);

                $renewal_fee_rule->update([
                    'service_id' => $rule['service_id'] ?? null,
                    'renewal_cycle_id' => $rule['renewal_cycle_id'] ?? null,
                    'fee_type' => $rule['fee_type'] ?? null,
                    'fixed_fee' => $rule['fixed_fee'] ?? null,
                    'question_id' => $rule['question_id'] ?? null,
                    'condition_label_question_id' => $rule['condition_label_question_id'] ?? null,
                    'pre_condition_operator' => $rule['pre_condition_operator'] ?? null,
                    'condition_operator' => $rule['condition_operator'] ?? null,
                    'pre_condition_value' => $rule['pre_condition_value'] ?? null,
                    'condition_value_start' => $rule['condition_value_start'] ?? null,
                    'condition_value_end' => $rule['condition_value_end'] ?? null,
                    'calculated_fee' => $rule['calculated_fee'] ?? null,
                    'fixed_calculated_fee' => $rule['fixed_calculated_fee'] ?? null,
                    'per_unit_fee' => $rule['per_unit_fee'] ?? null,
                    'priority' => $rule['priority'] ?? null,
                    'status' => $rule['status'] ?? 1,
                    'updated_by' => $admin->email_id
                ]);

                $renewal_fee_rules[] = $renewal_fee_rule;
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Renewal fee rules updated successfully.',
                'data' => $renewal_fee_rules,
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to update Renewal fee rules.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function renewal_fee_rule_view(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $renewal_fee_rules = RenewalFeeRule::where('service_id', $request->service_id)->get();

            if ($renewal_fee_rules->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No Renewal fee rule found for the given service_id.',
                ], 404);
            }

            $data = $renewal_fee_rules->map(function ($rule) {
                return [
                    'id' => $rule->id,
                    'service_id' => $rule->service_id,
                    'renewal_cycle_id' => $rule->renewal_cycle_id,
                    'fee_type' => $rule->fee_type,
                    'fixed_fee' => $rule->fixed_fee,
                    'question_id' => $rule->question_id,
                    'question_label' => $rule->question->question_label ?? null,
                    'condition_label_question_id' => $rule->condition_label_question_id ?? null,
                    'condition_label_question' => $rule->conditionQuestion->question_label ?? null,
                    'pre_condition_value' => $rule->pre_condition_value ?? null,
                    'pre_condition_operator' => $rule->pre_condition_operator ?? null,
                    'condition_operator' => $rule->condition_operator,
                    'condition_value_start' => $rule->condition_value_start,
                    'condition_value_end' => $rule->condition_value_end,
                    'fixed_fee' => $rule->fixed_fee,
                    'calculated_fee' => $rule->calculated_fee,
                    'fixed_calculated_fee' => $rule->fixed_calculated_fee,
                    'per_unit_fee' => $rule->per_unit_fee,
                    'priority' => $rule->priority,
                    'status' => $rule->status,
                    'multi_condition' => $rule->multi_condition,
                    'created_by' => $rule->created_by,
                    'updated_by' => $rule->updated_by,
                    'created_at' => $rule->created_at,
                    'updated_at' => $rule->updated_at,
                ];
            });

            return response()->json([
                'status' => 1,
                'message' => 'Renewal fee rule fetched successfully.',
                'data' => $data,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function renewal_fee_rule_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:renewal_fee_rules,id',
            ]);

            DB::beginTransaction();

            $renewal_fee_rule = RenewalFeeRule::where('id', $request->id)->first();

            if (!$renewal_fee_rule) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Renewal Fee Rule not found.'
                ], 404);
            }

            $renewal_fee_rule->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Renewal Fee Rule deleted successfully.',
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
}
