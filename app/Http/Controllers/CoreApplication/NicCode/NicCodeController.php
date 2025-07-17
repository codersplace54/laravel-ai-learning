<?php

namespace App\Http\Controllers\CoreApplication\NicCode;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\NicCode;
use Exception;

class NicCodeController extends Controller
{

    public function nic_code_store(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'nic_codes' => 'required|array',
                'nic_codes.*.nic_2_digit_code' => 'required|string',
                'nic_codes.*.nic_2_digit_code_description' => 'required|string',
                'nic_codes.*.nic_4_digit_codes' => 'required|array',
                'nic_codes.*.nic_4_digit_codes.*.nic_4_digit_code' => 'required|string',
                'nic_codes.*.nic_4_digit_codes.*.nic_4_digit_code_description' => 'required|string',
                'nic_codes.*.nic_4_digit_codes.*.nic_5_digit_codes' => 'required|array',
                'nic_codes.*.nic_4_digit_codes.*.nic_5_digit_codes.*.nic_5_digit_code' => 'required|string|unique:nic_codes,nic_5_digit_code',
                'nic_codes.*.nic_4_digit_codes.*.nic_5_digit_codes.*.nic_5_digit_code_description' => 'required|string',
            ]);

            DB::beginTransaction();

            $nic_code_array = [];

            foreach ($request->nic_codes as $nic2) {
                foreach ($nic2['nic_4_digit_codes'] as $nic4) {
                    foreach ($nic4['nic_5_digit_codes'] as $nic5) {
                        $nic_code = NicCode::create([
                            'nic_2_digit_code' => $nic2['nic_2_digit_code'],
                            'nic_2_digit_code_description' => $nic2['nic_2_digit_code_description'],
                            'nic_4_digit_code' => $nic4['nic_4_digit_code'],
                            'nic_4_digit_code_description' => $nic4['nic_4_digit_code_description'],
                            'nic_5_digit_code' => $nic5['nic_5_digit_code'],
                            'nic_5_digit_code_description' => $nic5['nic_5_digit_code_description'],
                            'added_by' => $user->id,
                        ]);
                        $nic_code_array[] = $nic_code->toArray();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'NIC Codes saved successfully.',
                'data' => $nic_code_array
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

            Log::error('Error saving NIC Codes: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }

    public function nic_code_update(Request $request)
    {

        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'nic_codes' => 'required|array',
                'nic_codes.*.nic_2_digit_code' => 'required|string',
                'nic_codes.*.nic_2_digit_code_description' => 'required|string',
                'nic_codes.*.nic_4_digit_codes' => 'required|array',
                'nic_codes.*.nic_4_digit_codes.*.nic_4_digit_code' => 'required|string',
                'nic_codes.*.nic_4_digit_codes.*.nic_4_digit_code_description' => 'required|string',
                'nic_codes.*.nic_4_digit_codes.*.nic_5_digit_codes' => 'required|array',
                'nic_codes.*.nic_4_digit_codes.*.nic_5_digit_codes.*.nic_5_digit_code' => 'required|string',
                'nic_codes.*.nic_4_digit_codes.*.nic_5_digit_codes.*.nic_5_digit_code_description' => 'required|string',
            ]);

            DB::beginTransaction();

            $nic_code_array = [];

            foreach ($request->nic_codes as $nic2) {
                foreach ($nic2['nic_4_digit_codes'] as $nic4) {
                    foreach ($nic4['nic_5_digit_codes'] as $nic5) {

                        $nic_5_exist = NicCode::where('nic_5_digit_code', $nic5['nic_5_digit_code'])->first();
                        if ($nic_5_exist) {
                            $nic_5_exist->delete();
                        }

                        $nic_code = NicCode::create([
                            'nic_2_digit_code' => $nic2['nic_2_digit_code'],
                            'nic_2_digit_code_description' => $nic2['nic_2_digit_code_description'],
                            'nic_4_digit_code' => $nic4['nic_4_digit_code'],
                            'nic_4_digit_code_description' => $nic4['nic_4_digit_code_description'],
                            'nic_5_digit_code' => $nic5['nic_5_digit_code'],
                            'nic_5_digit_code_description' => $nic5['nic_5_digit_code_description'],
                            'added_by' => $user->id,
                        ]);

                        $nic_code_array[] = $nic_code->toArray();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'NIC Codes updated successfully.',
                'data' => $nic_code_array
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            Log::error('Error updating NIC Codes: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }


    public function nic_code_view()
    {

        try {


            $nic_codes = NicCode::orderBy('nic_2_digit_code')
                ->orderBy('nic_4_digit_code')
                ->orderBy('nic_5_digit_code')
                ->get();

            if ($nic_codes->isEmpty()) {
                return response()->json([
                    'status' => 1,
                    'message' => 'No NIC codes found.',
                    'data' => [],
                ], 200);
            }

            $get_codes = [];

            $nic_2_digit_codes =  $nic_codes->groupBy('nic_2_digit_code');

            foreach ($nic_2_digit_codes as $nic_2_digit => $nic_2) {

                $nic_2_digit_code = [
                    'nic_2_digit_code' => $nic_2_digit,
                    'nic_2_digit_code_description' => $nic_2->first()->nic_2_digit_code_description,
                    'nic_4_digit_codes' => [],
                ];

                $nic_4_digit_codes = $nic_codes->groupBy('nic_4_digit_code');

                foreach ($nic_4_digit_codes as $nic_4_digit => $nic_4) {
                    $nic_4_digit_code = [
                        'nic_4_digit_code' => $nic_4_digit,
                        'nic_4_digit_code_description' => $nic_4->first()->nic_4_digit_code_description,
                        'nic_5_digit_codes' => [],
                    ];

                    foreach ($nic_4 as $row) {
                        $nic_4_digit_code['nic_5_digit_codes'][] = [
                            'id' => $row->id,
                            'nic_5_digit_code' => $row->nic_5_digit_code,
                            'nic_5_digit_code_description' => $row->nic_5_digit_code_description,
                            'added_by' => $row->added_by
                        ];
                    }

                    $nic_2_digit_code['nic_4_digit_codes'][] = $nic_4_digit_code;
                }

                $get_codes[] = $nic_2_digit_code;
            }

            return response()->json([
                'status' => 1,
                'message' => 'Nested NIC codes fetched successfully.',
                'data' => $get_codes,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function nic_code_delete(Request $request)
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'nic_2_digit_code' => 'required|string',
            ]);

            DB::beginTransaction();


            $deleted = NicCode::where('nic_2_digit_code', $request->nic_2_digit_code)->delete();

            DB::commit();

            if ($deleted === 0) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No records found to delete.'
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'NIC code(s) deleted successfully.'
            ], 200);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
