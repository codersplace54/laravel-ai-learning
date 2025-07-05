<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\ManagementDetails;

class ManagementDetailsController extends Controller
{

    public function management_details_store_or_update(Request $request)
    {

        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $management_details = ManagementDetails::where('user_id', $user->id)->first();

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
                'owner_details_photo' => [
                    $management_details ? 'nullable' : 'required',
                    'file',
                    'mimes:jpg,jpeg,png',
                    'max:2048'
                ],

                'manager_details_name' => 'required|string|max:255',
                'manager_details_fathers_name' => 'required|string|max:255',
                'manager_details_residential_address' => 'required|string',
                'manager_details_police_station' => 'required|string',
                'manager_details_pin' => 'required|string',
                'manager_details_mobile' => 'required|string',
                'manager_details_aadhar_no' => 'required|string',
                'manager_details_dob' => 'required|date',
                'manager_details_photo' =>  [
                    $management_details ? 'nullable' : 'required',
                    'file',
                    'mimes:jpg,jpeg,png',
                    'max:2048'
                ],

                'signature_authorization_of_owner' =>  [
                    $management_details ? 'nullable' : 'required',
                    'file',
                    'mimes:jpg,jpeg,png',
                    'max:2048'
                ],

                'factory_occupiers_signature' =>  [
                    $management_details ? 'nullable' : 'required',
                    'file',
                    'mimes:jpg,jpeg,png',
                    'max:2048'
                ],

                'factory_managers_signature' =>  [
                    $management_details ? 'nullable' : 'required',
                    'file',
                    'mimes:jpg,jpeg,png',
                    'max:2048'
                ],
            ]);


            DB::beginTransaction();

            $owner_photo = null;
            $manager_photo = null;
            $signature_owner = null;
            $signature_occupier = null;
            $signature_manager = null;


            if ($request->hasFile('owner_details_photo')) {
                if ($management_details && $management_details->owner_details_photo) {
                    Storage::disk('public')->delete($management_details->owner_details_photo);
                }
                $filename = 'owner_photo.' . $request->file('owner_details_photo')->getClientOriginalExtension();
                $owner_photo = $request->file('owner_details_photo')->storeAs("uploads/$user->id/owner_details_photo", $filename, 'public');
            }

            if ($request->hasFile('manager_details_photo')) {
                if ($management_details && $management_details->manager_details_photo) {
                    Storage::disk('public')->delete($management_details->manager_details_photo);
                }
                $filename = 'manager_photo.' . $request->file('manager_details_photo')->getClientOriginalExtension();
                $manager_photo = $request->file('manager_details_photo')->storeAs("uploads/$user->id/manager_details_photo", $filename, 'public');
            }

            if ($request->hasFile('signature_authorization_of_owner')) {
                if ($management_details && $management_details->signature_authorization_of_owner) {
                    Storage::disk('public')->delete($management_details->signature_authorization_of_owner);
                }
                $filename = 'signature_owner.' . $request->file('signature_authorization_of_owner')->getClientOriginalExtension();
                $signature_owner = $request->file('signature_authorization_of_owner')->storeAs("uploads/$user->id/signature_authorization_of_owner", $filename, 'public');
            }

            if ($request->hasFile('factory_occupiers_signature')) {
                if ($management_details && $management_details->factory_occupiers_signature) {
                    Storage::disk('public')->delete($management_details->factory_occupiers_signature);
                }
                $filename = 'signature_occupier.' . $request->file('factory_occupiers_signature')->getClientOriginalExtension();
                $signature_occupier = $request->file('factory_occupiers_signature')->storeAs("uploads/$user->id/factory_occupiers_signature", $filename, 'public');
            }

            if ($request->hasFile('factory_managers_signature')) {
                if ($management_details && $management_details->factory_managers_signature) {
                    Storage::disk('public')->delete($management_details->factory_managers_signature);
                }
                $filename = 'signature_manager.' . $request->file('factory_managers_signature')->getClientOriginalExtension();
                $signature_manager = $request->file('factory_managers_signature')->storeAs("uploads/$user->id/factory_managers_signature", $filename, 'public');
            }

            if ($management_details) {

                $management_details->update([
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

                    'owner_details_photo' => $owner_photo ?? $management_details->owner_details_photo,
                    'manager_details_photo' => $manager_photo ?? $management_details->manager_details_photo,
                    'signature_authorization_of_owner' => $signature_owner ?? $management_details->signature_authorization_of_owner,
                    'factory_occupiers_signature' => $signature_occupier ?? $management_details->factory_occupiers_signature,
                    'factory_managers_signature' => $signature_manager ?? $management_details->factory_managers_signature,
                ]);
            } else {

                $management_details = ManagementDetails::create([
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

                    'owner_details_photo' => $owner_photo,
                    'manager_details_photo' => $manager_photo,
                    'signature_authorization_of_owner' => $signature_owner,
                    'factory_occupiers_signature' => $signature_occupier,
                    'factory_managers_signature' => $signature_manager,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Management details saved successfully.',
                'data' => $management_details
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

            Log::error('Error saving management details: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }

    public function management_details_view()
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthenticated.',
                ], 401);
            }


            $management_details = ManagementDetails::where('user_id', $user->id)->first();

            if (! $management_details) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Management details not found.',
                ], 404);
            }

            return response()->json([
                'status' => 0,
                'data' =>  $management_details,
            ], 200);
        } catch (\Exception $e) {


            Log::error('Error fetching management details: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }
}
