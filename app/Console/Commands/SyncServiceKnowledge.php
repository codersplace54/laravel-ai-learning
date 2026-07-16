<?php

namespace App\Console\Commands;

use App\Models\ServiceMaster;
use App\Services\Ai\ServiceKnowledgeSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncServiceKnowledge extends Command
{
    protected $signature = 'service-knowledge:sync
                            {service_id? : Service ID to synchronize}
                            {--all : Synchronize all active services}';

    protected $description = 'Generate and synchronize service knowledge with Qdrant';

    public function __construct(
        private ServiceKnowledgeSyncService $sync_service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $service_id = $this->argument('service_id');
        $sync_all = (bool) $this->option('all');

        if (!$service_id && !$sync_all) {
            $this->error('Please provide a service ID or use --all.');

            $this->line('');
            $this->line('Examples:');
            $this->line('php artisan service-knowledge:sync 37');
            $this->line('php artisan service-knowledge:sync --all');

            return self::FAILURE;
        }

        if ($service_id && $sync_all) {
            $this->error(
                'Use either a service ID or --all, not both.'
            );

            return self::FAILURE;
        }

        if ($service_id) {
            return $this->sync_one_service(
                (int) $service_id
            );
        }

        return $this->sync_all_services();
    }

    private function sync_one_service(
        int $service_id
    ): int {
        $service = ServiceMaster::find($service_id);

        if (!$service) {
            $this->error(
                "Service {$service_id} was not found."
            );

            return self::FAILURE;
        }

        $service_name =
            $service->service_title_or_description
            ?? "Service {$service_id}";

        $this->info(
            "Synchronizing: {$service_name}"
        );

        try {
            $result = $this->sync_service->sync(
                $service_id
            );
        } catch (Throwable $e) {
            $this->error(
                'Synchronization failed: ' .
                $e->getMessage()
            );

            return self::FAILURE;
        }

        if (empty($result['status'])) {
            $this->error(
                $result['message']
                ?? 'Service knowledge synchronization failed.'
            );

            if (!empty($result['error'])) {
                $this->line(
                    'Error: ' . $result['error']
                );
            }

            if (!empty($result['status_code'])) {
                $this->line(
                    'HTTP status: ' .
                    $result['status_code']
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Service knowledge synchronized successfully.'
        );

        $this->table(
            ['Field', 'Value'],
            [
                [
                    'Service ID',
                    $result['service_id']
                        ?? $service_id,
                ],
                [
                    'Service name',
                    $result['service_name']
                        ?? $service_name,
                ],
                [
                    'Sections',
                    $result['total_sections']
                        ?? 0,
                ],
                [
                    'Chunks',
                    $result['total_chunks']
                        ?? 0,
                ],
            ]
        );

        return self::SUCCESS;
    }

    private function sync_all_services(): int
    {
        $query = ServiceMaster::query()
            ->where('is_active', 1)
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn(
                'No active services were found.'
            );

            return self::SUCCESS;
        }

        $this->info(
            "Synchronizing {$total} active services..."
        );

        $progress_bar = $this->output
            ->createProgressBar($total);

        $progress_bar->start();

        $successful = 0;
        $failed = 0;
        $failures = [];

        $query->chunkById(
            20,
            function ($services) use (
                &$successful,
                &$failed,
                &$failures,
                $progress_bar
            ) {
                foreach ($services as $service) {
                    try {
                        $result = $this->sync_service->sync(
                            (int) $service->id
                        );

                        if (!empty($result['status'])) {
                            $successful++;
                        } else {
                            $failed++;

                            $failures[] = [
                                'service_id' => $service->id,

                                'service_name' =>
                                    $service
                                        ->service_title_or_description
                                    ?? 'Service',

                                'reason' =>
                                    $result['message']
                                    ?? 'Unknown error',
                            ];
                        }
                    } catch (Throwable $e) {
                        $failed++;

                        $failures[] = [
                            'service_id' => $service->id,

                            'service_name' =>
                                $service
                                    ->service_title_or_description
                                ?? 'Service',

                            'reason' => $e->getMessage(),
                        ];
                    }

                    $progress_bar->advance();
                }
            }
        );

        $progress_bar->finish();

        $this->newLine(2);

        $this->table(
            ['Result', 'Count'],
            [
                ['Total services', $total],
                ['Successful', $successful],
                ['Failed', $failed],
            ]
        );

        if (count($failures) > 0) {
            $this->newLine();

            $this->error(
                'The following services failed:'
            );

            $this->table(
                [
                    'Service ID',
                    'Service name',
                    'Reason',
                ],
                array_map(
                    fn (array $failure) => [
                        $failure['service_id'],
                        $failure['service_name'],
                        $failure['reason'],
                    ],
                    $failures
                )
            );
        }

        return $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}