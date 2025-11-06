<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\ExistingLicense;
use App\Models\User;
use Exception;

class ExistingLicenseController extends Controller
{

    public function existing_license_store(Request $request)
    {
        try {


            DB::beginTransaction();

            $request->validate([
                'service_id'    => 'nullable|integer|exists:service_masters,id',
                'department_id' => 'nullable|integer|exists:departments,id',
                'licensee_name' => 'required|string|max:255',
                'application_no'=> 'nullable|string|max:255',
                'valid_from'    => 'nullable|date',
                'expiry_date'   => 'nullable|date|after_or_equal:valid_from',
                'license_no'    => 'required|string|max:255|unique:existing_licenses,license_no',
            ], [
                'license_no.unique' => 'This license number already exists.',
            ]);

            $user = Auth::user();

            $license = ExistingLicense::create([
                'user_id'        => $user->id,
                'service_id'     => $request->service_id,
                'department_id'  => $request->department_id,
                'licensee_name'  => $request->licensee_name,
                'application_no' => $request->application_no,
                'valid_from'     => $request->valid_from,
                'expiry_date'    => $request->expiry_date,
                'license_no'     => $request->license_no,
                'status'         => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Existing license created successfully.',
                'data'    => $license,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (Exception $e) {


            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Failed to create existing license.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function existing_license_update(Request $request)
    {
        try {

            DB::beginTransaction();

            $request->validate([
                'id'            => 'required|integer|exists:existing_licenses,id',
                'service_id'    => 'nullable|integer|exists:service_masters,id',
                'department_id' => 'nullable|integer|exists:departments,id',
                'licensee_name' => 'required|string|max:255',
                'application_no'=> 'nullable|string|max:255',
                'valid_from'    => 'nullable|date',
                'expiry_date'   => 'nullable|date|after_or_equal:valid_from',
                'license_no'    => 'required|string|max:255|unique:existing_licenses,license_no,' . $request->id,
                'status'        => 'nullable|in:pending,approved,rejected', 
            ]);

            $admin   = Auth::user();
            $license = ExistingLicense::where('id',$request->id)->where('user_id',Auth::id())->first();

            if (!$license) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Access denied: you can only update your own license.',
                ], 403);
            }

            $license->update([
                'service_id'     => $request->service_id,
                'department_id'  => $request->department_id,
                'licensee_name'  => $request->licensee_name,
                'application_no' => $request->application_no,
                'valid_from'     => $request->valid_from,
                'expiry_date'    => $request->expiry_date,
                'license_no'     => $request->license_no,
                'status'         => $request->status ?? $license->status,
                'updated_by'     => $admin->email_id ?? null,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Existing license updated successfully.',
                'data'    => $license,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {


            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Failed to update existing license.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function existing_license_view()
    {
        try {
            $user_type = User::where('id', Auth::id())->value('user_type');

            if($user_type === 'admin'){
                $licenses = ExistingLicense::latest()->get();
            }else{
                $licenses = ExistingLicense::where('user_id',Auth::id())->latest()->get();
            }

            return response()->json([
                'status' => 1,
                'data'   => $licenses,
            ]);

        } catch (Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to retrieve existing licenses.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function existing_license_details(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|integer|exists:existing_licenses,id',
            ]);

            $user_type = User::where('id', Auth::id())->value('user_type');

            if($user_type === 'admin'){
                $license = ExistingLicense::where('id',$request->id)->first();
            }else{
                $license = ExistingLicense::where('id',$request->id)->where('user_id',Auth::id())->first();

                if (!$license) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'Access denied: you can only see your own license.',
                    ], 403);
                }
            }

            return response()->json([
                'status' => 1,
                'message' => 'License details fetched successfully',
                'data'   => $license,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Failed to retrieve existing license.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function existing_license_delete(Request $request)
    {
        try {
            DB::beginTransaction();

            $request->validate([
                'id' => 'required|integer|exists:existing_licenses,id',
            ]);

            $license = ExistingLicense::where('id',$request->id)->where('user_id',Auth::id())->first();

            if (!$license) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Access denied: you can only delete your own license.',
                ], 403);
            }

            $license->delete();

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Existing license deleted successfully.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {


            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Failed to delete existing license.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function existing_license_update_status(Request $request)
    {
        try {
            DB::beginTransaction();

            $request->validate([
                'id'     => 'required|integer|exists:existing_licenses,id',
                'status' => 'required|in:approved,rejected',
            ]);

            $admin   = Auth::user();

            if($admin->user_type !== 'admin'){

                 response()->json([
                    'status' => 0, 
                    'message' => 'Only admins can perform this action.'
                ], 403);

            }
            
            $license = ExistingLicense::where('id',$request->id)->first();

            $license->update([
                'status'          => $request->status,
                'action_taken_by' => $admin->id ?? null,
                'updated_by'      => $admin->email_id ?? null,
            ]);


            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'License status updated.',
                'data'    => $license,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {


            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Failed to update status.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

}
