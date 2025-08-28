<?php

namespace App\Http\Controllers\Subdivision;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\TripuraMasterData;

class TripuraMasterDataController extends Controller
{
    public function get_districts()
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $districts = TripuraMasterData::select('district_code','district_name')
                ->distinct()
                ->orderBy('district_name')
                ->get();

            return response()->json([
                'status'        => 1,
                'message' => 'District list fetched successfully.',
                'districts' => $districts
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching district list',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_subdivisions(Request $request)
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'district' => 'required|string'
            ]);

            $subdivisions = TripuraMasterData::where('district_name', $request->district)
                ->orWhere('district_code', $request->district)
                ->select('sub_lgd_code','sub_division')
                ->distinct()
                ->get();

            return response()->json([
                'status'        => 1,
                'message' => 'Subdivisions list fetched successfully.',
                'subdivision' => $subdivisions
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching Subdivisions list',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_ulbs(Request $request)
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'subdivision' => 'required|string'
            ]);

            $ulbs = TripuraMasterData::where('sub_division', $request->subdivision)
                ->orWhere('sub_lgd_code', $request->subdivision)
                ->select('ulb_lgd_code','ulb_name')
                ->distinct()
                ->get();

            return response()->json([
                'status'        => 1,
                'message' => 'Ulbs list fetched successfully.',
                'ulbs' => $ulbs
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching Ulbs list',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_wards(Request $request)
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'ulb' => 'required|string'
            ]);

            $wards = TripuraMasterData::where('ulb_name', $request->ulb)
                ->orWhere('ulb_lgd_code', $request->ulb)
                ->select('gp_vc_ward_lgd_code','name_of_gp_vc_or_ward')
                ->distinct()
                ->get();

            return response()->json([
                'status'        => 1,
                'message' => 'Wards list fetched successfully.',
                'ward' => $wards
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching Wards list',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
