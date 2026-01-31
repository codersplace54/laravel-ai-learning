<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\AclRule;
use Exception;
use Carbon\Carbon;
use App\Models\DepartmentUser;
use App\Models\Otp;
use App\Services\SmsService;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\DepartmentUsersExport;
use App\Models\UserUnit;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Validation\Rule;
use App\Traits\LogsActivity;

class UserController extends Controller
{
    use LogsActivity;

    public function register(Request $request)
    {

        try {


            $request->validate(
                [
                    'name_of_enterprise' => 'nullable|string|max:255',
                    'authorized_person_name' => 'required|string|max:255',
                    'email_id' => 'required|email|unique:users,email_id',
                    'mobile_no' => 'required|string|max:15|unique:users,mobile_no',
                    'whatsapp_no' => 'required|string|max:15',
                    'user_name' => 'required|string|max:100|unique:users,user_name',
                    'district_id' => 'nullable|integer|exists:tripura_master_data,district_code',
                    'subdivision_id' => 'nullable|integer|exists:tripura_master_data,sub_lgd_code',
                    'ulb_id' => 'nullable|integer|exists:tripura_master_data,ulb_lgd_code',
                    'ward_id' => 'nullable|integer|exists:tripura_master_data,gp_vc_ward_lgd_code',
                    'registered_enterprise_address' => 'nullable|string',
                    'registered_enterprise_city' => 'nullable|string',
                    'user_type' => 'required|string|in:individual,department,admin',
                    'password' => 'required|string|min:6',
                    'pan' => 'required_if:user_type,individual|unique:users,pan|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',

                    'department_id'   => 'required_if:user_type,department|integer|exists:departments,id',
                    'hierarchy_level' => 'required_if:user_type,department|in:block,subdivision1,subdivision2,subdivision3,district1,district2,district3,state1,state2,state3',
                    'designation'      => 'nullable|string',
                    'is_active'      => 'nullable|integer',
                    'inspector'      => 'nullable|string|in:yes,no',
                    'dob' => 'nullable|date_format:d/m/Y',
                    'pan_token' => 'required_if:user_type,individual|string',

                    'locations' => 'nullable|array|min:1',
                    'locations.*.district_id' => 'nullable|integer|exists:tripura_master_data,district_code',
                    'locations.*.subdivision_id' => 'nullable|integer|exists:tripura_master_data,sub_lgd_code',
                    'locations.*.block_id' => 'nullable|integer',
                ],
                [
                    'name_of_enterprise.required' => 'Enterprise name is required.',
                    'authorized_person_name.required' => 'Authorized person name is required.',
                    'email_id.required' => 'Email is required.',
                    'email_id.email' => 'Please enter a valid email address.',
                    'email_id.unique' => 'This email is already registered.',
                    'mobile_no.required' => 'Mobile number is required.',
                    'whatsapp_no.required' => 'WhatsApp number is required.',
                    'user_name.required' => 'Username is required.',
                    'user_name.unique' => 'This username is already taken.',
                    'district_id.required'   => 'District is required.',
                    'district_id.exists'     => 'Selected district is invalid.',
                    'subdivision_id.required' => 'Subdivision is required.',
                    'subdivision_id.exists'  => 'Selected subdivision is invalid.',
                    'ulb_id.required'        => 'ULB is required.',
                    'ulb_id.exists'          => 'Selected ULB is invalid.',
                    'ward_id.required'       => 'Ward is required.',
                    'ward_id.exists'         => 'Selected ward is invalid.',
                    'registered_enterprise_address.required' => 'Enterprise address is required.',
                    'registered_enterprise_city.required' => 'Enterprise city is required.',
                    'user_type.required' => 'User type is required.',
                    'user_type.in' => 'User type must be either individual,department or admin.',
                    'password.required' => 'Password is required.',
                    'password.min' => 'Password must be at least 6 characters.',
                    'pan.regex' => 'The PAN number must be in valid format (e.g., ABCDE1234F).',
                    'pan.required_if' => 'PAN is required.',
                    'pan.unique'      => 'This PAN number is already registered.',
                    'department_id.required' => 'The department field is required.',
                    'department_id.integer'  => 'The department must be a valid number.',
                    'department_id.exists'   => 'The selected department does not exist in our records.',
                    'hierarchy_level.required' => 'The hierarchy level is required.',
                    'hierarchy_level.in'       => 'The hierarchy level must be one of: block, subdivision1, subdivision2, subdivision3, district1, district2, district3, state1, state2, or state3.',
                    'is_active.integer'        => 'The status must be a valid number (0 or 1).',
                    'dob.date_format' => 'DOB must be in DD/MM/YYYY format.',
                    'pan_token.required_if' => 'PAN verification is required before registration.',
                ]
            );

            if ($request->user_type === 'individual') {

                $user_otp_exist = Otp::where('mobile_no', $request->mobile_no)->first();
                if (!$user_otp_exist || $user_otp_exist->is_verified == 0) {
                    return response()->json(['status' => 0, 'message' => 'Please verify your mobile number with OTP before registering.'], 422);
                }

                try {
                    $pan_data = decrypt($request->pan_token);
                } catch (DecryptException $e) {
                    return response()->json(['status' => 0, 'message' => 'PAN verification token is invalid or expired.'], 422);
                }

                if (
                    empty($pan_data['verified']) ||
                    $pan_data['verified'] !== true ||
                    now()->timestamp - ($pan_data['issued_at'] ?? 0) > 900
                ) {
                    return response()->json(['status' => 0, 'message' => 'PAN verification expired or invalid.'], 422);
                }

                if (strtoupper($request->pan) !== strtoupper($pan_data['pan'] ?? '')) {
                    return response()->json(['status' => 0, 'message' => 'PAN does not match verified PAN.'], 422);
                }
            }

            DB::beginTransaction();

            if ($request->user_type == "department") {
                $admin = Auth::user();
                if (!$admin || $admin->user_type !== 'admin') {
                    return response()->json(['status' => 0, 'message' => 'Only a logged-in admin can register a departmental user.'], 401);
                }
            }

            $user = User::create([
                'name_of_enterprise' => $request->name_of_enterprise,
                'authorized_person_name' => $request->authorized_person_name,
                'email_id' => $request->email_id,
                'mobile_no' => $request->mobile_no,
                'whatsapp_no' => $request->whatsapp_no,
                'user_name' => $request->user_name,
                'district_id' => $request->user_type === 'individual' ? $request->district_id : null,
                'subdivision_id' => $request->user_type === 'individual' ? $request->subdivision_id : null,
                'ulb_id' => $request->user_type === 'individual' ? $request->ulb_id : null,
                'ward_id' => $request->user_type === 'individual' ? $request->ward_id : null,
                'registered_enterprise_address' => $request->registered_enterprise_address,
                'registered_enterprise_city' => $request->registered_enterprise_city,
                'user_type' => $request->user_type,
                'pan' => strtoupper(trim($request->pan)),
                'password' => Hash::make($request->password),
                'status' => 'active',
                'is_mobile_verified' => $request->user_type === 'individual' ? 1 : 0,
                'dob' => $request->dob ? Carbon::createFromFormat('d/m/Y', $request->dob)->format('Y-m-d') : null,
                'is_pan_verified' => $request->user_type === 'individual' ? 1 : 0,
            ]);

            if ($user->user_type == "department") {

                $locations = $request->locations ?? [null];
                foreach ($locations as $location) {

                    DepartmentUser::create([
                        'user_id' => $user->id,
                        'department_id' => $request->department_id,
                        'designation' => $request->designation,
                        'block_id' => $location['block_id'] ?? null,
                        'subdivision_id' => $location['subdivision_id'] ?? null,
                        'district_id' => $location['district_id'] ?? null,
                        'hierarchy_level' => $request->hierarchy_level,
                        'is_active' => 1,
                        'inspector' => $request->inspector ?? 'no',
                        'created_by' => $admin->email_id,
                        'updated_by' => null
                    ]);
                }

                // Log department user creation
                // activity('admin_user_management')
                //     ->performedOn($user)
                //     ->causedBy($admin)
                //     ->withProperties([
                //         'action' => 'department_user_created',
                //         'department_id' => $request->department_id,
                //         'hierarchy_level' => $request->hierarchy_level,
                //         'designation' => $request->designation
                //     ])
                //     ->log('Admin created department user');
            }

            $now = Carbon::now();
            $date = $now->format('y');
            $month = $now->format('m');
            $userIdPadded = str_pad($user->id, 7, '0', STR_PAD_LEFT);
            $bin = "";
            if ($request->user_type == "individual") {
                $bin = "TR{$date}{$month}{$userIdPadded}";
                $user->bin = $bin;
            }
            $user->save();

            if ($user->user_type === 'individual') {
                UserUnit::create([
                    'user_id'       => $user->id,
                    'unit_name'     => $user->name_of_enterprise,
                    'district_id'   => $user->district_id,
                    'subdivision_id' => $user->subdivision_id,
                    'ulb_id'        => $user->ulb_id,
                    'ward_id'       => $user->ward_id,
                    'status'        => 'active',
                ]);
            }

            AclRule::create([
                'user_id' => $user->id,
                'department_id' => null,
                'service_id' => null,
                'role_id' => 3,
                'role_code' => "Industrialist",
                'district' => null,
                'sub_division' => null,
                'ulb' => null,
                'gp_vc_mc' => null,
            ]);


            DB::commit();

            if ($request->user_type === 'individual') {
                session()->forget('verified_mobile_no');
            }

            return response()->json([
                'status' => 1,
                'message' => 'User registered successfully',
                'data' => $user
            ], 200);
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
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function update_profile(Request $request)
    {
        try {

            $user = User::findOrFail($request->id);

            $rules = [
                'id' => 'required|exists:users,id',
                'email_id' => 'required|email|unique:users,email_id,' . $request->id,
                'mobile_no' => 'required|string|max:15',
                'whatsapp_no' => 'nullable|string|max:15',
                'otp_code' => 'required_if:user_type,individual|string|size:6',
            ];

            $current_pan = $user->pan;
            $incoming_pan = strtoupper(trim($request->pan));

            $pan_rules = ['required_if:user_type,individual', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'];

            if ($request->has('pan') && $incoming_pan !== $current_pan) {
                $pan_rules[] = Rule::unique('users', 'pan')->ignore($user->id);
            }

            $rules['pan'] = $pan_rules;

            if ($request->name_of_enterprise !== null) {
                $rules['name_of_enterprise'] = 'nullable|string|max:255';
            }
            if ($request->registered_enterprise_address !== null) {
                $rules['registered_enterprise_address'] = 'nullable|string';
            }
            if ($request->registered_enterprise_city !== null) {
                $rules['registered_enterprise_city'] = 'nullable|string';
            }
            if ($request->user_type !== null) {
                $rules['user_type'] = 'required|in:individual,department,admin';
            }
            if ($request->password !== null) {
                $rules['password'] = 'nullable|string|min:6';
            }
            if ($request->district_id !== null) {
                $rules['district_id'] = 'nullable|integer|exists:tripura_master_data,district_code';
            }
            if ($request->subdivision_id !== null) {
                $rules['subdivision_id'] = 'nullable|integer|exists:tripura_master_data,sub_lgd_code';
            }
            if ($request->ulb_id !== null) {
                $rules['ulb_id'] = 'nullable|integer|exists:tripura_master_data,ulb_lgd_code';
            }
            if ($request->hierarchy_level !== null) {
                $rules['hierarchy_level'] = 'nullable|in:block,subdivision1,subdivision2,subdivision3,district1,district2,district3,state1,state2,state3';
            }
            if ($request->department_id !== null) {
                $rules['department_id'] = 'nullable|integer|exists:departments,id';
            }
            if ($request->designation !== null) {
                $rules['designation'] = 'nullable|string';
            }
            if ($request->inspector !== null) {
                $rules['inspector'] = 'nullable|string|in:yes,no';
            }

            if ($request->locations !== null) {
                $rules['locations'] = 'array|min:1';

                $rules['locations.*.district_id'] = 'nullable|integer|exists:tripura_master_data,district_code';
                $rules['locations.*.subdivision_id'] = 'nullable|integer|exists:tripura_master_data,sub_lgd_code';
                $rules['locations.*.block_id'] = 'nullable|integer';
            }

            if ($request->dob !== null) {
                $rules['dob'] = 'nullable|date_format:d/m/Y';
            }


            $request->validate($rules, [
                'id.required' => 'User ID is required.',
                'id.exists' => 'No user is assigned to this ID.',
                'name_of_enterprise.required' => 'Enterprise name is required.',
                'authorized_person_name.required' => 'Authorized person name is required.',
                'email_id.required' => 'Email is required.',
                'email_id.email' => 'Enter a valid email address.',
                'email_id.unique' => 'This email is already registered.',
                'mobile_no.required' => 'Mobile number is required.',
                'whatsapp_no.required' => 'WhatsApp number is required.',
                'user_name.required' => 'Username is required.',
                'otp_code.required' => 'OTP code is required.',
                'otp_code.size' => 'OTP code must be exactly 6 digits.',
                'user_name.unique' => 'This username is already taken.',
                'user_name.alpha_dash' => 'Username can only contain letters, numbers, dashes, and underscores.',
                'registered_enterprise_address.required' => 'Enterprise address is required.',
                'registered_enterprise_city.required' => 'Enterprise city is required.',
                'user_type.required' => 'User type is required.',
                'user_type.in' => 'User type must be individual,department or admin.',
                'password.min' => 'Password must be at least 6 characters long.',
                'district_id.exists'  => 'Selected district is invalid.',
                'subdivision_id.exists' => 'Selected subdivision is invalid.',
                'ulb_id.exists'  => 'Selected ULB is invalid.',
                'department_id.integer'  => 'The department must be a valid number.',
                'department_id.exists'   => 'The selected department does not exist in our records.',
                'hierarchy_level.in' => 'The hierarchy level must be one of: block, subdivision1, subdivision2, subdivision3, district1, district2, district3, state1, state2, or state3.',
                'pan.required_if' => 'PAN is required.',
                'pan.regex' => 'The PAN number must be in valid format (e.g., ABCDE1234F).',
                'pan.unique' => 'This PAN number is already registered.',
                'dob.date_format' => 'DOB must be in DD/MM/YYYY format.',
            ]);

            DB::beginTransaction();

            if ($user->user_type === "individual") {

                $otp = Otp::where('mobile_no', $user->mobile_no)
                    ->where('code', $request->otp_code)
                    ->first();

                if (!$otp) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 0,
                        'message' => 'Invalid OTP. Please enter the correct OTP and try again.',
                    ], 422);
                }

                if ($otp->is_verified !== 1) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 0,
                        'message' => 'Please verify OTP first.',
                    ], 422);
                }

