<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Activity;

use function PHPUnit\Framework\isEmpty;

class ActivityController extends Controller
{

    public function activity_store(Request $request)
    {

        try {


            if ($request->save_data != 1) {
                $request->validate([
                    'activities' => 'required|array',
                    'activities.*.activity_of_enterprise' => 'required|string',
                    'activities.*.nic_2_digit_code' => 'required|string',
                    'activities.*.nic_4_digit_code' => 'required|string',
                    'activities.*.nic_5_digit_code' => 'required|string',
                ]);
            }

            DB::beginTransaction();

            $list_of_activities = [];
            foreach ($request->activities as $activity) {
                $existing_activity = Activity::where('user_id', Auth::id())
                    ->where('nic_5_digit_code', $activity['nic_5_digit_code'])
                    ->first();

                if ($existing_activity) {
                    $existing_activity->update([
                        'activity_of_enterprise' => $activity['activity_of_enterprise'],
                        'nic_2_digit_code' => $activity['nic_2_digit_code'],
                        'nic_4_digit_code' => $activity['nic_4_digit_code'],
                    ]);

                    $list_of_activities[] = $existing_activity->toArray();
                } else {
                    $new_activity = Activity::create([
                        'user_id' => Auth::id(),
                        'activity_of_enterprise' => $activity['activity_of_enterprise'],
                        'nic_2_digit_code' => $activity['nic_2_digit_code'],
                        'nic_4_digit_code' => $activity['nic_4_digit_code'],
                        'nic_5_digit_code' => $activity['nic_5_digit_code'],
                    ]);

                    $list_of_activities[] = $new_activity->toArray();
                }
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Activity saved successfully.',
                'data' => $list_of_activities
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
                    'status' => 1,
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

    public function get_user_caf_activity_details(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);

            $activities = Activity::where('user_id', $request->user_id)->get();

            if ($activities->isEmpty()) {
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
