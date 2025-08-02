<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\RenewalCycle;

class RenewalCycleController extends Controller
{
    public function renewal_cycle_store(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'renewals' => 'required|array',
                'renewals.*.service_id' => 'required|integer|exists:service_masters,id',
                'renewals.*.renewal_title' => 'required|string',
                'renewals.*.renewal_period' => 'required|string',
                'renewals.*.renewal_period_custom' => 'nullable|string',
                'renewals.*.renewal_target_days' => 'nullable|integer',
                'renewals.*.renewal_window_days' => 'nullable|integer',
                'renewals.*.fixed_renewal_start_date' => 'nullable|date',
                'renewals.*.fixed_renewal_end_date' => 'nullable|date',
                'renewals.*.late_fee_applicable' => 'required|in:yes,no',
                'renewals.*.late_fee_calculation_dynamic' => 'required|in:yes,no',
                'renewals.*.late_fee_fixed_amount' => 'nullable|string',
                'renewals.*.late_fee_calculated_amount' => 'nullable|string',
                'renewals.*.allow_renewal_input_form' => 'required|in:yes,no',
                'renewals.*.is_active' => 'nullable|integer',
            ]);

            DB::beginTransaction();

            $renewal_cycles = [];

            foreach ($request->renewals as $renewal) {
                $renewal_cycles[] = RenewalCycle::create([
                    'service_id' => $renewal['service_id'],
                    'renewal_title' => $renewal['renewal_title'],
                    'renewal_period' => $renewal['renewal_period'],
                    'renewal_period_custom' => $renewal['renewal_period_custom'] ?? null,
                    'renewal_target_days' => $renewal['renewal_target_days'] ?? null,
                    'renewal_window_days' => $renewal['renewal_window_days'] ?? null,
                    'fixed_renewal_start_date' => $renewal['fixed_renewal_start_date'] ?? null,
                    'fixed_renewal_end_date' => $renewal['fixed_renewal_end_date'] ?? null,
                    'late_fee_applicable' => $renewal['late_fee_applicable'],
                    'late_fee_calculation_dynamic' => $renewal['late_fee_calculation_dynamic'],
                    'late_fee_fixed_amount' => $renewal['late_fee_fixed_amount'] ?? null,
                    'late_fee_calculated_amount' => $renewal['late_fee_calculated_amount'] ?? null,
                    'allow_renewal_input_form' => $renewal['allow_renewal_input_form'],
                    'is_active' => $renewal['is_active'] ?? 1,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service renewal cycles created successfully.',
                'data' =>  $renewal_cycles,
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to create Service renewal cycles.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function renewal_cycle_update(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'renewals' => 'required|array',
                'renewals.*.id' => 'required|integer|exists:renewal_cycles,id',
                'renewals.*.service_id' => 'required|integer|exists:service_masters,id',
                'renewals.*.renewal_title' => 'required|string',
                'renewals.*.renewal_period' => 'required|string',
                'renewals.*.renewal_period_custom' => 'nullable|string',
                'renewals.*.renewal_target_days' => 'nullable|integer',
                'renewals.*.renewal_window_days' => 'nullable|integer',
                'renewals.*.fixed_renewal_start_date' => 'nullable|date',
                'renewals.*.fixed_renewal_end_date' => 'nullable|date',
                'renewals.*.late_fee_applicable' => 'required|in:yes,no',
                'renewals.*.late_fee_calculation_dynamic' => 'required|in:yes,no',
                'renewals.*.late_fee_fixed_amount' => 'nullable|string',
                'renewals.*.late_fee_calculated_amount' => 'nullable|string',
                'renewals.*.allow_renewal_input_form' => 'required|in:yes,no',
                'renewals.*.is_active' => 'nullable|integer',
            ]);

            DB::beginTransaction();

            $renewal_cycles = [];

            foreach ($request->renewals as $renewal) {
                $renewal_cycle = RenewalCycle::findOrFail($renewal['id']);

                $renewal_cycle->update([
                    'service_id' => $renewal['service_id'],
                    'renewal_title' => $renewal['renewal_title'],
                    'renewal_period' => $renewal['renewal_period'],
                    'renewal_period_custom' => $renewal['renewal_period_custom'] ?? null,
                    'renewal_target_days' => $renewal['renewal_target_days'] ?? null,
                    'renewal_window_days' => $renewal['renewal_window_days'] ?? null,
                    'fixed_renewal_start_date' => $renewal['fixed_renewal_start_date'] ?? null,
                    'fixed_renewal_end_date' => $renewal['fixed_renewal_end_date'] ?? null,
                    'late_fee_applicable' => $renewal['late_fee_applicable'],
                    'late_fee_calculation_dynamic' => $renewal['late_fee_calculation_dynamic'],
                    'late_fee_fixed_amount' => $renewal['late_fee_fixed_amount'] ?? null,
                    'late_fee_calculated_amount' => $renewal['late_fee_calculated_amount'] ?? null,
                    'allow_renewal_input_form' => $renewal['allow_renewal_input_form'],
                    'is_active' => $renewal['is_active'] ?? 1,
                ]);

                $renewal_cycles[] = $renewal_cycle;
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Service renewal cycle updated successfully.',
                'data' =>  $renewal_cycles
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'success' => 0,
                'message' => 'Failed to update service renewal cycle.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function renewal_cycle_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:renewal_cycles,id',
            ]);

            DB::beginTransaction();

            $renewal_cycle = RenewalCycle::where('id', $request->id)->first();

            if (!$renewal_cycle) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Service Renewal Cycle not found.'
                ], 404);
            }

            $renewal_cycle->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service Renewal Cycle deleted successfully.',
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
