<?php

namespace App\Observers;

use App\Models\UserServiceApplication;
use Illuminate\Support\Facades\Auth;

class ServiceApplicationStatusChangeObserver
{
    public function updating(UserServiceApplication $application)
    {
        if ($application->isDirty('status')) {
            $old_status = $application->getOriginal('status');
            $new_status = $application->status;
            
            $user = Auth::user();
            $context = $this->get_context();
            
            activity('status_change')
                ->causedBy($user)
                ->performedOn($application)
                ->withProperties([
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                    'context' => $context,
                    'application_id' => $application->applicationId,
                ])
                ->log("Status changed from '{$old_status}' to '{$new_status}'");
        }
    }

    private function get_context()
    {
        try {
            $route = request()->route();
            if (!$route) return 'system';
            
            $uri = $route->uri();
            
            if (str_contains($uri, 'payment')) return 'payment';
            if (str_contains($uri, 'update-status')) return 'department_action';
            if (str_contains($uri, 'third-party')) return 'third_party';
            if (str_contains($uri, 'renewal')) return 'renewal';
            
            return 'application_update';
        } catch (\Exception $e) {
            return 'system';
        }
    }
}