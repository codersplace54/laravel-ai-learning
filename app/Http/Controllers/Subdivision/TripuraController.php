<?php

namespace App\Http\Controllers\Subdivision;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Tripura;

class TripuraController extends Controller
{
    public function get_districts()
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $districts = Tripura::select('district')
                ->distinct()
                ->orderBy('district')
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

            $subdivisions = Tripura::where('district', $request->district)
                ->select('subdivision')
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

            $ulbs = Tripura::where('subdivision', $request->subdivision)
                ->select('ulb')
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

            $wards = Tripura::where('ulb', $request->ulb)
                ->select('ward')
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
