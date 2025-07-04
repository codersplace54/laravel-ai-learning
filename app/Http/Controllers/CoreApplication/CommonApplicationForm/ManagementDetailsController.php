<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ManagementDetails;

class ManagementDetailsController extends Controller
{

    public function management_details_store_or_update(Request $request)
    {

        try {


            $request->validate([
                'owner_details_name' => 'required|string|max:255',
                'owner_details_fathers_name' => 'required|string|max:255',
                'owner_details_residential_address' => 'required|string',
                'owner_details_police_station' => 'required|string',
                'owner_details_pin' => 'required|string',
                'owner_aadhar_no' => 'required|string',
                'owner_details_mobile' => 'required|string',
                'owner_details_alternate_mobile' => 'nullable|string',
                'owner_details_aadhar_no' => 'required|string',
                'owner_details_status' => 'required|in:Owner,Managing Director,CEO,Chairman,Partner,COO,CFO,Director,VP,Chief Operating Officer,Chief Financial Officer,Chief Executive Officer,Vice President,President',
                'owner_details_email' => 'required|email',
                'owner_details_dob' => 'required|date',
                'owner_details_social_status' => 'required|in:General,SC,ST,OBC',
                'owner_details_is_differently_abled' => 'required|in:YES,NO',
                'owner_details_is_women_entrepreneur' => 'required|in:YES,NO',
                'owner_details_is_minority' => 'required|in:YES,NO',
                'owner_details_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',

                'manager_details_name' => 'required|string|max:255',
                'manager_details_fathers_name' => 'required|string|max:255',
                'manager_details_residential_address' => 'required|string',
                'manager_details_police_station' => 'required|string',
                'manager_details_pin' => 'required|string',
                'manager_details_mobile' => 'required|string',
                'manager_details_aadhar_no' => 'required|string',
                'manager_details_dob' => 'required|date',
                'manager_details_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',

                'signature_authorization_of_owner' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
                'factory_occupiers_signature' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
                'factory_managers_signature' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            ]);

            DB::beginTransaction();

            $user = Auth::user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated user.'], 401);
            }


            $owner_photo = null;
            $manager_photo = null;
            $signature_owner = null;
            $signature_occupier = null;
            $signature_manager = null;

            if ($request->hasFile('owner_details_photo')) {
                $owner_photo = $request->file('owner_details_photo')->store('uploads/owner_details_photo', 'public');
            }

            if ($request->hasFile('manager_details_photo')) {
                $manager_photo = $request->file('manager_details_photo')->store('uploads/manager_details_photo', 'public');
            }

            if ($request->hasFile('signature_authorization_of_owner')) {
                $signature_owner = $request->file('signature_authorization_of_owner')->store('uploads/signature_authorization_of_owner', 'public');
            }

            if ($request->hasFile('factory_occupiers_signature')) {
                $signature_occupier = $request->file('factory_occupiers_signature')->store('uploads/factory_occupiers_signature', 'public');
            }

            if ($request->hasFile('factory_managers_signature')) {
                $signature_manager = $request->file('factory_managers_signature')->store('uploads/factory_managers_signature', 'public');
            }

            $management_details = ManagementDetails::where('user_id', $user->id)->first();

            $data = [
                'user_id' => $user->id,
                'owner_details_name' => $request->owner_details_name,
                'owner_details_fathers_name' => $request->owner_details_fathers_name,
                'owner_details_residential_address' => $request->owner_details_residential_address,
                'owner_details_police_station' => $request->owner_details_police_station,
                'owner_details_pin' => $request->owner_details_pin,
                'owner_aadhar_no' => $request->owner_aadhar_no,
                'owner_details_mobile' => $request->owner_details_mobile,
                'owner_details_alternate_mobile' => $request->owner_details_alternate_mobile,
                'owner_details_aadhar_no' => $request->owner_details_aadhar_no,
                'owner_details_status' => $request->owner_details_status,
                'owner_details_email' => $request->owner_details_email,
                'owner_details_dob' => $request->owner_details_dob,
                'owner_details_social_status' => $request->owner_details_social_status,
                'owner_details_is_differently_abled' => $request->owner_details_is_differently_abled,
                'owner_details_is_women_entrepreneur' => $request->owner_details_is_women_entrepreneur,
                'owner_details_is_minority' => $request->owner_details_is_minority,

                'manager_details_name' => $request->manager_details_name,
                'manager_details_fathers_name' => $request->manager_details_fathers_name,
                'manager_details_residential_address' => $request->manager_details_residential_address,
                'manager_details_police_station' => $request->manager_details_police_station,
                'manager_details_pin' => $request->manager_details_pin,
                'manager_details_mobile' => $request->manager_details_mobile,
                'manager_details_aadhar_no' => $request->manager_details_aadhar_no,
                'manager_details_dob' => $request->manager_details_dob,
            ];


            if ($owner_photo) $data['owner_details_photo'] = $owner_photo;
            if ($manager_photo) $data['manager_details_photo'] = $manager_photo;
            if ($signature_owner) $data['signature_authorization_of_owner'] = $signature_owner;
            if ($signature_occupier) $data['factory_occupiers_signature'] = $signature_occupier;
            if ($signature_manager) $data['factory_managers_signature'] = $signature_manager;

            if ($management_details) {
                $management_details->update($data);
            } else {
                $management_details = ManagementDetails::create($data);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Management details saved successfully.',
                'data' => $management_details
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

            Log::error('Error saving management details: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while saving management details.',
            ], 500);
        }
    }

    public function management_details_view(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }


            $managementDetails = ManagementDetails::where('user_id', $user->id)->first();

            if (!$managementDetails) {
                return response()->json([
                    'success' => false,
                    'message' => 'Management details not found.',
                ], 404);
            }

            foreach (
                [
                    'owner_details_photo',
                    'manager_details_photo',
                    'signature_authorization_of_owner',
                    'factory_occupiers_signature',
                    'factory_managers_signature',
                ] as $field
            ) {
                if ($managementDetails->{$field}) {
                    $managementDetails->{$field} = asset('storage/' . $managementDetails->{$field});
                }
            }


            return response()->json([
                'success' => true,
                'data' => $managementDetails,
            ], 200);
        } catch (\Exception $e) {


            Log::error('Error fetching management details: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }
}
