<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Cache;

class PanLookupRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        // If we have client id header, use that too:
        // $client = $request->header('X-Client-Id') ?: $request->ip();
        // $key = 'pan_lookup_rl:' . $client;

        $key = 'pan_lookup_rl:' . $request->ip();

        if (!Cache::add($key, 1, now()->addSeconds(10))) {
            return response()->json([
                'status' => 0,
                'message' => 'Too many requests. Try again after 10 seconds.',
            ], 429)->header('Retry-After', 10);
        }

        return $next($request);
    }
}
