<?php

namespace App\Console\Commands\Ai;

use App\Services\Ai\DiscoveryKnowledgeSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncDiscoveryKnowledge extends Command
{
    protected $signature = 'discovery-knowledge:sync
                            {document? : Document slug such as labour-services}
                            {--all : Synchronize all discovery PDFs}
                            {--document-version=1 : Document version number}';

    protected $description = 'Synchronize static service discovery PDFs with Qdrant';

    private const DOCUMENTS = [
        'labour-services' => [
            'document_key' => 'discovery:labour-services',
            'title' => 'SWAAGAT Labour Services Selection Guide',
            'category' => 'labour-services',
            'filename' => 'labour-services.pdf',
        ],

        'factories-boilers-services' => [
            'document_key' => 'discovery:factories-boilers-services',
            'title' => 'SWAAGAT Factories and Boilers Services Selection Guide',
            'category' => 'factories-boilers-services',
            'filename' => 'factories-boilers-services.pdf',
        ],

        'legal-metrology-services' => [
            'document_key' => 'discovery:legal-metrology-services',
            'title' => 'SWAAGAT Legal Metrology Services Selection Guide',
            'category' => 'legal-metrology-services',
            'filename' => 'legal-metrology-services.pdf',
        ],

        'electrical-power-services' => [
            'document_key' => 'discovery:electrical-power-services',
            'title' => 'SWAAGAT Electrical and Power Services Selection Guide',
            'category' => 'electrical-power-services',
            'filename' => 'electrical-power-services.pdf',
        ],

        'business-registration-tax-services' => [
            'document_key' => 'discovery:business-registration-tax-services',
            'title' => 'SWAAGAT Business Registration and Tax Services Selection Guide',
            'category' => 'business-registration-tax-services',
            'filename' => 'business-registration-tax-services.pdf',
        ],

        'urban-development-services' => [
            'document_key' => 'discovery:urban-development-services',
            'title' => 'SWAAGAT Urban Development Services Selection Guide',
            'category' => 'urban-development-services',
            'filename' => 'urban-development-services.pdf',
        ],

        'pollution-waste-services' => [
            'document_key' => 'discovery:pollution-waste-services',
            'title' => 'SWAAGAT Pollution and Waste Management Services Selection Guide',
            'category' => 'pollution-waste-services',
            'filename' => 'pollution-waste-services.pdf',
        ],

        'tourism-services' => [
            'document_key' => 'discovery:tourism-services',
            'title' => 'SWAAGAT Tourism Services Selection Guide',
            'category' => 'tourism-services',
            'filename' => 'tourism-services.pdf',
        ],

        'excise-services' => [
            'document_key' => 'discovery:excise-services',
            'title' => 'SWAAGAT Excise Services Selection Guide',
            'category' => 'excise-services',
            'filename' => 'excise-services.pdf',
        ],

        'land-water-infrastructure-services' => [
            'document_key' => 'discovery:land-water-infrastructure-services',
            'title' => 'SWAAGAT Land, Water and Infrastructure Services Selection Guide',
            'category' => 'land-water-infrastructure-services',
            'filename' => 'land-water-infrastructure-services.pdf',
        ],

        'other-regulated-business-services' => [
            'document_key' => 'discovery:other-regulated-business-services',
            'title' => 'SWAAGAT Other Regulated Business Services Selection Guide',
            'category' => 'other-regulated-business-services',
            'filename' => 'other-regulated-business-services.pdf',
        ],

        'general-service-selection' => [
            'document_key' => 'discovery:general-service-selection',
            'title' => 'SWAAGAT General Service Selection Guide',
            'category' => 'general-service-selection',
            'filename' => 'general-service-selection.pdf',
        ],
    ];

    public function __construct(
        private DiscoveryKnowledgeSyncService $sync_service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $document = trim(
            (string) $this->argument('document')
        );

        $sync_all = (bool) $this->option(
            'all'
        );

        $version = max(
            1,
            (int) $this->option('version')
        );

        if ($document === '' && !$sync_all) {
            $this->error(
                'Provide a document slug or use --all.'
            );

            $this->newLine();

            $this->line('Examples:');
            $this->line(
                'php artisan discovery-knowledge:sync labour-services'
            );

            $this->line(
                'php artisan discovery-knowledge:sync --all'
            );

            return self::FAILURE;
        }

        if ($document !== '' && $sync_all) {
            $this->error(
                'Use either one document slug or --all, not both.'
            );

            return self::FAILURE;
        }

        if ($document !== '') {
            return $this->sync_one(
                $document,
                $version
            );
        }

        return $this->sync_all(
            $version
        );
    }

    private function sync_one(
        string $document,
        int $version
    ): int {
        $document = str_replace(
            'discovery:',
            '',
            trim($document)
        );

        if (!array_key_exists(
            $document,
            self::DOCUMENTS
        )) {
            $this->error(
                "Unknown discovery document: {$document}"
            );

            $this->newLine();

            $this->line(
                'Available documents:'
            );

            foreach (
                array_keys(self::DOCUMENTS)
                as $document_slug
            ) {
                $this->line(
                    '- ' . $document_slug
                );
            }

            return self::FAILURE;
        }

        $configuration = self::DOCUMENTS[
            $document
        ];

        $this->info(
            'Synchronizing: ' .
            $configuration['title']
        );

        $result = $this->execute_sync(
            $configuration,
            $version
        );

        if (empty($result['status'])) {
            $this->error(
                $result['message']
                ?? 'Discovery synchronization failed.'
            );

            if (!empty($result['error'])) {
                $this->line(
                    'Error: ' .
                    $result['error']
                );
            }

            if (!empty($result['file_path'])) {
                $this->line(
                    'File: ' .
                    $result['file_path']
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Discovery knowledge synchronized successfully.'
        );

        $this->table(
            ['Field', 'Value'],
            [
                [
                    'Document key',
                    $result['document_key'],
                ],
                [
                    'Version',
                    $result['version'],
                ],
                [
                    'Services detected',
                    $result['total_services_detected'],
                ],
                [
                    'Chunks',
                    $result['total_chunks'],
                ],
            ]
        );

        return self::SUCCESS;
    }

    private function sync_all(
        int $version
    ): int {
        $this->info(
            'Synchronizing all discovery documents...'
        );

        $successful = 0;
        $failed = 0;
        $results = [];

        foreach (
            self::DOCUMENTS
            as $slug => $configuration
        ) {
            $this->line('');
            $this->line(
                'Processing: ' .
                $configuration['title']
            );

            try {
                $result = $this->execute_sync(
                    $configuration,
                    $version
                );
            } catch (Throwable $exception) {
                $result = [
                    'status' => false,
                    'message' =>
                        $exception->getMessage(),
                ];
            }

            if (!empty($result['status'])) {
                $successful++;

                $results[] = [
                    $slug,
                    'Successful',
                    $result['total_services_detected']
                        ?? 0,

                    $result['total_chunks']
                        ?? 0,

                    '',
                ];

                $this->info('Completed.');
            } else {
                $failed++;

                $results[] = [
                    $slug,
                    'Failed',
                    0,
                    0,
                    $result['message']
                        ?? 'Unknown error',
                ];

                $this->error(
                    $result['message']
                    ?? 'Failed.'
                );
            }
        }

        $this->newLine();

        $this->table(
            [
                'Document',
                'Status',
                'Services',
                'Chunks',
                'Reason',
            ],
            $results
        );

        $this->table(
            ['Result', 'Count'],
            [
                [
                    'Total documents',
                    count(self::DOCUMENTS),
                ],
                [
                    'Successful',
                    $successful,
                ],
                [
                    'Failed',
                    $failed,
                ],
            ]
        );

        return $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function execute_sync(
        array $configuration,
        int $version
    ): array {
        $file_path =
            'ai/service-discovery/' .
            $configuration['filename'];

        return $this->sync_service->sync(
            document_key:
                $configuration['document_key'],

            title:
                $configuration['title'],

            category:
                $configuration['category'],

            file_path:
                $file_path,

            language:
                'en',

            version:
                $version
        );
    }
}