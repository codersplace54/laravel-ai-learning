<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\Contracts\Activity;

trait LogsModelActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['created_at', 'updated_at', 'deleted_at'])
            ->useLogName('swaagat');
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        try {
            if (app()->runningInConsole()) return;

            $activity->properties = $activity->properties->merge([
                'ip' => request()->ip(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            
        }
    }
}
