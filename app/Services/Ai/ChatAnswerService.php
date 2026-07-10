<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class ChatAnswerService
{
    public function generate(string $message, string $data_scope, array $context): array
    {
        $base_url = rtrim(config('ai.base_url'), '/');

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-AI-SECRET'  => config('ai.secret'),
                ])
                ->post($base_url . '/api/ai/chat/answer', [
                    'message'    => $message,
                    'data_scope' => $data_scope,
                    'context'    => $context,
                ]);

            if ($response->failed()) {
                return $this->fallback();
            }

            return $response->json() ?: $this->fallback();
        } catch (\Exception $e) {
            return $this->fallback();
        }
    }

    public function generate_application_answer(string $message, array $context): array
    {
        $base_url = rtrim(config('ai.base_url'), '/');

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-AI-SECRET'  => config('ai.secret'),
                ])
                ->post($base_url . '/api/ai/application-chat', [
                    'message' => $message,
                    'context' => $context,
                ]);

            if ($response->failed()) {
                return $this->fallback();
            }

            return $response->json() ?: $this->fallback();
        } catch (\Exception $e) {
            return $this->fallback();
        }
    }

    private function fallback(): array
    {
        return [
            'answer'      => 'I could not prepare an answer right now. Please try again.',
            'short_status' => null,
            'answer_type' => 'general',
            'confidence'  => 0.0,
        ];
    }
}
