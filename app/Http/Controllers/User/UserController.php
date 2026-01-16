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

class UserController extends Controller
{
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
                ]
            );


            DB::beginTransaction();

            if ($request->user_type === 'individual') {

                $user_otp_exist = Otp::where('mobile_no', $request->mobile_no)->first();

                if (!$user_otp_exist || $user_otp_exist->is_verified == 0) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'Please verify your mobile number with OTP before registering.',
                    ], 422);
                }

                $user_otp_exist->delete();
            }

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
                'pan' => $request->pan,
                'password' => Hash::make($request->password),
                'status' => 'active',
                'is_mobile_verified' => $request->user_type === 'individual' ? 1 : 0,
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

            if ($request->pan && $request->pan !== $user->pan) {
                $rules['pan'] = 'required_if:user_type,individual|string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|unique:users,pan';
            } elseif ($request->pan) {
                $rules['pan'] = 'required_if:user_type,individual|string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/';
            }

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
            ]);

            DB::beginTransaction();

            if ($user->user_type === "individual") {
                $now = Carbon::now();

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

            $user->update($update_data);

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

            $mobile_no = $user->mobile_no;

            if (app()->environment('production')) {
                $otp_code = random_int(100000, 999999);
            } else {
                $otp_code = 123456;
            }

            DB::beginTransaction();

            $user_otp_exist = Otp::where('mobile_no', $mobile_no)->first();

            if ($user_otp_exist) {
                $user_otp_exist->update([
                    'code' => $otp_code,
                    'is_verified' => 0,
                ]);
            } else {
                Otp::create([
                    'mobile_no' => $mobile_no,
                    'code' => $otp_code,
                    'is_verified' => 0,
                ]);
            }

            $sms = SmsService::buildSmsMessage('action_verification_otp', [
                'OTP_CODE' => $otp_code,
            ]);

            $sms_result = SmsService::send(
                $mobile_no,
                $sms['message'],
                $sms['template_id']
            );

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'OTP sent successfully for profile update.',
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
}
