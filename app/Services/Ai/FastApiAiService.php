<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class FastApiAiService
{
    public function applicationStuckInvestigator(array $payload): array
    {
        $base_url = config('ai.base_url');
        $secret = config('ai.secret');
        $timeout = config('ai.timeout');

        $response = Http::timeout($timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-AI-SECRET' => $secret,
            ])
            ->post($base_url . '/api/ai/application-stuck-investigator', $payload);

        if ($response->failed()) {
            return [
                'status' => false,
                'message' => 'FastAPI AI service failed',
                'http_status' => $response->status(),
                'error' => $response->json() ?: $response->body(),
            ];
        }

        return [
            'status' => true,
            'data' => $response->json(),
        ];
    }
}