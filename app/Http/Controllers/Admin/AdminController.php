<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class AdminController extends Controller
{
    public function fetch_all_business_users()
    {


        try {

            $admin = Auth::user();
            if (!$admin) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = User::where('id', $admin->id)
                ->where('user_type', 'admin')
                ->first();

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or not an admin.'
                ], 404);
            }


            $business_users = User::where('user_type', 'individual')->get();

            if ($business_users->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No business users found.',
                    'data' => [],
                ], 200);
            }

            $business_users = $business_users->map(function ($user) {
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
                'data' => $business_users
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function fetch_all_department_users()
    {



        try {

            $admin = Auth::user();
            if (!$admin) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = User::where('id', $admin->id)
                ->where('user_type', 'admin')
                ->first();

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or not an admin.'
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
                    'hierarchy_level'  => $user->department_user->hierarchy_level,
                    'status' => $user->status,
                    'created_at'  => $user->created_at,
                    'updated_at'  => $user->updated_at,
                    'created_by'  => $user->department_user->created_by  ?? null,
                    'updated_by'  => $user->department_user->updated_by ?? null
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


    public function update_user_status(Request $request, $id)
    {


        try {

            DB::beginTransaction();

            $user = User::findOrFail($id);

            $user->status = $user->status === 'active' ? 'blocked' : 'active';
            $user->save();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Status updated successfully.',
                'updated_status' => $user->status,
            ]);

        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while updating status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
