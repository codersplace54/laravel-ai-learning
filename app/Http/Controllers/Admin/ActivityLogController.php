<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function activity_logs(Request $request)
    {
        $validated = $request->validate([
            'search'     => 'nullable|string|max:200',
            'action'     => 'nullable|string|max:50',     // created/updated/deleted/approved/rejected
            'action_by'  => 'nullable|integer',           // causer_id
            'for_whom'   => 'nullable|integer',           // subject_id
            'module'     => 'nullable|string|max:120',    // User / Report / Settings 
            'platform'   => 'nullable|string|max:30',     // Web/iOS/Android
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date',
            'sort_by'    => 'nullable|in:created_at,event,log_name',
            'sort_dir'   => 'nullable|in:asc,desc',
            'per_page'   => 'nullable|integer|min:5|max:200',
        ]);

        $per_page = $validated['per_page'] ?? 20;
        $sort_by  = $validated['sort_by'] ?? 'created_at';
        $sort_dir = $validated['sort_dir'] ?? 'desc';

        $query = Activity::query()
            ->with(['causer', 'subject']); // morph relations

        if (!empty($validated['search'])) {
            $search_term = $validated['search'];
            $query->where(function ($sub_query) use ($search_term) {
                $sub_query->where('description', 'like', "%{$search_term}%")
                   ->orWhere('event', 'like', "%{$search_term}%")
                   ->orWhere('log_name', 'like', "%{$search_term}%")
                   ->orWhere('subject_type', 'like', "%{$search_term}%")
                   ->orWhere('properties', 'like', "%{$search_term}%");
            });
        }

        if (!empty($validated['action'])) {
            $query->where('event', $validated['action']);
        }

        if (!empty($validated['action_by'])) {
            $query->where('causer_id', $validated['action_by']);
        }

        if (!empty($validated['for_whom'])) {
            $query->where('subject_id', $validated['for_whom']);
        }

        if (!empty($validated['module'])) {
            
            $module = $validated['module'];

            $query->where(function ($sub_query) use ($module) {
                $sub_query->where('subject_type', $module)
                   ->orWhere('subject_type', 'like', "%\\{$module}");
            });
        }

        if (!empty($validated['platform'])) {
            $platform = $validated['platform'];
            $query->where('properties', 'like', "%\"platform\":\"{$platform}\"%");
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $paginator = $query->orderBy($sort_by, $sort_dir)->paginate($per_page);

        $paginator->getCollection()->transform(function (Activity $activity) {
            return [
                'id' => $activity->id,
                'date' => $activity->created_at?->toDateTimeString(),

                'action' => $this->display_action($activity->event),

                'action_by' => [
                    'id' => $activity->causer_id,
                    'name' => $this->display_user_name($activity->causer),
                ],

                'for_whom' => [
                    'id' => $activity->subject_id,
                    'name' => $this->display_user_name($activity->subject),
                ],

                'module' => $activity->subject_type ? class_basename($activity->subject_type) : null,

                'platform' => data_get($activity->properties, 'platform', 'Web'),

                'description' => $activity->description,
                'event' => $activity->event,
                'log_name' => $activity->log_name,
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Activity logs fetched',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function activity_log_details(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:activity_log,id'
        ]);


        $activity = Activity::where('id',$request->id)->with(['causer', 'subject'])->first();

        return response()->json([
            'status' => 1,
            'message' => 'Activity log details fetched',
            'data' => [
                'id' => $activity->id,
                'date' => $activity->created_at?->toDateTimeString(),
                'action' => $this->display_action($activity->event),
                'description' => $activity->description,
                'event' => $activity->event,
                'log_name' => $activity->log_name,

                'action_by' => [
                    'id' => $activity->causer_id,
                    'type' => class_basename($activity->causer_type),
                    'name' => $this->display_user_name($activity->causer),
                ],
                'for_whom' => [
                    'id' => $activity->subject_id,
                    'type' => class_basename($activity->subject_type),
                    'name' => $this->display_user_name($activity->subject),
                ],

                'properties' => $activity->properties,
            ],
        ]);
    }

    private function display_action(?string $event): ?string
    {
        if (!$event) return null;

        return match ($event) {
            'created' => 'Create',
            'updated' => 'Update',
            'deleted' => 'Delete',
            default => ucfirst($event),
        };
    }

    public function activity_log_filters()
    {
        $actions = Cache::remember('activity_log_actions', 3600, function () {
            return Activity::distinct()->pluck('event')->filter()->sort()->values();
        });

        $modules = Cache::remember('activity_log_modules', 3600, function () {
            return Activity::distinct()->pluck('subject_type')
                ->filter()
                ->map(fn($type) => class_basename($type))
                ->unique()
                ->sort()
                ->values();
        });

        return response()->json([
            'status' => 1,
            'message' => 'Filter options fetched',
            'data' => [
                'actions' => $actions,
                'modules' => $modules,
            ],
        ]);
    }

    private function display_user_name($model): ?string
    {
        if (!$model) return null;

        return $model->authorized_person_name
            ?? $model->name_of_enterprise
            ?? $model->name
            ?? $model->email_id
            ?? ($model->id ?? null);
    }
}
