<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Exception;
use App\Models\JWTToken;
use App\Models\Otp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\SmsService;

class AuthController extends Controller
{

    public function login(Request $request)
    {

        try {


            $request->validate([
                'user_name' => 'required|string',
                'password'  => 'required|string',
            ]);

            $identifier = trim($request->user_name);
            $password   = $request->password;

            $token = null;

            $token = JWTAuth::attempt(['user_name' => $identifier, 'password' => $password]);

            if (! $token) {
                $token = JWTAuth::attempt(['pan' => strtoupper($identifier), 'password' => $password]);
            }

            if (! $token) {
                $token = JWTAuth::attempt(['bin' => $identifier, 'password' => $password]);
            }

            if (!$token) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            $user = JWTAuth::setToken($token)->toUser();

            if ($user->status == "blocked") {
                JWTAuth::invalidate($token);

                return response()->json([
                    'status' => 0,
                    'message' => 'Your account is inactive. Please contact admin.'
                ], 403);
            }

            if ($user->password_reset_required == 1) {
                JWTAuth::invalidate($token);

                return response()->json([
                    'status' => 0,
                    'message' => 'We found your account, but your password must be reset due to portal migration. Redirecting you to password reset…',
                    'password_reset_required' => true
                ], 200);
            }

            $old_token_row = JWTToken::where('user_id', $user->id)->first();

            if ($old_token_row) {
                try {

                    JWTAuth::setToken($old_token_row->token)->invalidate();
                } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {

                    Log::warning('Could not invalidate old token: ' . $e->getMessage());
                }

                $old_token_row->delete();
            }

            JWTToken::create([
                'user_id' => $user->id,
                'token' => $token,
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'expires_at' => now()->addMinutes(JWTAuth::factory()->getTTL()),
                'last_activity_at' => now(),
            ]);

            $data = $user->only([
                'id',
                'authorized_person_name',
                'name_of_enterprise',
                'email_id',
                'user_name',
                'bin',
                'pan',
                'user_type',
            ]);

            $data['district']    = $user->district_id ? $user->district?->district_name : null;
            $data['subdivision'] = $user->subdivision_id ? $user->subdivision?->sub_division : null;
            $data['ulb']         = $user->ulb_id ? $user->ulb?->ulb_name : null;
            $data['ward']        = $user->ward_id ? $user->ward?->name_of_gp_vc_or_ward : null;

            if ($user->user_type === 'department') {
                $department_user = $user->department_user()->with('department')->first();
                if ($department_user && $department_user->department) {
                    $data['department_id'] = $department_user->department->id;
                    $data['department_name'] = $department_user->department->name;
                    $data['designation'] = $department_user->designation;
                    $data['hierarchy'] = $department_user->hierarchy_level;
                }
            }

            return response()->json([
                'status' => 1,
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
                'data' => $data
            ]);
        } catch (JWTException $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Could not create token',
                'error' => $e->getMessage()
            ], 500);
        } catch (Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Login failed due to server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request)
    {

        try {


            $token = JWTAuth::getToken();

            if (!$token) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Token not provided'
                ], 400);
            }


            JWTAuth::invalidate($token);

            JWTToken::where('token', $token)->delete();

            return response()->json([
                'status' => 1,
                'message' => 'Successfully logged out'
            ]);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Token is already invalid or expired',
            ], 401);
        } catch (JWTException $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to logout, please try again',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function change_password(Request $request)
    {


        try {


            $request->validate([
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:6',
            ]);

            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 401);
            }

            if (!Hash::check($request->old_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incorrect old password.'
                ], 400);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully.'
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function send_otp(Request $request)
    {
        try {
            $request->validate([
                'mobile_no' => 'required|string|max:15',
            ]);

            $mobile_no = $request->mobile_no;

            $user = User::where('mobile_no', $mobile_no)->first();

            if ($user && $user->is_mobile_verified) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Mobile number is already taken.',
                ], 200);
            }

            if (app()->environment('production')) {
                $otp_code = random_int(100000, 999999);
            } else {
                $otp_code = 123456;
            }

            $expires_at = Carbon::now()->addDays(60);

            DB::beginTransaction();

            $user_otp_exist = Otp::where('mobile_no', $mobile_no)->first();

            if ($user_otp_exist) {

                $user_otp_exist->update([
                    'code'        => $otp_code,
                    'expires_at'  => $expires_at,
                    'is_verified' => 0,
                ]);
            } else {

                Otp::create([
                    'mobile_no' => $mobile_no,
                    'code'      => $otp_code,
                    'expires_at' => $expires_at,
                ]);
            }


            $sms = SmsService::buildSmsMessage('user_registration_otp', [
                '#var#' => $otp_code,
            ]);

            $sms_result = SmsService::send(
                $mobile_no,
                $sms['message'],
                $sms['template_id']
            );


            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'OTP sent successfully to your mobile number.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to send OTP.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function verify_otp(Request $request)
    {
        try {
            $request->validate([
                'mobile_no' => 'required|string|max:15',
                'otp_code'  => 'required|string|size:6',
            ]);

            $mobile_no = $request->mobile_no;
            $otp_code  = $request->otp_code;

            $user = User::where('mobile_no', $mobile_no)->first();

            if ($user && $user->is_mobile_verified) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'Mobile number is already verified.',
                ], 200);
            }

            $now = Carbon::now();

            DB::beginTransaction();

            $user_otp = Otp::where('mobile_no', $mobile_no)
                ->where('code', $otp_code)
                ->first();

            if (!$user_otp) {
                DB::rollBack();
                return response()->json([
                    'status'  => 0,
                    'message' => 'Invalid OTP. Please enter the correct OTP and try again.',
                ], 422);
            }

            if ($user_otp->expires_at < $now) {
                DB::rollBack();
                return response()->json([
                    'status'  => 0,
                    'message' => 'OTP has expired. Please generate a new OTP to proceed.',
                ], 422);
            }

            $user_otp->update([
                'is_verified' => 1,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Otp verified successfully.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to verify OTP.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function forgot_password_send_otp(Request $request)
    {
        try {
            $request->validate([
                'phone_or_pan' => 'required|string|max:15',
            ], [
                'phone_or_pan.required' => 'PAN number or mobile number is required.',
                'phone_or_pan.string' => 'PAN number or mobile number must be a string.',
                'phone_or_pan.max' => 'PAN number or mobile number cannot exceed 15 characters.',
            ]);

            $phone_or_pan = trim($request->phone_or_pan);

            if (preg_match('/[A-Za-z]/', $phone_or_pan)) {
                
                $pan_no = strtoupper($phone_or_pan);
                $user = User::where('pan', $pan_no)->first();
                
                if (! $user) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'No account is registered with this PAN number.',
                    ], 404);
                }
            } else {
                $user = User::where('mobile_no', $phone_or_pan)->first();
                
                if (! $user) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'No account is registered with this mobile number.',
                    ], 404);
                }
            }

            $mobile_no = $user->mobile_no;
            if ($user->status === "blocked") {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Your account is inactive. Please contact admin.',
                ], 403);
            }

            if (app()->environment('production')) {
                $otp_code = random_int(100000, 999999);
            } else {
                $otp_code = 123456;
            }

            $expires_at = Carbon::now()->addDays(60);

            DB::beginTransaction();

            $otp_row = Otp::where('mobile_no', $mobile_no)->first();

            if ($otp_row) {
                $otp_row->update([
                    'code'        => $otp_code,
                    'expires_at'  => $expires_at,
                    'is_verified' => 0,
                ]);
            } else {
                Otp::create([
                    'mobile_no'   => $mobile_no,
                    'code'        => $otp_code,
                    'expires_at'  => $expires_at,
                    'is_verified' => 0,
                ]);
            }

            $sms = SmsService::buildSmsMessage('user_registration_otp', [
                '#var#' => $otp_code,
            ]);

            $sms_result = SmsService::send(
                $mobile_no,
                $sms['message'],
                $sms['template_id']
            );

            DB::commit();

            return response()->json([
                'status'        => 1,
                'message'       => 'OTP sent successfully.',
                'mobile_no'     => $mobile_no,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to send OTP.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    public function forgot_password_verify_otp(Request $request)
    {
        try {
            $request->validate([
                'phone_or_pan' => 'required|string|max:15',
                'otp_code'     => 'required|string|size:6',
            ], [
                'phone_or_pan.required' => 'PAN number or mobile number is required.',
                'phone_or_pan.string' => 'PAN number or mobile number must be a string.',
                'phone_or_pan.max' => 'PAN number or mobile number cannot exceed 15 characters.',
                'otp_code.required' => 'OTP code is required.',
                'otp_code.string' => 'OTP code must be a string.',
                'otp_code.size' => 'OTP code must be exactly 6 digits.',
            ]);

            $phone_or_pan = trim($request->phone_or_pan);
            $otp_code     = $request->otp_code;

            if (preg_match('/[A-Za-z]/', $phone_or_pan)) {
                
                $pan_no = strtoupper($phone_or_pan);
                $user = User::where('pan', $pan_no)->first();
                
                if (! $user) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'No account is registered with this PAN number.',
                    ], 404);
                }
            } else {
                
                $user = User::where('mobile_no', $phone_or_pan)->first();
                
                if (! $user) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'No account is registered with this mobile number.',
                    ], 404);
                }
            }

            $mobile_no = $user->mobile_no;
            $now = Carbon::now();

            DB::beginTransaction();

            $otp_row = Otp::where('mobile_no', $mobile_no)
                ->where('code', $otp_code)
                ->first();

            if (!$otp_row) {
                DB::rollBack();
                return response()->json([
                    'status'  => 0,
                    'message' => 'Invalid OTP. Please enter the correct OTP and try again.',
                ], 422);
            }

            if ($otp_row->expires_at < $now) {
                DB::rollBack();
                return response()->json([
                    'status'  => 0,
                    'message' => 'OTP has expired. Please generate a new OTP to proceed.',
                ], 422);
            }

            $otp_row->update(['is_verified' => 1]);

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'OTP verified successfully.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to verify OTP.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function forgot_password_reset(Request $request)
    {
        try {
            $request->validate([
                'phone_or_pan' => 'required|string|max:15',
                'new_password' => 'required|string|min:6|confirmed',
            ], [
                'phone_or_pan.required' => 'PAN number or mobile number is required.',
                'phone_or_pan.string' => 'PAN number or mobile number must be a string.',
                'phone_or_pan.max' => 'PAN number or mobile number cannot exceed 15 characters.',
                'new_password.required' => 'New password is required.',
                'new_password.string' => 'New password must be a string.',
                'new_password.min' => 'New password must be at least 6 characters.',
                'new_password.confirmed' => 'New password confirmation does not match.',
            ]);

            $phone_or_pan = trim($request->phone_or_pan);

            if (preg_match('/[A-Za-z]/', $phone_or_pan)) {
                $pan_no = strtoupper($phone_or_pan);
                $user = User::where('pan', $pan_no)->first();
                
                if (! $user) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'No account is registered with this PAN number.',
                    ], 404);
                }
            } else {
                $user = User::where('mobile_no', $phone_or_pan)->first();
                
                if (! $user) {
                    return response()->json([
                        'status'  => 0,
                        'message' => 'No account is registered with this mobile number.',
                    ], 404);
                }
            }

            $mobile_no = $user->mobile_no;
            $now = Carbon::now();

            DB::beginTransaction();

            $otp_row = Otp::where('mobile_no', $mobile_no)
                ->where('is_verified', 1)
                ->where('expires_at', '>=', $now)
                ->first();

            if (! $otp_row) {
                DB::rollBack();

                return response()->json([
                    'status'  => 0,
                    'message' => 'OTP not verified or expired.',
                ], 422);
            }

            $user->password = Hash::make($request->new_password);
            $user->password_reset_required = 0;
            $user->save();

            $otp_row->delete();

            $old_tokens = JWTToken::where('user_id', $user->id)->get();
            foreach ($old_tokens as $row) {
                try {
                    JWTAuth::setToken($row->token)->invalidate();
                } catch (\Exception $e) {
                }
            }
            JWTToken::where('user_id', $user->id)->delete();

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Password reset successful. Please login again.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to reset password.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function check_pan_registered(Request $request)
    {
        try {
            $request->validate([
                'pan_no' => 'required|string|max:15',
            ]);

            $pan_no = strtoupper(trim($request->pan_no));

            $user = User::where('pan', $pan_no)->first();

            if (! $user) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No account is registered with this PAN number.',
                    'is_registered'    => false,
                ], 404);
            }


            return response()->json([
                'status'  => 1,
                'message' => 'Account already exist with this PAN number.',
                'is_registered' => true
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function check_mobile_registered(Request $request)
    {
        try {
            $request->validate([
                'mobile_no' => 'required|string|max:15',
            ]);

            $mobile_no = trim($request->mobile_no);

            $user = User::where('mobile_no', $mobile_no)->first();

            if (! $user) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No account is registered with this mobile number.',
                    'is_registered'    => false,
                ], 404);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Account already exist with this mobile number.',
                'is_registered' => true
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    public function login_by_admin(Request $request)
    {

        try {

            $request->validate([
                'user_id' => 'required|integer|exists:users,id',
            ]);

            $admin = Auth::user();

            if ($admin->user_type !== 'admin') {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $user = User::find($request->user_id);

            if ($user->status == 'blocked') {
                return response()->json([
                    'status' => 0,
                    'message' => 'This user is inactive.'
                ], 403);
            }

            $token = JWTAuth::fromUser($user);

            JWTToken::create([
                'user_id' => $user->id,
                'token' => $token,
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'expires_at' => now()->addMinutes(JWTAuth::factory()->getTTL()),
                'last_activity_at' => now(),
            ]);

            $data = $user->only([
                'id',
                'authorized_person_name',
                'name_of_enterprise',
                'email_id',
                'user_name',
                'bin',
                'user_type',
            ]);

            $data['district'] = $user->district->district_name ?? null;
            $data['subdivision'] = $user->district->sub_division ?? null;
            $data['ulb'] = $user->district->ulb_name ?? null;
            $data['ward'] = $user->district->name_of_gp_vc_or_ward ?? null;

            if ($user->user_type === 'department') {
                $department_user = $user->department_user()->with('department')->first();
                if ($department_user && $department_user->department) {
                    $data['department_id'] = $department_user->department->id;
                    $data['department_name'] = $department_user->department->name;
                    $data['designation'] = $department_user->designation;
                    $data['hierarchy'] = $department_user->hierarchy_level;
                }
            }

            return response()->json([
                'status' => 1,
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
                'data' => $data
            ]);
        } catch (Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to login as user',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
