<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\Contracts\Activity;

trait LogsModelActivity
{
    use LogsActivity;

    protected $customDescription = null;
    protected $customEventName = null;

    public function logAs($description, $eventName = null)
    {
        $this->customDescription = $description;
        $this->customEventName = $eventName;
        return $this;
    }

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

            if ($this->customDescription) {
                $activity->description = $this->customDescription;
                $this->customDescription = null;
            }

            if ($this->customEventName) {
                $activity->event = $this->customEventName;
                $this->customEventName = null;
            }
        } catch (\Throwable $e) {
            
        }
    }
}
