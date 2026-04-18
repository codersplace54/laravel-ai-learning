<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OtpRateLimiter
{
    const MAX_ATTEMPTS = 3;
    const DECAY_MINUTES = 5;

    public function handle(Request $request, Closure $next)
    {
        $phone = $request->input('mobile_no') ?? $request->input('phone_or_pan') ?? 'unknown';
        $key   = 'otp_rl:' . $request->ip() . ':' . $phone;

        $attempts = Cache::get($key, 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            return response()->json([
                'status'  => 0,
                'message' => 'Too many OTP requests. Please wait ' . self::DECAY_MINUTES . ' minutes before trying again.',
            ], 429);
        }

        Cache::put($key, $attempts + 1, now()->addMinutes(self::DECAY_MINUTES));

        return $next($request);
    }
}
