<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\JWTToken;

class JWTActivityMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {

        try {


            $token = JWTAuth::getToken();
            $user = JWTAuth::toUser($token);

            $dbToken = JWTToken::where('user_id', $user->id)
                ->where('token', $token)
                ->first();

            if (!$dbToken) {
                return response()->json(['message' => 'Session expired or logged out'], 401);
            }


            if ($dbToken->last_activity_at && $dbToken->last_activity_at->lt(now()->subHours(1))) {
                $dbToken->delete();
                JWTAuth::invalidate($token);
                return response()->json(['message' => 'Session expired due to inactivity'], 401);
            }

            $dbToken->update(['last_activity_at' => now()]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Unauthorized: ' . $e->getMessage()], 401);
        }

        return $next($request);
    }
}
