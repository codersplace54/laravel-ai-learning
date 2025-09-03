<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\JWTToken;

class JWTActivityMiddleware
{

    public function handle($request, Closure $next)
    {

        try {


            $token = JWTAuth::getToken();
            $user = JWTAuth::toUser($token);

            $db_token = JWTToken::where('user_id', $user->id)
                ->where('token', $token)
                ->first();

            if (!$db_token) {
                return response()->json(['message' => 'Session expired or logged out'], 401);
            }


            if ($db_token->last_activity_at && $db_token->last_activity_at->lt(now()->subHours(1))) {
                $db_token->delete();
                JWTAuth::invalidate($token);
                return response()->json(['message' => 'Session expired due to inactivity'], 401);
            }

            $db_token->update(['last_activity_at' => now()]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Unauthorized: ' . $e->getMessage()], 401);
        }

        return $next($request);
    }
}
