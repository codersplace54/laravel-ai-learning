<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\UnitDetails;

class UnitDetailsController extends Controller
{
    public function unit_details_store_or_update(Request $request)
    {

        try {


            $request->validate([
                'unit_name' => 'required|string|max:255',
                'unit_address' => 'required|string',
                'district' => 'required|string',
                'subdivision' => 'required|string',
                'block' => 'nullable|string',
                'police_station' => 'required|string',
                'post_office' => 'required|string',
                'pin_no' => 'required|string',
                'contact_no' => 'required|string',
                'fax' => 'nullable|string',
                'email' => 'required|email',
                'website' => 'nullable|url',
                'land_type' => 'required|string',
                'area_type' => 'required|string',
                'planning_area' => 'required|string',
                'estate_name' => 'required|string',
                'plot_no' => 'required|string',
                'khatian_no_new' => 'required|string',
                'plot_no_cs_sabek' => 'required|string',
                'plot_no_rs_hal' => 'required|string',
                'classification_of_land' => 'required|string',
                'land_area' => 'required|string',
                'load_bearing_building_sq_mtr' => 'required|string',
                'rcc_building_sq_mtr' => 'required|string',
                'others_construction' => 'required|string',
                'sanitary_latrine_count' => 'required|integer',
                'boundary_wall_mtr' => 'required|string',
                'power_supply_agency' => 'required|string',
            ]);

            DB::beginTransaction();

            $user = Auth::user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated user.'], 401);
            }

            $unit_details = UnitDetails::where('user_id', $user->id)->first();

            if ($unit_details) {
                $unit_details->update([
                    'unit_name' => $request->unit_name,
                    'unit_address' => $request->unit_address,
                    'district' => $request->district,
                    'subdivision' => $request->subdivision,
                    'block' => $request->block,
                    'police_station' => $request->police_station,
                    'post_office' => $request->post_office,
                    'pin_no' => $request->pin_no,
                    'contact_no' => $request->contact_no,
                    'fax' => $request->fax,
                    'email' => $request->email,
                    'website' => $request->website,
                    'land_type' => $request->land_type,
                    'area_type' => $request->area_type,
                    'planning_area' => $request->planning_area,
                    'estate_name' => $request->estate_name,
                    'plot_no' => $request->plot_no,
                    'khatian_no_new' => $request->khatian_no_new,
                    'plot_no_cs_sabek' => $request->plot_no_cs_sabek,
                    'plot_no_rs_hal' => $request->plot_no_rs_hal,
                    'classification_of_land' => $request->classification_of_land,
                    'land_area' => $request->land_area,
                    'load_bearing_building_sq_mtr' => $request->load_bearing_building_sq_mtr,
                    'rcc_building_sq_mtr' => $request->rcc_building_sq_mtr,
                    'others_construction' => $request->others_construction,
                    'sanitary_latrine_count' => $request->sanitary_latrine_count,
                    'boundary_wall_mtr' => $request->boundary_wall_mtr,
                    'power_supply_agency' => $request->power_supply_agency,
                ]);
            } else {

                $unit_details = UnitDetails::create([
                    'user_id' => $user->id,
                    'unit_name' => $request->unit_name,
                    'unit_address' => $request->unit_address,
                    'district' => $request->district,
                    'subdivision' => $request->subdivision,
                    'block' => $request->block,
                    'police_station' => $request->police_station,
                    'post_office' => $request->post_office,
                    'pin_no' => $request->pin_no,
                    'contact_no' => $request->contact_no,
                    'fax' => $request->fax,
                    'email' => $request->email,
                    'website' => $request->website,
                    'land_type' => $request->land_type,
                    'area_type' => $request->area_type,
                    'planning_area' => $request->planning_area,
                    'estate_name' => $request->estate_name,
                    'plot_no' => $request->plot_no,
                    'khatian_no_new' => $request->khatian_no_new,
                    'plot_no_cs_sabek' => $request->plot_no_cs_sabek,
                    'plot_no_rs_hal' => $request->plot_no_rs_hal,
                    'classification_of_land' => $request->classification_of_land,
                    'land_area' => $request->land_area,
                    'load_bearing_building_sq_mtr' => $request->load_bearing_building_sq_mtr,
                    'rcc_building_sq_mtr' => $request->rcc_building_sq_mtr,
                    'others_construction' => $request->others_construction,
                    'sanitary_latrine_count' => $request->sanitary_latrine_count,
                    'boundary_wall_mtr' => $request->boundary_wall_mtr,
                    'power_supply_agency' => $request->power_supply_agency,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Unit details saved successfully.',
                'data' => $unit_details
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('Error saving unit details: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while saving unit details.',
            ], 500);
        }
    }

    public function unit_details_view(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $unit_details = UnitDetails::where('user_id', $user->id)->first();

            if (!$unit_details) {
                return response()->json(['success' => false, 'message' => 'Unit details not found.'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $unit_details,
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }
}
