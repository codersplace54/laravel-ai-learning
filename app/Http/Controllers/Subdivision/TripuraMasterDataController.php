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

            $districts = TripuraMasterData::select('district_code', 'district_name')
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


            $request->validate([
                'district' => 'required|string'
            ]);

            $subdivisions = TripuraMasterData::where('district_name', $request->district)
                ->orWhere('district_code', $request->district)
                ->select('sub_lgd_code', 'sub_division')
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


            $request->validate([
                'subdivision' => 'required|string'
            ]);

            $ulbs = TripuraMasterData::where('sub_division', $request->subdivision)
                ->orWhere('sub_lgd_code', $request->subdivision)
                ->select('ulb_lgd_code', 'ulb_name')
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


            $request->validate([
                'ulb' => 'required|string'
            ]);

            $wards = TripuraMasterData::where('ulb_name', $request->ulb)
                ->orWhere('ulb_lgd_code', $request->ulb)
                ->select('gp_vc_ward_lgd_code', 'name_of_gp_vc_or_ward')
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

    public function get__multiple_subdivisions(Request $request)
    {


        try {

            $request->validate([
                'districts' => 'required|array|min:1',
                'districts.*' => 'integer|exists:tripura_master_data,district_code'
            ]);

            $subdivisions = TripuraMasterData::whereIn('district_code', $request->districts)
                ->select('sub_lgd_code AS sub_division_code', 'sub_division AS sub_division_name', 'district_code' , 'district_name')
                ->distinct()
                ->get();

            return response()->json([
                'status' => 1,
                'message' => 'Subdivisions list fetched successfully.',
                'subdivisions' => $subdivisions
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch subdivisions.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_multiple_ulbs(Request $request)
    {


        try {

            $request->validate([
                'subdivisions' => 'required|array|min:1',
                'subdivisions.*' => 'integer|exists:tripura_master_data,sub_lgd_code'
            ]);

            $ulbs = TripuraMasterData::whereIn('sub_lgd_code', $request->subdivisions)
                ->select('ulb_lgd_code AS block_code', 'ulb_name AS block_name', 'sub_lgd_code as subdivision_code','sub_division as sub_division_name', 'district_code', 'district_name')
                ->distinct()
                ->get();

            return response()->json([
                'status' => 1,
                'message' => 'ULB list fetched successfully.',
                'ulbs' => $ulbs
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch ULB list.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
