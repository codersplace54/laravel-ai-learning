<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Holiday;
use Carbon\Carbon;

class HolidayController extends Controller
{
    public function holidays_store(Request $request)
    {

        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'holiday_date' => 'required|date|unique:holidays,holiday_date',
                'description'  => 'nullable|string|max:255',
            ]);

            DB::beginTransaction();

            $holiday = Holiday::create([
                'holiday_date' => $request->holiday_date,
                'description'  => $request->description
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Holiday created successfully.',
                'data'    => $holiday
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'error'   => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function holidays_update(Request $request)
    {

        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id'           => 'required|integer|exists:holidays,id',
                'holiday_date' => 'required|date|unique:holidays,holiday_date,' . $request->id,
                'description'  => 'nullable|string|max:255',
            ]);

            DB::beginTransaction();

            $holiday = Holiday::where('id', $request->id)->first();

            $holiday->update([
                'holiday_date' => $request->holiday_date,
                'description'  => $request->description
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Holiday updated successfully.',
                'data'    => $holiday
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'error'   => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function holidays_view()
    {

        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $holidays = Holiday::orderBy('holiday_date', 'asc')->get();

            if ($holidays->isEmpty()) {

                return response()->json([
                    'status'  => 1,
                    'message' => 'No holidays found.',
                    'data'    => []
                ], 200);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Holidays fetched successfully.',
                'data'    => $holidays
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function holiday_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:holidays,id',
            ]);

            DB::beginTransaction();

            $holiday = Holiday::where('id', $request->id)->first();

            if (!$holiday) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Holiday not found.'
                ], 404);
            }

            $holiday->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Holiday deleted successfully.',
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

    public function holiday_disabled_dates()
    {


        try {

            $dates = Holiday::orderBy('holiday_date', 'asc')
                ->pluck('holiday_date')
                ->map(function ($date) {
                    return Carbon::parse($date)->format('Y-m-d');
                })
                ->values();

            return response()->json([
                'disabled_dates' => $dates
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
