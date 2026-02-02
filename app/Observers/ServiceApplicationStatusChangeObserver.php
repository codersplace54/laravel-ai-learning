<?php

namespace App\Observers;

use App\Models\UserServiceApplication;
use Illuminate\Support\Facades\Auth;

class ServiceApplicationStatusChangeObserver
{
    public function created(UserServiceApplication $application)
    {
        if ($application->status !== 'draft') {
            $user = $application->user;
            $service = $application->service;
            
            activity('application_created')
                ->causedBy($user)
                ->performedOn($application)
                ->withProperties([
                    'application_id' => $application->applicationId,
                    'service_title_or_description' => $service->service_title_or_description ?? 'Unknown Service',
                ])
                ->log($user->user_name . ' applied for ' . ($service->service_title_or_description ?? 'Unknown Service') . ' application');
        }
    }

    public function updating(UserServiceApplication $application)
    {
        if ($application->isDirty('status')) {
            $old_status = $application->getOriginal('status');
            $new_status = $application->status;
            
            $user = Auth::user();
            $context = $this->get_context();
            $appKey = $application->getKey();
            activity('status_change')
                ->causedBy($user)
                ->performedOn($application)
                ->event('Application status changed')
                ->withProperties([
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                    'context' => $context,
                    'application_id' => $appKey,
                ])
                ->log(($user->user_name ?? 'System') . ' changed ' . ($application->user->user_name ?? 'Unknown User') . "'s application status from '{$old_status}' to '{$new_status}'");
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