<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FastApiAiService
{
    public function investigate_application_stuck(array $payload): array
    {
        $base_url = config('ai.base_url');
        $secret = config('ai.secret');
        $timeout = config('ai.timeout');

        if (!$secret) {
            throw new RuntimeException('AI service secret is missing.');
        }

        $response = Http::timeout($timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-AI-SECRET' => $secret,
            ])
            ->post($base_url . '/api/ai/application-stuck-investigator', $payload);

        if (!$response->successful()) {
            throw new RuntimeException('FastAPI AI service failed: ' . $response->body());
        }

        return $response->json();
    }
    
    public function investigate_application(array $payload): array
    {
        $base_url = config('ai.base_url');
        $secret = config('ai.secret');
        $timeout = config('ai.timeout');

        if (!$secret) {
            throw new RuntimeException('AI service secret is missing.');
        }

        $response = Http::timeout($timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-AI-SECRET' => $secret,
            ])
            ->post($base_url . '/check-application', $payload);

        if (!$response->successful()) {
            throw new RuntimeException('AI service failed: ' . $response->body());
        }

        return $response->json();
    }
}