<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Activity;

class ActivityController extends Controller
{

    public function activity_store(Request $request)
    {

        try {


            if ($request->save_data != 1) {
                $request->validate([
                    'activity_of_enterprise' => 'required|string',
                    'nic_2_digit_code' => 'required|string',
                    'nic_4_digit_code' => 'required|string',
                    'nic_5_digit_code' => 'required|string',
                ]);
            }

            DB::beginTransaction();

            $nic_5_digit_code_exist = Activity::where('nic_5_digit_code', $request->nic_5_digit_code)
                ->where('user_id', Auth::id())
                ->exists();

            if ($nic_5_digit_code_exist) {
                return response()->json([
                    'status' => 0,
                    'message' => "NIC 5 digit code '{$request->nic_5_digit_code}' already exists for this user."
                ], 409);
            }

            $activity = Activity::create([
                'user_id' => Auth::id(),
                'activity_of_enterprise' => $request->activity_of_enterprise,
                'nic_2_digit_code' => $request->nic_2_digit_code,
                'nic_4_digit_code' => $request->nic_4_digit_code,
                'nic_5_digit_code' => $request->nic_5_digit_code,
            ]);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Activity saved successfully.',
                'data' => $activity
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function activity_delete(Request $request)
    {

        try {

            $request->validate([
                'id' => 'required|integer',
            ]);

            DB::beginTransaction();

            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $activity = Activity::where('id', $request->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$activity) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Activity not found.'
                ], 404);
            }

            $activity->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Activity deleted successfully.',
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

    public function activity_view()
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $activities = Activity::where('user_id', $user->id)->get();

            if (!$activities) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No activities found for this user.'
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Activities fetched successfully.',
                'data' => $activities,
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching.',
                'error_message' => $e->getMessage()
            ], 500);
        }
    }
}
