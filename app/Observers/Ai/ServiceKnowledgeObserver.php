<?php

namespace App\Observers\Ai;

use App\Jobs\Ai\SyncServiceKnowledgeJob;
use App\Models\ServiceMaster;
use Illuminate\Database\Eloquent\Model;

class ServiceKnowledgeObserver
{
    public function created(Model $model): void
    {
        $this->queue_sync($model);
    }

    public function updated(Model $model): void
    {
        /*
         * Normally service_id will not change.
         * This also refreshes the old service if a row
         * is moved from one service to another.
         */
        if (
            !$model instanceof ServiceMaster
            && $model->wasChanged('service_id')
        ) {
            $old_service_id = (int) $model->getOriginal(
                'service_id'
            );

            if ($old_service_id > 0) {
                $this->dispatch_sync(
                    $old_service_id
                );
            }
        }

        $this->queue_sync($model);
    }

    public function deleted(Model $model): void
    {
        /*
         * When the service itself is deleted,
         * remove its old Qdrant knowledge.
         */
        if ($model instanceof ServiceMaster) {
            SyncServiceKnowledgeJob::dispatch(
                (int) $model->id,
                'delete',
                $model->service_title_or_description
            )->delay(
                now()->addSeconds(3)
            );

            return;
        }

        /*
         * When a questionnaire, fee, renewal or flow row
         * is deleted, rebuild the remaining service data.
         */
        $this->queue_sync($model);
    }

    private function queue_sync(Model $model): void
    {
        $service_id = $model instanceof ServiceMaster
            ? (int) $model->id
            : (int) ($model->service_id ?? 0);

        if ($service_id <= 0) {
            return;
        }

        $this->dispatch_sync($service_id);
    }

    private function dispatch_sync(
        int $service_id
    ): void {
        SyncServiceKnowledgeJob::dispatch(
            $service_id
        )->delay(
            now()->addSeconds(3)
        );
    }
}