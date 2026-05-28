<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\JWTToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Http;

class SaralSsoController extends Controller
{
    public function saral_sso_login(Request $request)
    {
        $received_sig = $request->header('X-SARAL-Signature');
        $timestamp    = $request->header('X-SARAL-Timestamp');

        if (!$received_sig || !$timestamp) {
            return $this->err(401, 'ERR_INVALID_SIG', 'invalid_signature', 'Missing signature or timestamp.');
        }

        // timestamp freshness (±5 minutes)
        if (abs(time() - (int) $timestamp) > 300) {
            return $this->err(401, 'ERR_AUTH_EXPIRED', 'token_expired',
                'The integration token or timestamp has expired. Re-authentication required.');
        }

        // HMAC-SHA256 signature validation
        $payload = $request->all();
        ksort($payload);
        $canonical  = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $message    = $canonical . '|' . $timestamp;
        $secret     = config('services.saral_sso.secret');
        $expected   = base64_encode(hash_hmac('sha256', $message, $secret, true));

        if (!hash_equals($expected, $received_sig)) {
            return $this->err(401, 'ERR_INVALID_SIG', 'invalid_signature',
                'Request signature validation failed. Possible tampering detected.');
        }

        try {
            $data = $request->validate([
                'registered_name'       => 'required|string',
                'authorised_person_name' => 'required|string',
                'date_of_birth'         => 'nullable|date',
                'authorised_pan'        => 'nullable|string',   // accepted but NOT stored
                'email'                 => 'required|email',
                'mobile_number'         => 'required|digits:10',
                'username'              => 'required|string',
                'city'                  => 'nullable|string',
                'registered_address'    => 'nullable|string',
                'district'              => 'required|integer',
                'sub_division'          => 'nullable|integer',
                'ulb_or_block'          => 'nullable|integer',
                'ward_number'           => 'nullable|integer',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->err(422, 'ERR_MISSING_FIELD', 'missing_required_field',
                'One or more required fields are absent in the request payload.', $e->errors());
        }

        DB::beginTransaction();

        try {
            $user = User::where('user_name', $data['username'])->first();

            if (!$user) {

                if (User::where('mobile_no', $data['mobile_number'])->exists()) {
                    DB::rollBack();
                    return $this->err(409, 'ERR_PHONE_EXISTS', 'phone_number_exists',
                        'An account with this mobile number already exists on SWAAGAT 2.0.');
                }

                if (User::where('email_id', $data['email'])->exists()) {
                    DB::rollBack();
                    return $this->err(409, 'ERR_EMAIL_EXISTS', 'email_exists',
                        'An account with this email address already exists on SWAAGAT 2.0.');
                }

                $user = User::create([
                    'user_name'                     => $data['username'],
                    'authorized_person_name'        => $data['authorised_person_name'],
                    'name_of_enterprise'            => $data['registered_name'],
                    'mobile_no'                     => $data['mobile_number'],
                    'email_id'                      => $data['email'],
                    'registered_enterprise_address' => $data['registered_address'] ?? null,
                    'registered_enterprise_city'    => $data['city'] ?? null,
                    'district_id'                   => $data['district'],
                    'subdivision_id'                => $data['sub_division'] ?? null,
                    'ulb_id'                        => $data['ulb_or_block'] ?? null,
                    'ward_id'                       => $data['ward_number'] ?? null,
                    'dob'                           => $data['date_of_birth'] ?? null,
                    'user_type'                     => 'individual',
                    'password'                      => Hash::make(Str::random(32)),
                    'status'                        => 'active',
                    'is_mobile_verified'            => 1,
                ]);
            } else {
                // Existing user - check conflicts with other users
                if (User::where('mobile_no', $data['mobile_number'])->where('id', '!=', $user->id)->exists()) {
                    DB::rollBack();
                    return $this->err(409, 'ERR_PHONE_EXISTS', 'phone_number_exists',
                        'An account with this mobile number already exists on SWAAGAT 2.0.');
                }

                if (User::where('email_id', $data['email'])->where('id', '!=', $user->id)->exists()) {
                    DB::rollBack();
                    return $this->err(409, 'ERR_EMAIL_EXISTS', 'email_exists',
                        'An account with this email address already exists on SWAAGAT 2.0.');
                }
            }

            if ($user->status === 'blocked') {
                DB::rollBack();
                return $this->err(403, 'ERR_USER_BLOCKED', 'user_blocked',
                    'Your account is inactive. Please contact admin.');
            }

            $old = JWTToken::where('user_id', $user->id)->first();
            if ($old) {
                try { JWTAuth::setToken($old->token)->invalidate(); } catch (\Exception) {}
                $old->delete();
            }

            $token = JWTAuth::fromUser($user);

            JWTToken::create([
                'user_id'          => $user->id,
                'token'            => $token,
                'ip_address'       => $request->ip(),
                'user_agent'       => $request->header('User-Agent'),
                'expires_at'       => now()->addMinutes(JWTAuth::factory()->getTTL()),
                'last_activity_at' => now(),
            ]);

            DB::commit();

            $frontend_url = rtrim(config('app.app_frontendurl', env('APP_FRONTEND_URL')), '/');
            $redirect_url = $frontend_url . '/dashboard/home?token=' . $token;

            return response()->json([
                'status'       => 'success',
                'redirect_url' => $redirect_url,
                'sso_token'    => $token,
                'message'      => 'SSO login successful. Redirecting to SWAAGAT 2.0...',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->err(500, 'ERR_SERVER', 'internal_server_error',
                'SWAAGAT backend encountered an unexpected error.');
        }
    }

    private function err(int $http, string $code, string $key, string $message, array $errors = [])
    {
        $body = [
            'status'     => 'error',
            'error_code' => $code,
            'error_key'  => $key,
            'message'    => $message,
        ];

        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $http);
    }

}


