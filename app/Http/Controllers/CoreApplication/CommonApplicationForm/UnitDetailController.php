<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\UnitDetail;
use App\Models\UserUnit;

class UnitDetailController extends Controller
{
    public function unit_details_store_or_update(Request $request)
    {

        try {


            if ($request->save_data != 1) {
                $request->validate([
                    'unit_name' => 'required|string|max:255',
                    'unit_address' => 'required|string',
                    'pin_no' => 'required|string',
                    'post_office' => 'nullable|string',
                    'contact_no' => 'nullable|string',
                    'fax' => 'nullable|string',
                    'email' => 'nullable|email',
                    'website' => 'nullable|url',

                    'unit_location_district' => 'required|string',
                    'unit_location_subdivision' => 'required|string',
                    'unit_location_police_station' => 'required|string',
                    'unit_location_land_type' => 'required|string|in:Industrial Estate,Panchayat,Municipality',
                    'unit_location_area_type' => 'required|string|in:Urban,Rural',
                    'unit_location_estate_name' => 'nullable|string|required_if:unit_location_land_type,Industrial Estate',
                    'unit_location_plot_no' => 'nullable|string|required_if:unit_location_land_type,Industrial Estate',
                    'unit_location_block' => 'nullable|string|required_if:unit_location_land_type,Panchayat',
                    'unit_location_gram_panchayat' => 'nullable|string|required_if:unit_location_land_type,Panchayat',
                    'unit_location_municipality' => 'nullable|string|required_if:unit_location_land_type,Municipality',
                    'unit_location_ward_no' => 'nullable|string|required_if:unit_location_land_type,Municipality',
                    'unit_location_planning_area' => 'nullable|string',

                    'land_record_details_revenue_circle' => 'nullable|string',
                    'land_record_details_tehasil' => 'nullable|string',
                    'land_record_details_revenue_mouza' => 'nullable|string',
                    'land_record_details_khatian_number_new' => 'nullable|string',
                    'land_record_details_plot_number_cs_sabek' => 'nullable|string',
                    'land_record_details_plot_number_rs_hal' => 'nullable|string',
                    'land_record_details_classification_of_land' => 'nullable|in:Agriculture,Commercial,Residential,Industrial',
                    'land_record_details_land_area' => 'nullable|string',
                    'land_record_details_unit' => 'nullable|in:Sq Mtr,Acre,Hector',

                    'construction_details_load_bearing_in_sq_mtr' => 'nullable|string',
                    'construction_details_rcc_building_in_sq_mtr' => 'nullable|string',
                    'construction_details_others_construction' => 'nullable|string',
                    'construction_details_sanitary_latrine_count' => 'nullable|integer',
                    'construction_details_boundary_wall_in_mtr' => 'nullable|string',
                    'construction_details_power_supply_agency_at_the_factory' => 'nullable|string',

                    'investment_details_value_of_land_as_per_sale_deed' => 'nullable|string',
                    'investment_details_value_of_building' => 'nullable|string',
                    'investment_details_value_of_plant_machinery_or_service_equipment' => 'nullable|string',
                    'investment_details_total_project_cost' => 'nullable|string',

                    'employment_details_worker_men_count' => 'nullable|string',
                    'employment_details_worker_women_count' => 'nullable|string',
                    'employment_details_management_staff_count' => 'nullable|string',
                    'employment_details_others_count' => 'nullable|string',
                    'employment_details_total_employment' => 'nullable|string',

                    'annual_turnover' =>  'required|string',
                    'category_of_enterprise' => 'nullable|in:Micro,Small,Medium,Large',
                    'working_session' => 'nullable|string',
                    'product_manufacturing_process' => 'nullable|string',
                ], [
                    'unit_name.required' => 'Unit name is required.',
                    'unit_name.string' => 'Unit name must be a valid string.',
                    'unit_name.max' => 'Unit name cannot exceed 255 characters.',

                    'unit_address.required' => 'Unit address is required.',
                    'unit_address.string' => 'Unit address must be a valid string.',

                    'pin_no.required' => 'PIN number is required.',
                    'pin_no.string' => 'PIN number must be a valid string.',

                    'post_office.string' => 'Post office must be a valid string.',

                    'contact_no.string' => 'Contact number must be a valid string.',

                    'fax.string' => 'Fax must be a valid string.',

                    'email.email' => 'Email must be a valid email address.',

                    'website.url' => 'Website must be a valid URL.',

                    'unit_location_district.required' => 'District is required.',
                    'unit_location_district.string' => 'District must be a valid string.',

                    'unit_location_subdivision.required' => 'Subdivision is required.',
                    'unit_location_subdivision.string' => 'Subdivision must be a valid string.',

                    'unit_location_police_station.required' => 'Police station is required.',
                    'unit_location_police_station.string' => 'Police station must be a valid string.',

                    'unit_location_land_type.required' => 'Land type is required.',
                    'unit_location_land_type.in' => 'Land type must be one of: Industrial Estate, Panchayat, Municipality.',

                    'unit_location_area_type.required' => 'Area type is required.',
                    'unit_location_area_type.in' => 'Area type must be one of: Urban, Rural.',

                    'unit_location_estate_name.string' => 'Estate name must be a valid string.',
                    'unit_location_estate_name.required_if' => 'Estate name is required when land type is Industrial Estate.',

                    'unit_location_plot_no.string' => 'Plot number must be a valid string.',
                    'unit_location_plot_no.required_if' => 'Plot number is required when land type is Industrial Estate.',

                    'unit_location_block.string' => 'Block must be a valid string.',
                    'unit_location_block.required_if' => 'Block is required when land type is Panchayat.',

                    'unit_location_gram_panchayat.string' => 'Gram panchayat must be a valid string.',
                    'unit_location_gram_panchayat.required_if' => 'Gram panchayat is required when land type is Panchayat.',

                    'unit_location_municipality.string' => 'Municipality must be a valid string.',
                    'unit_location_municipality.required_if' => 'Municipality is required when land type is Municipality.',

                    'unit_location_ward_no.string' => 'Ward number must be a valid string.',
                    'unit_location_ward_no.required_if' => 'Ward number is required when land type is Municipality.',

                    'unit_location_planning_area.string' => 'Planning area must be a valid string.',

                    'land_record_details_revenue_circle.string' => 'Revenue circle must be a valid string.',

                    'land_record_details_tehasil.string' => 'Tehasil must be a valid string.',

                    'land_record_details_revenue_mouza.string' => 'Revenue mouza must be a valid string.',

                    'land_record_details_khatian_number_new.string' => 'Khatian number must be a valid string.',

                    'land_record_details_plot_number_cs_sabek.string' => 'Plot number CS/Sabek must be a valid string.',

                    'land_record_details_plot_number_rs_hal.string' => 'Plot number RS/Hal must be a valid string.',

                    'land_record_details_classification_of_land.in' => 'Land classification must be one of: Agriculture, Commercial, Residential, Industrial.',

                    'land_record_details_land_area.string' => 'Land area must be a valid string.',

                    'land_record_details_unit.in' => 'Land unit must be one of: Sq Mtr, Acre, Hector.',

                    'construction_details_load_bearing_in_sq_mtr.string' => 'Load bearing area must be a valid string.',

                    'construction_details_rcc_building_in_sq_mtr.string' => 'RCC building area must be a valid string.',

                    'construction_details_others_construction.string' => 'Other construction details must be a valid string.',

                    'construction_details_sanitary_latrine_count.integer' => 'Sanitary latrine count must be a valid integer.',

                    'construction_details_boundary_wall_in_mtr.string' => 'Boundary wall length must be a valid string.',

                    'construction_details_power_supply_agency_at_the_factory.string' => 'Power supply agency must be a valid string.',

                    'investment_details_value_of_land_as_per_sale_deed.string' => 'Land value must be a valid string.',

                    'investment_details_value_of_building.string' => 'Building value must be a valid string.',

                    'investment_details_value_of_plant_machinery_or_service_equipment.string' => 'Plant and machinery value must be a valid string.',

                    'investment_details_total_project_cost.string' => 'Total project cost must be a valid string.',

                    'employment_details_worker_men_count.string' => 'Worker men count must be a valid string.',

                    'employment_details_worker_women_count.string' => 'Worker women count must be a valid string.',

                    'employment_details_management_staff_count.string' => 'Management staff count must be a valid string.',

                    'employment_details_others_count.string' => 'Others count must be a valid string.',

                    'employment_details_total_employment.string' => 'Total employment must be a valid string.',

                    'annual_turnover.required' => 'Annual turnover is required.',
                    'annual_turnover.string' => 'Annual turnover must be a valid string.',

                    'category_of_enterprise.in' => 'Category of enterprise must be one of: Micro, Small, Medium, Large.',

                    'working_session.string' => 'Working session must be a valid string.',

                    'product_manufacturing_process.string' => 'Product manufacturing process must be a valid string.',
                ]);
            }
            DB::beginTransaction();

            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthorized. User not authenticated.',
                ], 401);
            }

            $unit_details = UnitDetail::where('user_id', $user->id)->first();

            if ($unit_details) {
                $unit_details->update([
                    'unit_name' => $request->unit_name,
                    'unit_address' => $request->unit_address,
                    'pin_no' => $request->pin_no,
                    'post_office' => $request->post_office,
                    'contact_no' => $request->contact_no,
                    'fax' => $request->fax,
                    'email' => $request->email,
                    'website' => $request->website,

                    'unit_location_district' => $request->unit_location_district,
                    'unit_location_subdivision' => $request->unit_location_subdivision,
                    'unit_location_police_station' => $request->unit_location_police_station,
                    'unit_location_land_type' => $request->unit_location_land_type,
                    'unit_location_area_type' => $request->unit_location_area_type,
                    'unit_location_estate_name' => $request->unit_location_estate_name,
                    'unit_location_plot_no' => $request->unit_location_plot_no,
                    'unit_location_block' => $request->unit_location_block,
                    'unit_location_gram_panchayat' => $request->unit_location_gram_panchayat,
                    'unit_location_municipality' => $request->unit_location_municipality,
                    'unit_location_ward_no' => $request->unit_location_ward_no,
                    'unit_location_planning_area' => $request->unit_location_planning_area,

                    'land_record_details_revenue_circle' => $request->land_record_details_revenue_circle,
                    'land_record_details_tehasil' => $request->land_record_details_tehasil,
                    'land_record_details_revenue_mouza' => $request->land_record_details_revenue_mouza,
                    'land_record_details_khatian_number_new' => $request->land_record_details_khatian_number_new,
                    'land_record_details_plot_number_cs_sabek' => $request->land_record_details_plot_number_cs_sabek,
                    'land_record_details_plot_number_rs_hal' => $request->land_record_details_plot_number_rs_hal,
                    'land_record_details_classification_of_land' => $request->land_record_details_classification_of_land,
                    'land_record_details_land_area' => $request->land_record_details_land_area,
                    'land_record_details_unit' => $request->land_record_details_unit,

                    'construction_details_load_bearing_in_sq_mtr' => $request->construction_details_load_bearing_in_sq_mtr,
                    'construction_details_rcc_building_in_sq_mtr' => $request->construction_details_rcc_building_in_sq_mtr,
                    'construction_details_others_construction' => $request->construction_details_others_construction,
                    'construction_details_sanitary_latrine_count' => $request->construction_details_sanitary_latrine_count,
                    'construction_details_boundary_wall_in_mtr' => $request->construction_details_boundary_wall_in_mtr,
                    'construction_details_power_supply_agency_at_the_factory' => $request->construction_details_power_supply_agency_at_the_factory,

                    'investment_details_value_of_land_as_per_sale_deed' => $request->investment_details_value_of_land_as_per_sale_deed,
                    'investment_details_value_of_building' => $request->investment_details_value_of_building,
                    'investment_details_value_of_plant_machinery_or_service_equipment' => $request->investment_details_value_of_plant_machinery_or_service_equipment,
                    'investment_details_total_project_cost' => $request->investment_details_total_project_cost,

                    'employment_details_worker_men_count' => $request->employment_details_worker_men_count,
                    'employment_details_worker_women_count' => $request->employment_details_worker_women_count,
                    'employment_details_management_staff_count' => $request->employment_details_management_staff_count,
                    'employment_details_others_count' => $request->employment_details_others_count,
                    'employment_details_total_employment' => $request->employment_details_total_employment,

                    'annual_turnover' => $request->annual_turnover,
                    'category_of_enterprise' => $request->category_of_enterprise,
                    'working_session' => $request->working_session,
                    'product_manufacturing_process' => $request->product_manufacturing_process,
                ]);

                DB::commit();

                return response()->json([
                    'status' => 1,
                    'message' => 'Unit details updated successfully.',
                    'unit_id' => $unit_details->id
                ], 200);
            } else {
                $new_unit_details = UnitDetail::create([
                    'user_id' => $user->id,
                    'unit_name' => $request->unit_name,
                    'unit_address' => $request->unit_address,
                    'pin_no' => $request->pin_no,
                    'post_office' => $request->post_office,
                    'contact_no' => $request->contact_no,
                    'fax' => $request->fax,
                    'email' => $request->email,
                    'website' => $request->website,

                    'unit_location_district' => $request->unit_location_district,
                    'unit_location_subdivision' => $request->unit_location_subdivision,
                    'unit_location_police_station' => $request->unit_location_police_station,
                    'unit_location_land_type' => $request->unit_location_land_type,
                    'unit_location_area_type' => $request->unit_location_area_type,
                    'unit_location_estate_name' => $request->unit_location_estate_name,
                    'unit_location_plot_no' => $request->unit_location_plot_no,
                    'unit_location_block' => $request->unit_location_block,
                    'unit_location_gram_panchayat' => $request->unit_location_gram_panchayat,
                    'unit_location_municipality' => $request->unit_location_municipality,
                    'unit_location_ward_no' => $request->unit_location_ward_no,
                    'unit_location_planning_area' => $request->unit_location_planning_area,

                    'land_record_details_revenue_circle' => $request->land_record_details_revenue_circle,
                    'land_record_details_tehasil' => $request->land_record_details_tehasil,
                    'land_record_details_revenue_mouza' => $request->land_record_details_revenue_mouza,
                    'land_record_details_khatian_number_new' => $request->land_record_details_khatian_number_new,
                    'land_record_details_plot_number_cs_sabek' => $request->land_record_details_plot_number_cs_sabek,
                    'land_record_details_plot_number_rs_hal' => $request->land_record_details_plot_number_rs_hal,
                    'land_record_details_classification_of_land' => $request->land_record_details_classification_of_land,
                    'land_record_details_land_area' => $request->land_record_details_land_area,
                    'land_record_details_unit' => $request->land_record_details_unit,

                    'construction_details_load_bearing_in_sq_mtr' => $request->construction_details_load_bearing_in_sq_mtr,
                    'construction_details_rcc_building_in_sq_mtr' => $request->construction_details_rcc_building_in_sq_mtr,
                    'construction_details_others_construction' => $request->construction_details_others_construction,
                    'construction_details_sanitary_latrine_count' => $request->construction_details_sanitary_latrine_count,
                    'construction_details_boundary_wall_in_mtr' => $request->construction_details_boundary_wall_in_mtr,
                    'construction_details_power_supply_agency_at_the_factory' => $request->construction_details_power_supply_agency_at_the_factory,

                    'investment_details_value_of_land_as_per_sale_deed' => $request->investment_details_value_of_land_as_per_sale_deed,
                    'investment_details_value_of_building' => $request->investment_details_value_of_building,
                    'investment_details_value_of_plant_machinery_or_service_equipment' => $request->investment_details_value_of_plant_machinery_or_service_equipment,
                    'investment_details_total_project_cost' => $request->investment_details_total_project_cost,

                    'employment_details_worker_men_count' => $request->employment_details_worker_men_count,
                    'employment_details_worker_women_count' => $request->employment_details_worker_women_count,
                    'employment_details_management_staff_count' => $request->employment_details_management_staff_count,
                    'employment_details_others_count' => $request->employment_details_others_count,
                    'employment_details_total_employment' => $request->employment_details_total_employment,

                    'annual_turnover' => $request->annual_turnover,
                    'category_of_enterprise' => $request->category_of_enterprise,
                    'working_session' => $request->working_session,
                    'product_manufacturing_process' => $request->product_manufacturing_process,
                ]);

                DB::commit();

                return response()->json([
                    'status' => 1,
                    'message' => 'Unit details created successfully.',
                    'unit_id' => $new_unit_details->id
                ], 201);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to process unit details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function unit_details_view(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {


                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthorized. User not authenticated.',
                ], 401);
            }

            $unitDetails = UnitDetail::where('user_id', $user->id)->first();

            if (!$unitDetails) {


                return response()->json([
                    'status' => 0,
                    'message' => 'Unit details not found.',
                ], 404);
            }

            return response()->json([


                'status' => 1,
                'message' => 'Unit details fetched successfully.',
                'data' => $unitDetails,
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch unit details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_user_caf_unit_details(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {


                return response()->json([
                    'status' => 0,
                    'message' => 'User not authenticated.',
                ], 401);
            }

            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);


            $unitDetails = UnitDetail::where('user_id', $request->user_id)->first();
            $user_units = UserUnit::with([
                'district',
                'subdivision',
                'ulb',
                'ward',
            ])
                ->where('user_id', $request->user_id)
                ->get()
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'unit_name' => $unit->unit_name,
                        'address' => $unit->address,
                        'phone' => $unit->phone,
                        'type' => $unit->type ?? null,
                        'district_code' => $unit->district_id,
                        'district_name' => $unit->district->district_name ?? null,
                        'subdivision_code' => $unit->subdivision_id,
                        'subdivision_name' => $unit->subdivision->sub_division ?? null,
                        'block_code' => $unit->ulb_id,
                        'block_name' => $unit->ulb->ulb_name ?? null,
                        'ward_code' => $unit->ward_id,
                        'ward_name' => $unit->ward->name_of_gp_vc_or_ward ?? null,
                        'status' => $unit->status,
                        'created_at' => $unit->created_at,
                    ];
                });

            if (!$unitDetails) {


                return response()->json([
                    'status' => 0,
                    'message' => 'Unit details not found.',
                ], 404);
            }

            return response()->json([


                'status' => 1,
                'message' => 'Unit details fetched successfully.',
                'data' => $unitDetails,
                'multiple_units'  => $user_units,
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch unit details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update_user_unit_status(Request $request)
    {


        try {

            $request->validate([
                'id' => 'required|exists:user_units,id',
                'status' => 'required|in:active,blocked',
            ]);

            $unit = UserUnit::findOrFail($request->id);

            $unit->status = $request->status;
            $unit->save();

            return response()->json([
                'success' => true,
                'message' => 'User unit status updated successfully',
                'data' => $unit
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
