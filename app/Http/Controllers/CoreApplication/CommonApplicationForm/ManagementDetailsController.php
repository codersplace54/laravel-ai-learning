<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\ManagementDetails;
use App\Models\PartnerSharePresidentOrSecretaryDetail;
use App\Models\BoardOfDirector;
use App\Models\ChiefAdministrativeHead;

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

                'partner_details' => 'required|array',
                'partner_details.*.name' => 'required|string|max:255',
                'partner_details.*.fathers_name' => 'required|string|max:255',
                'partner_details.*.age' => 'nullable|integer',
                'partner_details.*.sex' => 'nullable|string',
                'partner_details.*.social_status' => 'nullable|string',
                'partner_details.*.profession' => 'nullable|string',
                'partner_details.*.permanent_address' => 'nullable|string',
                'partner_details.*.mobile_no' => 'required|string',
                'partner_details.*.date_of_birth' => 'required|date',
                'partner_details.*.date_of_joining' => 'nullable|date',
                'partner_details.*.id_proof_doc' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'partner_details.*.signature_image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',

                'board_of_directors' => 'nullable|array',
                'board_of_directors.*.name' => 'required|string|max:255',
                'board_of_directors.*.permanent_address' => 'nullable|string|max:255',
                'board_of_directors.*.mobile_number' => 'required|string',

                'chief_administrative_heads' => 'nullable|array',
                'chief_administrative_heads.*.name' => 'required|string|max:255',
                'chief_administrative_heads.*.permanent_address' => 'nullable|string|max:255',
                'chief_administrative_heads.*.mobile_number' => 'required|string',
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



            $management_details_array = $this->get_file_urls(
                $management_details,
                [
                    'owner_details_photo',
                    'manager_details_photo',
                    'signature_authorization_of_owner',
                    'factory_occupiers_signature',
                    'factory_managers_signature'
                ]
            );

            $partner_details_array = [];
            foreach ($request->partner_details as $index => $partner) {

                $id_proof_doc = null;
                $signature_image = null;

                if ($request->hasFile("partner_details.$index.id_proof_doc")) {
                    $file = $request->file("partner_details.$index.id_proof_doc");
                    $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                    $id_proof_doc = $file->storeAs("uploads/$user->id/partner_id_proof", $filename, 'public');
                }

                if ($request->hasFile("partner_details.$index.signature_image")) {
                    $file = $request->file("partner_details.$index.signature_image");
                    $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                    $signature_image = $file->storeAs("uploads/$user->id/partner_signature", $filename, 'public');
                }

                $partner_record = PartnerSharePresidentOrSecretaryDetail::create([
                    'user_id' => $user->id,
                    'name' => $partner['name'],
                    'fathers_name' => $partner['fathers_name'],
                    'age' => $partner['age'],
                    'sex' => $partner['sex'],
                    'social_status' => $partner['social_status'],
                    'profession' => $partner['profession'],
                    'permanent_address' => $partner['permanent_address'],
                    'mobile_no' => $partner['mobile_no'],
                    'date_of_birth' => $partner['date_of_birth'],
                    'date_of_joining' => $partner['date_of_joining'],
                    'id_proof_doc' => $id_proof_doc,
                    'signature_image' => $signature_image,
                ]);

                $partner_array = $this->get_file_urls(
                    $partner_record,
                    ['id_proof_doc', 'signature_image']
                );

                $partner_details_array[] = $partner_array;
            }

            $board_directors = [];
            if ($request->has('board_of_directors')) {
                foreach ($request->board_of_directors as $director) {
                    $director_record = BoardOfDirector::create([
                        'user_id' => $user->id,
                        'name' => $director['name'],
                        'permanent_address' => $director['permanent_address'] ?? null,
                        'mobile_number' => $director['mobile_number'],
                    ]);
                    $board_directors[] = $director_record->toArray();
                }
            }

            $chief_administrative_heads = [];
            if ($request->has('chief_administrative_heads')) {
                foreach ($request->chief_administrative_heads as $head) {
                    $head_record = ChiefAdministrativeHead::create([
                        'user_id' => $user->id,
                        'name' => $head['name'],
                        'permanent_address' => $head['permanent_address'] ?? null,
                        'mobile_number' => $director['mobile_number'],
                    ]);
                    $chief_administrative_heads[] = $head_record->toArray();
                }
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Management details saved successfully.',
                'management_details' => $management_details_array,
                'partner_details' => $partner_details_array,
                'board_of_directors' => $board_directors,
                'chief_administrative_heads' => $chief_administrative_heads,
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
            $partnerDetails = PartnerSharePresidentOrSecretaryDetail::where('user_id', $user->id)->get();
            $boardDirectors = BoardOfDirector::where('user_id', $user->id)->get();
            $chiefHeads = ChiefAdministrativeHead::where('user_id', $user->id)->get();

            if (! $management_details) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Management details not found.',
                ], 404);
            }

            $management_details_array = $this->get_file_urls(
                $management_details,
                [
                    'owner_details_photo',
                    'manager_details_photo',
                    'signature_authorization_of_owner',
                    'factory_occupiers_signature',
                    'factory_managers_signature'
                ]
            );

            $partnerDetails_array = $this->get_file_urls(
                $partnerDetails,
                ['id_proof_doc', 'signature_image']
            );

            return response()->json([
                'status' => 1,
                'management_details' =>  $management_details_array,
                'partner_details' => $partnerDetails_array,
                'board_of_directors' => $boardDirectors,
                'chief_administrative_heads' => $chiefHeads,
            ], 200);
        } catch (\Exception $e) {


            Log::error('Error fetching management details: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }


    private function get_file_urls($data, $fields)
    {
        if ($data instanceof \Illuminate\Support\Collection) {
            return $data->map(function ($item) use ($fields) {
                return $this->get_file_urls($item, $fields);
            });
        }

        $array = $data->toArray();
        foreach ($fields as $field) {
            if (!empty($array[$field])) {
                $array[$field] = asset('storage/' . $array[$field]);
            }
        }
        return $array;
    }
}
