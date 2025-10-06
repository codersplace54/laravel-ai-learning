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

class UserController extends Controller
{
    public function register(Request $request)
    {

        try {


            $request->validate(
                [
                    'name_of_enterprise' => 'required|string|max:255',
                    'authorized_person_name' => 'required|string|max:255',
                    'email_id' => 'required|email|unique:users,email_id',
                    'mobile_no' => 'required|string|max:15',
                    'user_name' => 'required|string|max:100|unique:users,user_name',
                    'district_id' => 'nullable|integer|exists:tripura_master_data,district_code',
                    'subdivision_id' => 'nullable|integer|exists:tripura_master_data,sub_lgd_code',
                    'ulb_id' => 'nullable|integer|exists:tripura_master_data,ulb_lgd_code',
                    'ward_id' => 'nullable|integer|exists:tripura_master_data,gp_vc_ward_lgd_code',
                    'registered_enterprise_address' => 'nullable|string',
                    'registered_enterprise_city' => 'nullable|string',
                    'user_type' => 'required|string|in:individual,department,admin',
                    'password' => 'required|string|min:6',
                    'pan' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',

                    'department_id'   => 'required_if:user_type,department|integer|exists:departments,id',
                    'hierarchy_level' => 'required_if:user_type,department|in:block,subdivision,district,state1,state2,state3',
                    'designation'      => 'nullable|string',
                    'is_active'      => 'nullable|integer'
                ],
                [
                    'name_of_enterprise.required' => 'Enterprise name is required.',
                    'authorized_person_name.required' => 'Authorized person name is required.',
                    'email_id.required' => 'Email is required.',
                    'email_id.email' => 'Please enter a valid email address.',
                    'email_id.unique' => 'This email is already registered.',
                    'mobile_no.required' => 'Mobile number is required.',
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
                    'department_id.required' => 'The department field is required.',
                    'department_id.integer'  => 'The department must be a valid number.',
                    'department_id.exists'   => 'The selected department does not exist in our records.',
                    'hierarchy_level.required' => 'The hierarchy level is required.',
                    'hierarchy_level.in'       => 'The hierarchy level must be one of: block, subdivision, district, state1, state2, or state3.',
                    'is_active.integer'        => 'The status must be a valid number (0 or 1).',
                ]
            );


            DB::beginTransaction();


            $user = User::create([
                'name_of_enterprise' => $request->name_of_enterprise,
                'authorized_person_name' => $request->authorized_person_name,
                'email_id' => $request->email_id,
                'mobile_no' => $request->mobile_no,
                'user_name' => $request->user_name,
                'district_id' => $request->district_id,
                'subdivision_id' => $request->subdivision_id,
                'ulb_id' => $request->ulb_id,
                'ward_id' => $request->ward_id,
                'registered_enterprise_address' => $request->registered_enterprise_address,
                'registered_enterprise_city' => $request->registered_enterprise_city,
                'user_type' => $request->user_type,
                'pan' => $request->pan,
                'password' => Hash::make($request->password),
                'status' => 'active',
            ]);

            if ($user->user_type == "department") {

                $department_user = DepartmentUser::create([
                    'user_id' => $user->id,
                    'department_id' => $request->department_id,
                    'designation' => $request->designation,
                    'block_id' => $request->ulb_id,
                    'subdivision_id' => $request->subdivision_id,
                    'district_id' => $request->district_id,
                    'hierarchy_level' => $request->hierarchy_level,
                    'is_active' => 1
                ]);
            }

            $now = Carbon::now();
            $date = $now->format('y');
            $month = $now->format('m');
            $userIdPadded = str_pad($user->id, 7, '0', STR_PAD_LEFT);
            $bin = "TR{$date}{$month}{$userIdPadded}";

            $user->bin = $bin;
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


            $rules = [
                'id' => 'required|exists:users,id',
            ];

            if ($request->name_of_enterprise !== null) {
                $rules['name_of_enterprise'] = 'required|string|max:255';
            }
            if ($request->authorized_person_name !== null) {
                $rules['authorized_person_name'] = 'required|string|max:255';
            }
            if ($request->email_id !== null) {
                $rules['email_id'] = 'required|email|unique:users,email_id,' . $request->id;
            }
            if ($request->mobile_no !== null) {
                $rules['mobile_no'] = 'required|string|max:15';
            }
            if ($request->user_name !== null) {
                $rules['user_name'] = 'required|string|max:100|alpha_dash|unique:users,user_name,' . $request->id;
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
            if ($request->pan !== null) {
                $rules['pan'] = 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/';
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
                $rules['hierarchy_level'] = 'nullable|in:block,subdivision,district,state1,state2,state3';
            }
            if ($request->department_id !== null) {
                $rules['department_id'] = 'nullable|integer|exists:departments,id';
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
                'user_name.required' => 'Username is required.',
                'user_name.unique' => 'This username is already taken.',
                'user_name.alpha_dash' => 'Username can only contain letters, numbers, dashes, and underscores.',
                'registered_enterprise_address.required' => 'Enterprise address is required.',
                'registered_enterprise_city.required' => 'Enterprise city is required.',
                'user_type.required' => 'User type is required.',
                'user_type.in' => 'User type must be individual,department or admin.',
                'pan.regex' => 'The PAN number must be in valid format (e.g., ABCDE1234F).',
                'password.min' => 'Password must be at least 6 characters long.',
                'district_id.exists'  => 'Selected district is invalid.',
                'subdivision_id.exists' => 'Selected subdivision is invalid.',
                'ulb_id.exists'  => 'Selected ULB is invalid.',
                'department_id.integer'  => 'The department must be a valid number.',
                'department_id.exists'   => 'The selected department does not exist in our records.',
                'hierarchy_level.in' => 'The hierarchy level must be one of: block, subdivision, district, state1, state2, or state3.',
            ]);

            DB::beginTransaction();


            $user = User::findOrFail($request->id);


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

                $department_user = DepartmentUser::where('user_id', $user->id)->first();
                if ($department_user) {

                    $department_user->update([
                        'department_id' => $request->department_id,
                        'hierarchy_level' => $request->hierarchy_level,
                        'district_id' => $request->district_id,
                        'subdivision_id' => $request->subdivision_id,
                        'block_id' => $request->ulb_id,
                    ]);
                    $user->department_id   = $department_user->department_id;
                    $user->hierarchy_level = $department_user->hierarchy_level;
                } else {

                    $department_user = DepartmentUser::create([
                        'user_id' => $user->id,
                        'department_id' => $request->department_id,
                        'designation' => $request->designation,
                        'block_id' => $request->ulb_id,
                        'subdivision_id' => $request->subdivision_id,
                        'district_id' => $request->district_id,
                        'hierarchy_level' => $request->hierarchy_level,
                        'is_active' => 1
                    ]);
                }
            }

            $user = [
                'name_of_enterprise' => $user->name_of_enterprise,
                'authorized_person_name' => $user->authorized_person_name,
                'email_id' => $user->email_id,
                'mobile_no' => $user->mobile_no,
                'pan' => $user->pan,
                'bin' => $user->bin,
                'district'                     => $user->district->district_name,
                'district_code'                => $user->district->district_code,
                'subdivision_name'                 => $user->subdivision->sub_division ?? null,
                'subdivision_code'               => $user->subdivision->sub_lgd_code ?? null,
                'ulb_name'                          => $user->ulb->ulb_name ?? null,
                'ulb_code'                 => $user->ulb->ulb_lgd_code ?? null,
                'ward_name'                         => $user->ward->name_of_gp_vc_or_ward ?? null,
                'ward_code'                      => $user->ward->gp_vc_ward_lgd_code ?? null,
                'user_type' => $user->user_type,
                'registered_enterprise_address' => $user->registered_enterprise_address,
                'registered_enterprise_city' => $user->registered_enterprise_city,
                'is_active'                    => $user->is_active,
                'department_id'   => $user->department_user->department_id   ?? null,
                'hierarchy_level' => $user->department_user->hierarchy_level ?? null,
                'designation'     => $user->department_user->designation     ?? null,


            ];

            DB::commit();

            return response()->json([
                'status' => 1,
                'data' => $user,
                'message' => 'User updated successfully',
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

            $data = [
                'name_of_enterprise' => $user->name_of_enterprise,
                'authorized_person_name' => $user->authorized_person_name,
                'email_id' => $user->email_id,
                'mobile_no' => $user->mobile_no,
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


            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_department_users()
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


            $department_users = User::where('user_type', 'department')
                ->with(['district', 'subdivision', 'ulb', 'ward', 'department_user.department'])
                ->get();

            if ($department_users->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No Deaprtment users found.',
                    'data' => [],
                ], 200);
            }

            $department_users = $department_users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name_of_enterprise' => $user->name_of_enterprise,
                    'authorized_person_name' => $user->authorized_person_name,
                    'email_id' => $user->email_id,
                    'mobile_no'  => $user->mobile_no,
                    'pan'  => $user->pan,
                    'user_name'  => $user->user_name,
                    'bin'   => $user->bin,
                    'district_code'   => $user->district->district_code ?? null,
                    'district_name' => $user->district->district_name ?? null,
                    'subdivision_code'   => $user->subdivision->sub_lgd_code ?? null,
                    'subdivision_name' =>  $user->subdivision->sub_division ?? null,
                    'ulb_code'   => $user->ulb->ulb_lgd_code ?? null,
                    'ulb_name' => $user->ulb->ulb_name ?? null,
                    'ward_code'   => $user->ward->gp_vc_ward_lgd_code ?? null,
                    'ward_name' => $user->ward->name_of_gp_vc_or_ward ?? null,
                    'department_name' => $user->department_user->department->name ?? null,
                    'department_id' => $user->department_user->department_id ?? null,
                    'registered_enterprise_address' => $user->registered_enterprise_address,
                    'registered_enterprise_city' => $user->registered_enterprise_city,
                    'user_type' => $user->user_type,
                    'status' => $user->status,
                    'created_at'  => $user->created_at,
                    'updated_at'  => $user->updated_at
                ];
            });

            return response()->json([
                'status' => 1,
                'data' => $department_users
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

            $data = [
                'name_of_enterprise' => $department_user->name_of_enterprise,
                'authorized_person_name' => $department_user->authorized_person_name,
                'email_id' => $department_user->email_id,
                'mobile_no' => $department_user->mobile_no,
                'pan' => $department_user->pan,
                'bin' => $department_user->bin,
                'district'                     => $department_user->district->district_name,
                'district_code'                => $department_user->district->district_code,
                'subdivision_name'                 => $department_user->subdivision->sub_division ?? null,
                'subdivision_code'               => $department_user->subdivision->sub_lgd_code ?? null,
                'ulb_name'                          => $department_user->ulb->ulb_name ?? null,
                'ulb_code'                 => $department_user->ulb->ulb_lgd_code ?? null,
                'ward_name'                         => $department_user->ward->name_of_gp_vc_or_ward ?? null,
                'ward_code'                      => $department_user->ward->gp_vc_ward_lgd_code ?? null,
                'user_type' => $department_user->user_type,
                'registered_enterprise_address' => $department_user->registered_enterprise_address,
                'registered_enterprise_city' => $department_user->registered_enterprise_city,
                'is_active'                    => $department_user->department_user->is_active,
                'department_id'   => $department_user->department_user->department_id   ?? null,
                'hierarchy_level' => $department_user->department_user->hierarchy_level ?? null,
                'designation'     => $department_user->department_user->designation     ?? null,


            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
