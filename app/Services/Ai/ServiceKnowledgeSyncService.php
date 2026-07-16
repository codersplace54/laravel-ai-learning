<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ServiceKnowledgeSyncService
{
    public function __construct(
        private ServiceKnowledgeDocumentService $document_service
    ) {}

    /**
     * Generate the latest knowledge for one service
     * and synchronize it with FastAPI/Qdrant.
     */
    public function sync(int $service_id): array
    {
        $knowledge = $this->document_service->build(
            $service_id
        );

        if (!$knowledge) {
            return [
                'status' => false,
                'message' => 'Service not found.',
                'service_id' => $service_id,
            ];
        }

        $base_url = rtrim(
            (string) config('ai.base_url'),
            '/'
        );

        if ($base_url === '') {
            return [
                'status' => false,
                'message' => 'AI service base URL is not configured.',
                'service_id' => $service_id,
            ];
        }

        try {
            $response = Http::timeout(180)
                ->connectTimeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-AI-SECRET' => config('ai.secret'),
                ])
                ->post(
                    $base_url .
                    '/api/ai/knowledge/services/sync',
                    $knowledge
                );

            if ($response->failed()) {
                Log::channel('ai_chat')->warning(
                    'Service knowledge sync failed',
                    [
                        'service_id' => $service_id,
                        'status_code' => $response->status(),
                        'response' => $response->body(),
                    ]
                );

                return [
                    'status' => false,
                    'message' => 'FastAPI service knowledge sync failed.',
                    'service_id' => $service_id,
                    'status_code' => $response->status(),
                    'response' => $response->json()
                        ?? $response->body(),
                ];
            }

            $result = $response->json();

            if (!is_array($result)) {
                return [
                    'status' => false,
                    'message' => 'FastAPI returned an invalid response.',
                    'service_id' => $service_id,
                ];
            }

            Log::channel('ai_chat')->info(
                'Service knowledge synchronized',
                [
                    'service_id' => $service_id,
                    'service_name' => $knowledge['service_name']
                        ?? null,
                    'total_sections' => $result['total_sections']
                        ?? 0,
                    'total_chunks' => $result['total_chunks']
                        ?? 0,
                ]
            );

            return [
                'status' => true,
                'message' => $result['message']
                    ?? 'Service knowledge synchronized successfully.',
                'service_id' => $service_id,
                'service_name' => $knowledge['service_name']
                    ?? null,
                'total_sections' => $result['total_sections']
                    ?? count($knowledge['sections'] ?? []),
                'total_chunks' => $result['total_chunks']
                    ?? 0,
                'data' => $result,
            ];
        } catch (Throwable $e) {
            Log::channel('ai_chat')->error(
                'Service knowledge sync exception',
                [
                    'service_id' => $service_id,
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'status' => false,
                'message' => 'Unable to connect to the AI service.',
                'service_id' => $service_id,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
 * Remove all stored RAG knowledge for a deleted service.
 */
public function remove(
    int $service_id,
    ?string $service_name = null
): array {
    $base_url = rtrim(
        (string) config('ai.base_url'),
        '/'
    );

    if ($base_url === '') {
        return [
            'status' => false,
            'message' => 'AI service base URL is not configured.',
            'service_id' => $service_id,
        ];
    }

    $payload = [
        'service_id' => $service_id,

        'service_name' => $service_name
            ?: "Deleted Service {$service_id}",

        'department_id' => null,
        'department_name' => null,
        'source_updated_at' => now()->toDateTimeString(),
        'total_sections' => 0,

        /*
         * FastAPI already treats an empty section list
         * as removal of old service knowledge.
         */
        'sections' => [],
    ];

    try {
        $response = Http::timeout(180)
            ->connectTimeout(10)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-AI-SECRET' => config('ai.secret'),
            ])
            ->post(
                $base_url .
                '/api/ai/knowledge/services/sync',
                $payload
            );

        if ($response->failed()) {
            Log::channel('ai_chat')->warning(
                'Service knowledge removal failed',
                [
                    'service_id' => $service_id,
                    'status_code' => $response->status(),
                    'response' => $response->body(),
                ]
            );

            return [
                'status' => false,
                'message' => 'FastAPI service knowledge removal failed.',
                'service_id' => $service_id,
                'status_code' => $response->status(),
                'response' => $response->json()
                    ?? $response->body(),
            ];
        }

        Log::channel('ai_chat')->info(
            'Service knowledge removed',
            [
                'service_id' => $service_id,
                'service_name' => $service_name,
            ]
        );

        return [
            'status' => true,
            'message' => 'Service knowledge removed successfully.',
            'service_id' => $service_id,
            'total_sections' => 0,
            'total_chunks' => 0,
        ];
    } catch (Throwable $e) {
        Log::channel('ai_chat')->error(
            'Service knowledge removal exception',
            [
                'service_id' => $service_id,
                'error' => $e->getMessage(),
            ]
        );

        return [
            'status' => false,
            'message' => 'Unable to connect to the AI service.',
            'service_id' => $service_id,
            'error' => $e->getMessage(),
        ];
    }
}
}