<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceFeeRule;
use App\Models\RenewalCycle;
use App\Models\ServiceQuestionnaire;

class ServiceFeeRuleController extends Controller
{
    public function service_fee_rule_store(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'rules' => 'required|array',
                'rules.*.service_id' => 'required|integer|exists:service_masters,id',
                'rules.*.renewal_cycle_id' => 'required|integer|exists:renewal_cycles,id',
                'rules.*.fee_type' => 'nullable|in:hardcoded,calculated,estimated',
                'rules.*.fixed_fee' => 'nullable|string',
                'rules.*.question_id' => 'required|integer|exists:service_questionnaires,id',
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

            $service_fee_rules = [];

            foreach ($request->rules as $rule) {

                $renewal_cycle = RenewalCycle::where('id', $rule['renewal_cycle_id'])
                    ->where('service_id', $rule['service_id'])
                    ->first();

                if (!$renewal_cycle) {

                    DB::rollBack();

                    return response()->json([
                        'status' => 0,
                        'message' => "Renewal Cycle ID {$rule['renewal_cycle_id']} does not belong to Service ID {$rule['service_id']}.",
                    ], 422);
                }

                $service_questionnaire = ServiceQuestionnaire::where('id', $rule['question_id'])
                    ->where('service_id', $rule['service_id'])
                    ->first();

                if (!$service_questionnaire) {

                    DB::rollBack();

                    return response()->json([
                        'status' => 0,
                        'message' => "Service Questionnaire ID {$rule['question_id']} does not belong to Service ID {$rule['question_id']}.",
                    ], 422);
                }

                $service_fee_rule = ServiceFeeRule::create([
                    'service_id' => $rule['service_id'],
                    'renewal_cycle_id' => $rule['renewal_cycle_id'],
                    'fee_type' => $rule['fee_type'] ?? null,
                    'fixed_fee' => $rule['fixed_fee'] ?? null,
                    'question_id' => $rule['question_id'] ?? null,
                    'condition_operator' => $rule['condition_operator'] ?? null,
                    'condition_value_start' => $rule['condition_value_start'] ?? null,
                    'condition_value_end' => $rule['condition_value_end'] ?? null,
                    'calculated_fee' => $rule['calculated_fee'] ?? null,
                    'fixed_calculated_fee' => $rule['fixed_calculated_fee'] ?? null,
                    'per_unit_fee' => $rule['per_unit_fee'] ?? null,
                    'priority' => $rule['priority'] ?? null,
                    'status' => $rule['status'] ?? 1,
                ]);

                $service_fee_rules[] = $service_fee_rule;
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service fee rules saved successfully.',
                'data' => $service_fee_rules,
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

    public function service_fee_rule_update(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'rules' => 'required|array',
                'rules.*.id' => 'nullable|integer|exists:service_fee_rules,id',
                'rules.*.service_id' => 'required|integer|exists:service_masters,id',
                'rules.*.renewal_cycle_id' => 'required|integer|exists:renewal_cycles,id',
                'rules.*.fee_type' => 'nullable|in:hardcoded,calculated,estimated',
                'rules.*.fixed_fee' => 'nullable|string',
                'rules.*.question_id' => 'required|integer|exists:service_questionnaires,id',
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

            $service_fee_rules = [];

            foreach ($request->rules as $rule) {

                $renewal_cycle = RenewalCycle::where('id', $rule['renewal_cycle_id'])
                    ->where('service_id', $rule['service_id'])
                    ->first();

                if (!$renewal_cycle) {

                    DB::rollBack();

                    return response()->json([
                        'status' => 0,
                        'message' => "Renewal Cycle ID {$rule['renewal_cycle_id']} does not belong to Service ID {$rule['service_id']}.",
                    ], 422);
                }

                $service_questionnaire = ServiceQuestionnaire::where('id', $rule['question_id'])
                    ->where('service_id', $rule['service_id'])
                    ->first();

                if (!$service_questionnaire) {

                    DB::rollBack();

                    return response()->json([
                        'status' => 0,
                        'message' => "Service Questionnaire ID {$rule['question_id']} does not belong to Service ID {$rule['question_id']}.",
                    ], 422);
                }

                $service_fee_rule = ServiceFeeRule::findOrFail($rule['id']);

                $service_fee_rule->update([
                    'service_id' => $rule['service_id'],
                    'renewal_cycle_id' => $rule['renewal_cycle_id'],
                    'fee_type' => $rule['fee_type'] ?? null,
                    'fixed_fee' => $rule['fixed_fee'] ?? null,
                    'question_id' => $rule['question_id'] ?? null,
                    'condition_operator' => $rule['condition_operator'] ?? null,
                    'condition_value_start' => $rule['condition_value_start'] ?? null,
                    'condition_value_end' => $rule['condition_value_end'] ?? null,
                    'calculated_fee' => $rule['calculated_fee'] ?? null,
                    'fixed_calculated_fee' => $rule['fixed_calculated_fee'] ?? null,
                    'per_unit_fee' => $rule['per_unit_fee'] ?? null,
                    'priority' => $rule['priority'] ?? null,
                    'status' => $rule['status'] ?? 1,
                ]);

                $service_fee_rules[] = $service_fee_rule;
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service fee rules updated successfully.',
                'data' => $service_fee_rules,
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to update service fee rules.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function service_fee_rule_view(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service_fee_rules = ServiceFeeRule::where('service_id', $request->service_id)->get();

            if ($service_fee_rules->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No service fee rule found for the given service_id.',
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service fee rule fetched successfully.',
                'data' => $service_fee_rules,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function service_fee_rule_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:service_fee_rules,id',
            ]);

            DB::beginTransaction();

            $service_fee_rule = ServiceFeeRule::where('id', $request->id)->first();

            if (!$service_fee_rule) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Service Fee Rule not found.'
                ], 404);
            }

            $service_fee_rule->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service Fee Rule deleted successfully.',
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
