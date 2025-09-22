<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\BankDetail;

class BankDetailController extends Controller
{
    public function bank_detail_store_or_update(Request $request)
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $bank_detail = BankDetail::where('user_id', $user->id)->first();

            if ($request->save_data != 1) {
                $request->validate([
                    'bank_name' => 'required|string|max:255',
                    'branch_name' => 'required|string|max:255',
                    'account_type' => 'required|in:Saving,Current,Other',
                    'account_holder_name' => 'required|string|max:255',
                    'account_number' => 'required|string|max:50',
                    'ifsc_code' => 'required|string|max:20',
                ]);
            }


            DB::beginTransaction();

            if ($bank_detail) {
                $bank_detail->update([
                    'bank_name' => $request->bank_name,
                    'branch_name' => $request->branch_name,
                    'account_type' => $request->account_type,
                    'account_holder_name' => $request->account_holder_name,
                    'account_number' => $request->account_number,
                    'ifsc_code' => $request->ifsc_code,
                ]);
            } else {
                $bank_detail = BankDetail::create([
                    'user_id' => $user->id,
                    'bank_name' => $request->bank_name,
                    'branch_name' => $request->branch_name,
                    'account_type' => $request->account_type,
                    'account_holder_name' => $request->account_holder_name,
                    'account_number' => $request->account_number,
                    'ifsc_code' => $request->ifsc_code,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Bank detail saved successfully.',
                'bank_detail' => $bank_detail,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            Log::error('Error saving bank detail: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error_message' => $e->getMessage()
            ], 500);
        }
    }

    public function bank_detail_view()
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $bankDetail = BankDetail::where('user_id', $user->id)->first();

            if (!$bankDetail) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No bank details found for this user.'
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Bank detail fetched successfully.',
                'data' => $bankDetail,
            ], 200);
        } catch (\Exception $e) {


            Log::error('Error fetching bank detail: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching.',
                'error_message' => $e->getMessage()
            ], 500);
        }
    }

    public function get_user_caf_bank_details(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);

            $bankDetail = BankDetail::where('user_id', $request->user_id)->first();

            if (!$bankDetail) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No bank details found for this user.'
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Bank detail fetched successfully.',
                'data' => $bankDetail,
            ], 200);
        } catch (\Exception $e) {


            Log::error('Error fetching bank detail: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching.',
                'error_message' => $e->getMessage()
            ], 500);
        }
    }
}
