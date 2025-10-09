<?php

namespace App\Http\Controllers\Incentive;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Scheme;

class SchemeController extends Controller
{
    public function scheme_store(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'code'              => 'required|string|max:64|unique:schemes,code',
                'title'             => 'required|string|max:200',
                'policy_start_date' => 'nullable|date',
                'policy_end_date'   => 'nullable|date|after_or_equal:policy_start_date',
                'status'            => 'nullable|integer',
            ]);

            DB::beginTransaction();

            $user = Auth::user();
            $scheme = Scheme::create([
                'code' => $request->code,
                'title' => $request->title,
                'policy_start_date' => $request->policy_start_date,
                'policy_end_date' => $request->policy_end_date,
                'status' => $request->status,
                'created_by' => $user->email_id
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Scheme created successfully.',
                'data'    => $scheme,
            ], 201);


        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }


    public function scheme_update(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'scheme_id'         => 'required|integer|exists:schemes,id',
                'code'              => 'sometimes|string|max:64|unique:schemes,code,' . $request->scheme_id,
                'title'             => 'sometimes|string|max:200',
                'policy_start_date' => 'nullable|date',
                'policy_end_date'   => 'nullable|date|after_or_equal:policy_start_date',
                'status'            => 'nullable|integer',
            ]);

            DB::beginTransaction();

            $scheme = Scheme::where('id',$request->scheme_id)->first();
            $user = Auth::user();
            $scheme->update([
                'code'              => $request->code ,
                'title'             => $request->title,
                'policy_start_date' => $request->policy_start_date,
                'policy_end_date'   => $request->policy_end_date,
                'status'            => $request->status,
                'updated_by'        => $user->email_id
            ]);


            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Scheme updated successfully.',
                'data' => $scheme,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }


    public function scheme_list()
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $data = Scheme::query()
                ->orderByDesc('id')
                ->withCount('proformas')
                ->get();

            return response()->json([
                'status'  => 1,
                'message' => 'Schemes fetched successfully.',
                'data'    => $data,
            ]);

        } catch (\Exception $e) {

            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    public function fetch_scheme_details(Request $request)
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'scheme_id' => 'required|integer|exists:schemes,id',
            ]);

            $scheme = Scheme::where('id', $request->scheme_id)->first();

            return response()->json([
                'status' => 1,
                'message' => 'Scheme details fetched successfully.',
                'data' => $scheme,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
            
        }
    }

    public function scheme_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:schemes,id',
            ]);

            DB::beginTransaction();

            $scheme = Scheme::where('id', $request->id)->first();

            $scheme->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Scheme deleted successfully.',
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
