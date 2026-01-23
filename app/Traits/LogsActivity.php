<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait LogsActivity
{
    protected function logActivity(string $message, $subject = null, $causer = null, array $additionalProperties = [])
    {
        $causer = $causer ?? Auth::user();
        $request = request();
        
        $properties = array_merge([
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ], $additionalProperties);
        
        $activity = activity();
        
        if ($subject !== null) {
            $activity->performedOn($subject);
        }
        
        $activity->causedBy($causer)
            ->withProperties($properties)
            ->log($message);
    }
}