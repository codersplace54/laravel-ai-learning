<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Hash;
use Exception;
use App\Models\JWTToken;
use App\Models\Otp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{

    public function login(Request $request)
    {

        try {


            $credentials = $request->only('user_name', 'password');

            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            $user = JWTAuth::setToken($token)->toUser();

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
                    'status'  => 1,
                    'message' => 'Mobile number is already taken and verified.',
                ], 200);
            }

            // $otp_code = random_int(100000, 999999);
            $otp_code = 123456;

            $expires_at = Carbon::now()->addMinutes(10);

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

            $this->send_sms_otp($mobile_no, $otp_code);

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
                ->where('expires_at', '>=', $now)
                ->first();

            if (!$user_otp) {
                DB::rollBack();

                return response()->json([
                    'status'  => 0,
                    'message' => 'Invalid or expired OTP.',
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


    protected function send_sms_otp(string $mobile_no, string $otp_code): void
    {
        // TODO: Replace with real SMS integration
    }
}
