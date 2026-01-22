<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use Illuminate\Http\Request;
use App\Models\EnterpriseDetail;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EnterpriseDetailController extends Controller
{

    public function enterprise_details_store_or_update(Request $request)
    {

        try {

            if ($request->save_data != 1) {
                $request->validate(
                    [
                        'constitution_of_enterprise' => 'required|string|max:255',
                        'enterprise_name' => 'required|string|max:255',
                        'business_pan_no' => 'required|string|max:10',
                        'enterprise_address' => 'required|string|max:255',
                        'enterprises_registered_address' => 'required|string|max:255',
                        'habitation_area_building' => 'nullable|string|max:255',
                        'pin' => 'required|digits:6',
                        'post_office' => 'required|string|max:255',
                        'police_station' => 'required|string|max:255',

                        'authorized_representative_name' => 'required|string|max:255',
                        'authorized_representative_designation' => 'required|string|max:255',
                        'authorized_representative_aadhar_no' => 'required|string|size:12',
                        'authorized_representative_mobile_no' => 'required|string|max:10',
                        'authorized_representative_email_id' => 'nullable|email|max:255',
                        'authorized_representative_alternate_mobile_no' => 'nullable|string|max:10',
                        'authorized_representative_phone_no' => 'nullable|string|max:20',
                        'authorized_representative_gstNumber' => 'nullable|string',
                        'authorized_representative_cin_number' => 'nullable|string',

                        'proposal_for' => 'required|in:New Unit,Existing Unit',
                        'proposed_date_of_commissioning' => 'required|date',
                    ],
                    [
                        'constitution_of_enterprise.required' => 'Constitution of enterprise is required.',
                        'constitution_of_enterprise.string' => 'Constitution must be a valid string.',
                        'constitution_of_enterprise.max' => 'Constitution cannot exceed 255 characters.',

                        'enterprise_name.required' => 'Enterprise name is required.',
                        'enterprise_name.string' => 'Enterprise name must be a valid string.',
                        'enterprise_name.max' => 'Enterprise name cannot exceed 255 characters.',

                        'business_pan_no.required' => 'Business PAN number is required.',
                        'business_pan_no.string' => 'PAN number must be a valid string.',
                        'business_pan_no.max' => 'PAN number cannot exceed 10 characters.',

                        'enterprise_address.required' => 'Enterprise address is required.',
                        'enterprise_address.string' => 'Enterprise address must be a valid string.',
                        'enterprise_address.max' => 'Enterprise address cannot exceed 255 characters.',

                        'enterprises_registered_address.required' => 'Registered address is required.',
                        'enterprises_registered_address.string' => 'Registered address must be a valid string.',
                        'enterprises_registered_address.max' => 'Registered address cannot exceed 255 characters.',

                        'habitation_area_building.string' => 'Habitation area must be a valid string.',
                        'habitation_area_building.max' => 'Habitation area cannot exceed 255 characters.',

                        'pin.required' => 'PIN is required.',
                        'pin.digits' => 'PIN must be exactly 6 digits.',

                        'post_office.required' => 'Post office is required.',
                        'post_office.string' => 'Post office must be a valid string.',
                        'post_office.max' => 'Post office cannot exceed 255 characters.',

                        'police_station.required' => 'Police station is required.',
                        'police_station.string' => 'Police station must be a valid string.',
                        'police_station.max' => 'Police station cannot exceed 255 characters.',

                        'authorized_representative_name.required' => 'Representative name is required.',
                        'authorized_representative_name.string' => 'Representative name must be a valid string.',
                        'authorized_representative_name.max' => 'Representative name cannot exceed 255 characters.',

                        'authorized_representative_designation.required' => 'Designation is required.',
                        'authorized_representative_designation.string' => 'Designation must be a valid string.',
                        'authorized_representative_designation.max' => 'Designation cannot exceed 255 characters.',

                        'authorized_representative_aadhar_no.required' => 'Aadhar number is required.',
                        'authorized_representative_aadhar_no.string' => 'Aadhar must be a valid string.',
                        'authorized_representative_aadhar_no.size' => 'Aadhar must be exactly 12 digits.',

                        'authorized_representative_mobile_no.required' => 'Mobile number is required.',
                        'authorized_representative_mobile_no.string' => 'Mobile number must be a valid string.',
                        'authorized_representative_mobile_no.max' => 'Mobile number cannot exceed 10 characters.',

                        'authorized_representative_email_id.email' => 'Email must be a valid email address.',
                        'authorized_representative_email_id.max' => 'Email cannot exceed 255 characters.',

                        'authorized_representative_alternate_mobile_no.string' => 'Alternate mobile must be a valid string.',
                        'authorized_representative_alternate_mobile_no.max' => 'Alternate mobile cannot exceed 10 characters.',

                        'authorized_representative_phone_no.string' => 'Phone number must be a valid string.',
                        'authorized_representative_phone_no.max' => 'Phone number cannot exceed 20 characters.',

                        'proposal_for.required' => 'Proposal type is required.',
                        'proposal_for.in' => 'Proposal must be either "New Unit" or "Existing Unit".',

                        'proposed_date_of_commissioning.required' => 'Proposed date is required.',
                        'proposed_date_of_commissioning.date' => 'Proposed date must be a valid date.',
                    ]
                );
            }

            DB::beginTransaction();

            $user = Auth::user();;

            if (!$user) {


                return response()->json([
                    "status" => 0,
                    "message" => "Unauthorized. User not authenticated.",
                ], 401);
            }

            // Check if an enterprise detail already exists for this user
            $enterprise = EnterpriseDetail::where('user_id', $user->id)->first();

            if ($enterprise) {


                $enterprise->update([
                    'constitution_of_enterprise' => $request->constitution_of_enterprise,
                    'enterprise_name' => $request->enterprise_name,
                    'business_pan_no' => $request->business_pan_no,
                    'enterprise_address' => $request->enterprise_address,
                    'enterprises_registered_address' => $request->enterprises_registered_address,
                    'habitation_area_building' => $request->habitation_area_building,
                    'pin' => $request->pin,
                    'post_office' => $request->post_office,
                    'police_station' => $request->police_station,

                    'authorized_representative_name' => $request->authorized_representative_name,
                    'authorized_representative_designation' => $request->authorized_representative_designation,
                    'authorized_representative_aadhar_no' => $request->authorized_representative_aadhar_no,
                    'authorized_representative_mobile_no' => $request->authorized_representative_mobile_no,
                    'authorized_representative_email_id' => $request->authorized_representative_email_id,
                    'authorized_representative_alternate_mobile_no' => $request->authorized_representative_alternate_mobile_no,
                    'authorized_representative_phone_no' => $request->authorized_representative_phone_no,
                    'authorized_representative_gstNumber' => $request->authorized_representative_gstNumber,
                    'authorized_representative_cin_number' => $request->authorized_representative_cin_number,

                    'proposal_for' => $request->proposal_for,
                    'proposed_date_of_commissioning' => $request->proposed_date_of_commissioning,
                ]);

                DB::commit();

                return response()->json([

                    'status' => 1,
                    'message' => 'Enterprise details updated successfully',
                    'enterprise_id' => $enterprise->id
                ], 200);
            } else {


                $new_enterprise = EnterpriseDetail::create([
                    'user_id' => $user->id,
                    'constitution_of_enterprise' => $request->constitution_of_enterprise,
                    'enterprise_name' => $request->enterprise_name,
                    'business_pan_no' => $request->business_pan_no,
                    'enterprise_address' => $request->enterprise_address,
                    'enterprises_registered_address' => $request->enterprises_registered_address,
                    'habitation_area_building' => $request->habitation_area_building,
                    'pin' => $request->pin,
                    'post_office' => $request->post_office,
                    'police_station' => $request->police_station,

                    'authorized_representative_name' => $request->authorized_representative_name,
                    'authorized_representative_designation' => $request->authorized_representative_designation,
                    'authorized_representative_aadhar_no' => $request->authorized_representative_aadhar_no,
                    'authorized_representative_mobile_no' => $request->authorized_representative_mobile_no,
                    'authorized_representative_email_id' => $request->authorized_representative_email_id,
                    'authorized_representative_alternate_mobile_no' => $request->authorized_representative_alternate_mobile_no,
                    'authorized_representative_phone_no' => $request->authorized_representative_phone_no,
                    'authorized_representative_gstNumber' => $request->authorized_representative_gstNumber,
                    'authorized_representative_cin_number' => $request->authorized_representative_cin_number,

                    'proposal_for' => $request->proposal_for,
                    'proposed_date_of_commissioning' => $request->proposed_date_of_commissioning,
                ]);

                DB::commit();

                return response()->json([

                    'status' => 1,
                    'message' => 'Enterprise details created successfully',
                    'enterprise_id' => $new_enterprise->id
                ], 201);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();
            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();
            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function show_enterprise_details()
    {

        try {


            $user = Auth::user();

            if (!$user) {

                return response()->json([
                    "status" => 0,
                    "message" => "Unauthorized. User not authenticated.",
                ], 401);
            }

            $user_id = $user->id;

            $enterprise_detail = EnterpriseDetail::where('user_id', $user_id)->first();

            if (!$enterprise_detail) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Enterprise details not found for this user.',
                ], 404);
            }

            return response()->json([


                'status' => 1,
                'message' => 'Enterprise details fetched successfully.',
                'data' => $enterprise_detail,
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch enterprise details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_user_caf_enterprise_details(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {

                return response()->json([
                    "status" => 0,
                    "message" => "Unauthorized. User not authenticated.",
                ], 401);
            }

            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);

            $enterprise_detail = EnterpriseDetail::where('user_id', $request->user_id)->first();

            if (!$enterprise_detail) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Enterprise details not found for this user.',
                ], 404);
            }

            return response()->json([


                'status' => 1,
                'message' => 'Enterprise details fetched successfully.',
                'data' => $enterprise_detail,
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch enterprise details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
