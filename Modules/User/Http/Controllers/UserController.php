<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Modules\User\Models\User;
use Exception;
use Carbon\Carbon;

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
                    'registered_enterprise_address' => 'required|string',
                    'registered_enterprise_city' => 'required|string',
                    'user_type' => 'required|string|in:citizen,department',
                    'password' => 'required|string|min:6',
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
                    'registered_enterprise_address.required' => 'Enterprise address is required.',
                    'registered_enterprise_city.required' => 'Enterprise city is required.',
                    'user_type.required' => 'User type is required.',
                    'user_type.in' => 'User type must be either citizen or department.',
                    'password.required' => 'Password is required.',
                    'password.min' => 'Password must be at least 6 characters.',
                ]
            );


            DB::beginTransaction();


            $user = User::create([
                'name_of_enterprise' => $request->name_of_enterprise,
                'authorized_person_name' => $request->authorized_person_name,
                'email_id' => $request->email_id,
                'mobile_no' => $request->mobile_no,
                'user_name' => $request->user_name,
                'registered_enterprise_address' => $request->registered_enterprise_address,
                'registered_enterprise_city' => $request->registered_enterprise_city,
                'user_type' => $request->user_type,
                'password' => Hash::make($request->password),
                'status' => 'active',
            ]);

            $now = Carbon::now();
            $date = $now->format('y');
            $month = $now->format('m');
            $userIdPadded = str_pad($user->id, 7, '0', STR_PAD_LEFT);
            $bin = "TR{$date}{$month}{$userIdPadded}";

            $user->bin = $bin;
            $user->save();


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
                $rules['registered_enterprise_address'] = 'required|string';
            }
            if ($request->registered_enterprise_city !== null) {
                $rules['registered_enterprise_city'] = 'required|string';
            }
            if ($request->user_type !== null) {
                $rules['user_type'] = 'required|in:citizen,department';
            }
            if ($request->password !== null) {
                $rules['password'] = 'nullable|string|min:6';
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
                'user_type.in' => 'User type must be either citizen or department.',
                'password.min' => 'Password must be at least 6 characters long.',
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
            if ($request->password !== null) {
               $update_data['password'] = Hash::make($request->password);
            }

            $user->update($update_data);

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


            $user = JWTAuth::parseToken()->authenticate();

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
}
