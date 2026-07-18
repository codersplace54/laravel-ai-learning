<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DiscoveryKnowledgeSyncService
{
    public function sync(
        string $document_key,
        string $title,
        string $category,
        string $file_path,
        string $language = 'en',
        int $version = 1
    ): array {
        if (!str_starts_with($document_key, 'discovery:')) {
            return [
                'status' => false,
                'message' => 'Discovery document key must start with discovery:.',
                'document_key' => $document_key,
            ];
        }

        if (!Storage::disk('local')->exists($file_path)) {
            return [
                'status' => false,
                'message' => 'Discovery PDF was not found.',
                'document_key' => $document_key,
                'file_path' => $file_path,
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
                'document_key' => $document_key,
            ];
        }

        $absolute_path = Storage::disk('local')->path(
            $file_path
        );

        $file_handle = null;

        try {
            $file_handle = fopen(
                $absolute_path,
                'rb'
            );

            if ($file_handle === false) {
                return [
                    'status' => false,
                    'message' => 'Unable to open discovery PDF.',
                    'document_key' => $document_key,
                    'file_path' => $file_path,
                ];
            }

            $response = Http::timeout(300)
                ->connectTimeout(15)
                ->acceptJson()
                ->withHeaders([
                    'X-AI-SECRET' => config('ai.secret'),
                ])
                ->attach(
                    'file',
                    $file_handle,
                    basename($absolute_path)
                )
                ->post(
                    $base_url .
                    '/api/ai/knowledge/discovery/sync',
                    [
                        'document_key' => $document_key,
                        'title' => $title,
                        'category' => $category,
                        'language' => $language,
                        'version' => $version,
                    ]
                );

            if ($response->failed()) {
                Log::warning(
                    'Discovery knowledge sync failed',
                    [
                        'document_key' => $document_key,
                        'file_path' => $file_path,
                        'status_code' => $response->status(),
                        'response' => $response->body(),
                    ]
                );

                return [
                    'status' => false,
                    'message' => 'FastAPI discovery knowledge sync failed.',
                    'document_key' => $document_key,
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
                    'document_key' => $document_key,
                ];
            }

            Log::info(
                'Discovery knowledge synchronized',
                [
                    'document_key' => $document_key,
                    'title' => $title,
                    'category' => $category,
                    'total_services_detected' =>
                        $result['total_services_detected']
                        ?? 0,

                    'total_chunks' =>
                        $result['total_chunks']
                        ?? 0,
                ]
            );

            return [
                'status' => true,

                'message' =>
                    $result['message']
                    ?? 'Discovery knowledge synchronized successfully.',

                'document_key' => $document_key,
                'title' => $title,
                'category' => $category,
                'version' => $version,

                'service_ids' =>
                    $result['service_ids']
                    ?? [],

                'total_services_detected' =>
                    $result['total_services_detected']
                    ?? 0,

                'total_chunks' =>
                    $result['total_chunks']
                    ?? 0,

                'content_hash' =>
                    $result['content_hash']
                    ?? null,

                'data' => $result,
            ];
        } catch (Throwable $exception) {
            Log::error(
                'Discovery knowledge sync exception',
                [
                    'document_key' => $document_key,
                    'file_path' => $file_path,
                    'error' => $exception->getMessage(),
                ]
            );

            return [
                'status' => false,
                'message' => 'Unable to connect to the AI service.',
                'document_key' => $document_key,
                'error' => $exception->getMessage(),
            ];
        } finally {
            if (is_resource($file_handle)) {
                fclose($file_handle);
            }
        }
    }
}