                $otp->delete();
            }



            $update_data = [];

            if ($request->name_of_enterprise !== null) {
                $update_data['name_of_enterprise'] = $request->name_of_enterprise;
            }
            if ($request->authorized_person_name !== null) {
                $update_data['authorized_person_name'] = $request->authorized_person_name;
            }
            if ($request->email_id !== null) {
                $update_data['email_id'] = $request->email_id;
            }
            if ($request->mobile_no !== null) {
                $update_data['mobile_no'] = $request->mobile_no;
            }
            if ($request->whatsapp_no !== null) {
                $update_data['whatsapp_no'] = $request->whatsapp_no;
            }
            if ($request->user_name !== null) {
                $update_data['user_name'] = $request->user_name;
            }
            if ($request->registered_enterprise_address !== null) {
                $update_data['registered_enterprise_address'] = $request->registered_enterprise_address;
            }
            if ($request->registered_enterprise_city !== null) {
                $update_data['registered_enterprise_city'] = $request->registered_enterprise_city;
            }
            if ($request->user_type !== null) {
                $update_data['user_type'] = $request->user_type;
            }
            if ($request->district_id !== null) {
                $update_data['district_id'] = $request->district_id;
            }
            if ($request->subdivision_id !== null) {
                $update_data['subdivision_id'] = $request->subdivision_id;
            }
            if ($request->ulb_id !== null) {
                $update_data['ulb_id'] = $request->ulb_id;
            }
            if ($request->ward_id !== null) {
                $update_data['ward_id'] = $request->ward_id;
            }
            if ($request->pan !== null) {
                $update_data['pan'] = $request->pan;
            }
            if ($request->password !== null) {
                $update_data['password'] = Hash::make($request->password);
            }

            if ($request->dob !== null) {
                $update_data['dob'] = Carbon::createFromFormat('d/m/Y', $request->dob)->format('Y-m-d');
            }

            $user->update($update_data);

            if ($user->user_type === 'individual') {
                $default_unit = UserUnit::where('user_id', $user->id)->first();
                if ($default_unit) {
                    $default_unit->update([
                        'unit_name'      => $request->name_of_enterprise ?? $default_unit->unit_name,
                        'district_id'    => $request->district_id ?? $default_unit->district_id,
                        'subdivision_id' => $request->subdivision_id ?? $default_unit->subdivision_id,
                        'ulb_id'         => $request->ulb_id ?? $default_unit->ulb_id,
                        'ward_id'        => $request->ward_id ?? $default_unit->ward_id,
                        'status'         => 'active',
                    ]);
                } else {
                    UserUnit::create([
                        'user_id'        => $user->id,
                        'unit_name'      => $user->name_of_enterprise,
                        'district_id'    => $user->district_id,
                        'subdivision_id' => $user->subdivision_id,
                        'ulb_id'         => $user->ulb_id,
                        'ward_id'        => $user->ward_id,
                        'status'         => 'active',
                    ]);
                }
            }

            if ($user->user_type == "department") {

                $auth_user = Auth::user();
                $hierarchy_level = $auth_user->department_user->hierarchy_level ?? null;

                if (!in_array($hierarchy_level, ['state1', 'state2', 'state3']) && $auth_user->user_type != 'admin') {
                    return response()->json([
                        'status' => 0,
                        'message' => 'You are not authorized to update department user locations.',
                    ], 403);
                }

                DepartmentUser::where('user_id', $user->id)->delete();
                $locations = $request->locations ?? [null];

                foreach ($locations as $location) {
                    DepartmentUser::create([
                        'user_id' => $user->id,
                        'department_id' => $request->department_id,
                        'designation' => $request->designation,
                        'block_id' => $location['block_id'] ?? null,
                        'subdivision_id' => $location['subdivision_id'] ?? null,
                        'district_id' => $location['district_id'] ?? null,
                        'hierarchy_level' => $request->hierarchy_level,
                        'is_active' => 1,
                        'created_by' =>  $auth_user->email_id,
                        'updated_by' =>  $auth_user->email_id,
                        'inspector' =>  $request->inspector ?? "no"
                    ]);
                }

                // Log department user profile update
                // activity('admin_user_management')
                //     ->performedOn($user)
                //     ->causedBy($auth_user)
                //     ->withProperties([
                //         'action' => 'department_user_updated',
                //         'department_id' => $request->department_id,
                //         'hierarchy_level' => $request->hierarchy_level,
                //         'designation' => $request->designation
                //     ])
                //     ->log('Admin updated department user profile');
            }

            $locations = $user->department_user_location->map(function ($loc) {
                return [
                    'district_id'    => $loc->district_id,
                    'district_name'    => $loc->district->district_name ?? null,
                    'subdivision_id' => $loc->subdivision_id,
                    'subdivision_name' => $loc->subdivision->sub_division ?? null,
                    'block_id'       => $loc->block_id,
                    'block_name'       => $loc->ulb->ulb_name ?? null,
                ];
            });

            $user = [
                'name_of_enterprise' => $user->name_of_enterprise,
                'authorized_person_name' => $user->authorized_person_name,
                'email_id' => $user->email_id,
                'mobile_no' => $user->mobile_no,
                'whatsapp_no' => $user->whatsapp_no,
                'pan' => $user->pan,
                'bin' => $user->bin,
                'district'                     => $user->district->district_name ?? null,
                'district_code'                => $user->district->district_code ?? null,
                'subdivision_name'                 => $user->subdivision->sub_division ?? null,
                'subdivision_code'               => $user->subdivision->sub_lgd_code ?? null,
                'ulb_name'                          => $user->ulb->ulb_name ?? null,
                'ulb_code'                 => $user->ulb->ulb_lgd_code ?? null,
                'ward_name'                         => $user->ward->name_of_gp_vc_or_ward ?? null,
                'ward_code'                      => $user->ward->gp_vc_ward_lgd_code ?? null,
                'user_type' => $user->user_type,
                'registered_enterprise_address' => $user->registered_enterprise_address,
                'registered_enterprise_city' => $user->registered_enterprise_city,
                'is_active'                    => $user->department_user->is_active ?? null,
                'department_id'   => $user->department_user->department_id   ?? null,
                'hierarchy_level' => $user->department_user->hierarchy_level ?? null,
                'designation'     => $user->department_user->designation     ?? null,
                'created_by'     => $user->department_user->created_by    ?? null,
                'updated_by'     => $user->department_user->updated_by ?? null,
                'inspector'     => $user->department_user->inspector ?? null,


            ];

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'User updated successfully',
                'data' => $user,
                'locations' => $locations,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to update user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function delete_profile(Request $request)
    {
        try {


            $request->validate(
                [
                    'id' => 'required|exists:users,id',
                ],
                [
                    'id.required' => 'User ID is required.',
                    'id.exists' => 'No user is assigned to this ID.',
                ]
            );


            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthorized: Token is missing or invalid.',
                ], 401);
            }

            if ($user->id == $request->id) {
                return response()->json([
                    'status' => 0,
                    'message' => 'You cannot delete your own account.'
                ], 403);
            }

            DB::beginTransaction();

            $userToDelete = User::findOrFail($request->id);
            $userToDelete->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'User deleted successfully'
            ], 200);
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
                'message' => 'Failed to delete user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function get_profile(Request $request)
    {
        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 401);
            }

            $locations = $user->department_user_location->map(function ($loc) {
                return [
                    'district_id'    => $loc->district_id,
                    'district_name'    => $loc->district->district_name ?? null,
                    'subdivision_id' => $loc->subdivision_id,
                    'subdivision_name' => $loc->subdivision->sub_division ?? null,
                    'block_id'       => $loc->block_id,
                    'block_name'       => $loc->ulb->ulb_name ?? null,
                ];
            });

            $data = [
                'name_of_enterprise' => $user->name_of_enterprise,
                'authorized_person_name' => $user->authorized_person_name,
                'email_id' => $user->email_id,
                'mobile_no' => $user->mobile_no,
                'whatsapp_no' => $user->whatsapp_no,
                'pan' => $user->pan,
                'bin' => $user->bin,
                'district'                     => $user->district->district_name ?? null,
                'district_code'                => $user->district->district_code ?? null,
                'subdivision_name'                 => $user->subdivision->sub_division ?? null,
                'subdivision_code'               => $user->subdivision->sub_lgd_code ?? null,
                'ulb_name'                          => $user->ulb->ulb_name ?? null,
                'ulb_code'                 => $user->ulb->ulb_lgd_code ?? null,
                'ward_name'                         => $user->ward->name_of_gp_vc_or_ward ?? null,
                'ward_code'                      => $user->ward->gp_vc_ward_lgd_code ?? null,
                'user_type' => $user->user_type,
                'registered_enterprise_address' => $user->registered_enterprise_address ?? null,
                'registered_enterprise_city' => $user->registered_enterprise_city ?? null,
                'is_active'                    => $user->is_active,
                'department_id'   => $user->department_user->department_id   ?? null,
                'hierarchy_level' => $user->department_user->hierarchy_level ?? null,
                'designation'     => $user->department_user->designation     ?? null,
                'inspector'     => $user->department_user->inspector         ?? null,
                'is_pan_verified' => (bool) $user->is_pan_verified,
                'dob' => $user->dob

            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'locations' => $locations
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_department_users(Request $request)
    {


        try {

            $department = Auth::user();
            if (!$department) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $department = User::where('id', $department->id)
                ->where('user_type', 'department')
                ->first();

            if (!$department) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or not an department user.'
                ], 404);
            }

            $logged_department_id = $department->department_user->department_id;

            $query = User::where('user_type', 'department')
                ->with([
                    'department_user.department',
                    'department_user_location.district',
                    'department_user_location.subdivision',
                    'department_user_location.ulb',
                    'department_user.department'
                ])
                ->whereHas('department_user', function ($q) use ($logged_department_id) {
                    $q->where('department_id', $logged_department_id);
                });

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('authorized_person_name', 'like', "%$search%")
                        ->orWhere('email_id', 'like', "%$search%")
                        ->orWhere('mobile_no', 'like', "%$search%");
                });
            }

            if ($request->filled('department_id')) {
                $department_id = $request->department_id;

                $query->whereHas('department_user', function ($q) use ($department_id) {
                    $q->where('department_id', $department_id);
                });
            }

            if ($request->export == 'excel') {

                $users = $query->get();

                $filename = 'department_users_' . time() . '.xlsx';

                return Excel::download(new DepartmentUsersExport($users), $filename);
            }

            $per_page = $request->get('per_page', 10);

            $department_users = $query->paginate($per_page)
                ->through(function ($user) {

                    $locations = $user->department_user_location->map(function ($loc) {
                        return [
                            'district_name'    => $loc->district->district_name ?? null,
                            'subdivision_name' => $loc->subdivision->sub_division ?? null,
                            'block_name'       => $loc->ulb->ulb_name ?? null,
                        ];
                    });

                    $districts_name = $locations->pluck('district_name')->filter()->unique()->implode(', ');
                    $subdivisions_name = $locations->pluck('subdivision_name')->filter()->unique()->implode(', ');
                    $blocks_name = $locations->pluck('block_name')->filter()->unique()->implode(', ');
                    return [
                        'id' => $user->id,
                        'name_of_enterprise' => $user->name_of_enterprise,
                        'authorized_person_name' => $user->authorized_person_name,
                        'email_id' => $user->email_id,
                        'mobile_no'  => $user->mobile_no,
                        'whatsapp_no'  => $user->whatsapp_no,
                        'user_name'  => $user->user_name,
                        'districts_name'    => $districts_name,
                        'subdivisions_name' => $subdivisions_name,
                        'blocks_name'       => $blocks_name,
                        'department_name' => $user->department_user->department->name ?? null,
                        'department_id' => $user->department_user->department_id ?? null,
                        'registered_enterprise_address' => $user->registered_enterprise_address,
                        'registered_enterprise_city' => $user->registered_enterprise_city,
                        'hierarchy_level' => $user->department_user->hierarchy_level ?? null,
                        'user_type' => $user->user_type,
                        'status' => $user->status,
                        'created_at'  => $user->created_at,
                        'updated_at'  => $user->updated_at,
                        'inspector'     => $user->department_user->inspector         ?? null,
                        'created_by'  => $user->department_user->created_by ?? null,
                        'updated_by'  => $user->department_user->updated_by ?? null,
                    ];
                });

            return response()->json([
                'status' => 1,
                'data' => $department_users->items(),
                'pagination' => [
                    'current_page' => $department_users->currentPage(),
                    'row_count'    => $department_users->count(),
                    'total'        => $department_users->total(),
                    'start_row'    => $department_users->firstItem(),
                    'end_row'      => $department_users->lastItem(),
                    'last_page'    => $department_users->lastPage(),
                    'next_page_url' => $department_users->nextPageUrl(),
                    'prev_page_url' => $department_users->previousPageUrl(),
                ],
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_department_user_details(Request $request)
    {
        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 401);
            }

            $request->validate(
                [
                    'id' => 'required|exists:users,id',
                ]
            );

            $department_user = User::where('id', $request->id)->first();

            $locations = $department_user->department_user_location->map(function ($loc) {
                return [
                    'district_id'    => $loc->district_id,
                    'district_name'    => $loc->district->district_name ?? null,
                    'subdivision_id' => $loc->subdivision_id,
                    'subdivision_name' => $loc->subdivision->sub_division ?? null,
                    'block_id'       => $loc->block_id,
                    'block_name'       => $loc->ulb->ulb_name ?? null,
                ];
            });

            $data = [
                'name_of_enterprise' => $department_user->name_of_enterprise,
                'authorized_person_name' => $department_user->authorized_person_name,
                'email_id' => $department_user->email_id,
                'mobile_no' => $department_user->mobile_no,
                'user_name' => $department_user->user_name,
                'user_type' => $department_user->user_type,
                'registered_enterprise_address' => $department_user->registered_enterprise_address,
                'registered_enterprise_city' => $department_user->registered_enterprise_city,
                'is_active'                    => $department_user->department_user->is_active,
                'department_id'   => $department_user->department_user->department_id   ?? null,
                'hierarchy_level' => $department_user->department_user->hierarchy_level ?? null,
                'designation'     => $department_user->department_user->designation     ?? null,
                'inspector'     => $user->department_user->inspector         ?? null,


            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'locations' =>  $locations
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function send_profile_update_otp(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthorized.'
                ], 401);
            }

            $mobile_no = $request->mobile_no ?? $user->mobile_no;

            $sms_mobile_no = $mobile_no;
            if (str_contains($mobile_no, 'eeee')) {
                $sms_mobile_no = '8730891796';
            } elseif (str_contains($mobile_no, 'eee')) {
                $sms_mobile_no = '7005367884';
            }

            if (app()->environment('production')) {
                $otp_code = random_int(100000, 999999);
            } else {
                $otp_code = 123456;
            }

            DB::beginTransaction();

            $user_otp_exist = Otp::where('mobile_no', $sms_mobile_no)->first();

            if ($user_otp_exist) {
                $user_otp_exist->update([
                    'code' => $otp_code,
                    'is_verified' => 0,
                ]);
            } else {
                Otp::create([
                    'mobile_no' => $user->mobile_no,
                    'code' => $otp_code,
                    'is_verified' => 0,
                ]);
            }

            $sms = SmsService::buildSmsMessage('action_verification_otp', [
                'OTP_CODE' => $otp_code,
            ]);

            $sms_result = SmsService::send(
                $sms_mobile_no,
                $sms['message'],
                $sms['template_id']
            );

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'OTP sent successfully for profile update.',
                'data' => [
                    'mobile_no' => $sms_mobile_no,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 0,
                'message' => 'Failed to send OTP.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function verify_profile_update_otp(Request $request)
    {
        try {
            $request->validate([
                'otp_code' => 'required|string|size:6',
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthorized.'
                ], 401);
            }

            $mobile_no = $user->mobile_no;
            $otp_code = $request->otp_code;
            $now = Carbon::now();

            DB::beginTransaction();

            $user_otp = Otp::where('mobile_no', $mobile_no)
                ->where('code', $otp_code)
                ->first();

            if (!$user_otp) {
                DB::rollBack();
                return response()->json([
                    'status' => 0,
                    'message' => 'Invalid OTP. Please enter the correct OTP and try again.',
                ], 422);
            }



            $user_otp->update(['is_verified' => 1]);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'OTP verified successfully for profile update.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 0,
                'message' => 'Failed to verify OTP.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function user_unit_store(Request $request)
    {

        try {

            $request->validate([
                'unit_name'      => 'required|string|max:255',
                'address'        => 'nullable|string',
                'phone'          => 'nullable|string',
                'type'           => 'nullable|in:rural,urban',
                'district_id'    => 'required|integer|exists:tripura_master_data,district_code',
                'subdivision_id' => 'nullable|integer|exists:tripura_master_data,sub_lgd_code',
                'ulb_id'         => 'nullable|integer|exists:tripura_master_data,ulb_lgd_code',
                'ward_id'        => 'nullable|integer|exists:tripura_master_data,gp_vc_ward_lgd_code',
            ]);

            $user = Auth::user();
            if ($user->user_type !== 'individual') {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Only individual users can create units.',
                ], 403);
            }

            DB::beginTransaction();

            $unit = UserUnit::create([
                'user_id'        => $user->id,
                'unit_name'      => $request->unit_name,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'type'           => $request->type,
                'district_id'    => $request->district_id,
                'subdivision_id' => $request->subdivision_id,
                'ulb_id'         => $request->ulb_id,
                'ward_id'        => $request->ward_id,
                'status'         => 'active',
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Unit added successfully.',
                'data'    => $unit,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to add unit.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function user_unit_update(Request $request)
    {


        try {

            $request->validate([
                'unit_id'        => 'required|integer|exists:user_units,id',
                'unit_name'      => 'required|string|max:255',
                'address'        => 'nullable|string|max:500',
                'phone'          => 'nullable|string|max:20',
                'type'           => 'nullable|in:rural,urban',
                'district_id'    => 'required|integer|exists:tripura_master_data,district_code',
                'subdivision_id' => 'nullable|integer|exists:tripura_master_data,sub_lgd_code',
                'ulb_id'         => 'nullable|integer|exists:tripura_master_data,ulb_lgd_code',
                'ward_id'        => 'nullable|integer|exists:tripura_master_data,gp_vc_ward_lgd_code',
            ]);

            $user = Auth::user();

            if ($user->user_type !== 'individual') {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Only individual users can update units.',
                ], 403);
            }

            DB::beginTransaction();

            $unit = UserUnit::where('id', $request->unit_id)
                ->where('user_id', $user->id)
                ->first();

            if (!$unit) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Unit not found or unauthorized access.',
                ], 404);
            }

            $unit->update([
                'unit_name'      => $request->unit_name,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'type'           => $request->type,
                'district_id'    => $request->district_id,
                'subdivision_id' => $request->subdivision_id,
                'ulb_id'         => $request->ulb_id,
                'ward_id'        => $request->ward_id,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Unit updated successfully.',
                'data'    => $unit,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to update unit.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function get_user_unit_list()
    {

        try {

            $user = Auth::user();

            if ($user->user_type !== 'individual') {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Only individual users can view units.',
                ], 403);
            }

            $units = UserUnit::with([
                'district',
                'subdivision',
                'ulb',
                'ward',
            ])
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->orderBy('id', 'desc')
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

            return response()->json([
                'status' => 1,
                'message' => 'User units fetched successfully.',
                'data' => $units,
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch units.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function get_duplicate_pan_accounts(Request $request)
    {
        try {
            $user = Auth::user();

            if (empty($user->pan)) {
                return response()->json([
                    'status' => 0,
                    'message' => 'PAN is not available for this user.'
                ], 400);
            }

            $duplicate_accounts = User::where('pan', $user->pan)
                ->where('status', 'active')
                ->where('user_type', 'individual')
                ->select('id', 'name_of_enterprise', 'authorized_person_name', 'email_id', 'mobile_no', 'user_name', 'status', 'created_at')
                ->get();

            if ($duplicate_accounts->count() <= 1) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No duplicate accounts found.'
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Dear User, you have following accounts. Please choose which one you want to keep active and remaining will be de-activated.',
                'accounts' => $duplicate_accounts
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Failed to get duplicate accounts.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function choose_active_account(Request $request)
    {
        try {
            $request->validate([
                'selected_account_id' => 'required|exists:users,id'
            ]);

            $user = Auth::user();

            DB::beginTransaction();

            $selected_account = User::where('id', $request->selected_account_id)->first();

            if ($selected_account->pan !== $user->pan) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Invalid account selection.'
                ], 422);
            }

            User::where('pan', $user->pan)
                ->where('id', '!=', $request->selected_account_id)
                ->update(['status' => 'blocked']);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Account activated successfully. Other accounts have been deactivated.',
                'active_account' => $selected_account
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 0,
                'message' => 'Failed to activate account.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
