<?php

namespace App\Jobs\Ai;

use App\Services\Ai\ServiceKnowledgeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncServiceKnowledgeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 240;

    /**
     * Prevent many questionnaire rows from creating
     * duplicate sync jobs for the same service.
     */
    public int $uniqueFor = 120;

    public function __construct(
        public int $service_id,
        public string $action = 'sync',
        public ?string $service_name = null
    ) {
        /*
        * Queueable already contains the afterCommit property.
        * We only set its value instead of declaring it again.
        */
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "{$this->action}:{$this->service_id}";
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(
        ServiceKnowledgeSyncService $sync_service
    ): void {
        if ($this->action === 'delete') {
            $result = $sync_service->remove(
                $this->service_id,
                $this->service_name
            );
        } else {
            $result = $sync_service->sync(
                $this->service_id
            );
        }

        if (empty($result['status'])) {
            throw new RuntimeException(
                $result['message']
                    ?? 'Service knowledge synchronization failed.'
            );
        }

        Log::channel('ai_chat')->info(
            'Service knowledge job completed',
            [
                'service_id' => $this->service_id,
                'action' => $this->action,
                'total_sections' => $result['total_sections']
                    ?? null,
                'total_chunks' => $result['total_chunks']
                    ?? null,
            ]
        );
    }

    public function failed(Throwable $e): void
    {
        Log::channel('ai_chat')->error(
            'Service knowledge job permanently failed',
            [
                'service_id' => $this->service_id,
                'action' => $this->action,
                'error' => $e->getMessage(),
            ]
        );
    }
}