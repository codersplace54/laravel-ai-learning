<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatAnswerService
{
    public function generate(string $message, string $data_scope, array $context): array
    {
        $base_url = rtrim((string) config('ai.base_url'), '/');

        try {
            $response = Http::timeout(75)
                ->connectTimeout(10)
                ->withHeaders([
                    'Accept'      => 'application/json',
                    'Content-Type'=> 'application/json',
                    'X-AI-SECRET' => config('ai.secret'),
                ])
                ->post($base_url . '/api/ai/chat/answer', [
                    'message'    => $message,
                    'data_scope' => $data_scope,
                    'context'    => $context,
                ]);

            if ($response->failed()) {
                Log::warning('AI generic answer failed HTTP response', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'scope'  => $data_scope,
                ]);

                return $this->fallback('AI generic answer HTTP failed');
            }

            $json = $response->json();

            if (!is_array($json)) {
                return $this->fallback('AI generic answer returned invalid JSON');
            }

            $data = $this->extract_payload($json);

            return $this->normalize_answer($data, $data_scope);

        } catch (Throwable $e) {
            Log::warning('AI generic answer exception', [
                'error' => $e->getMessage(),
                'scope' => $data_scope,
            ]);

            return $this->fallback('AI generic answer exception');
        }
    }

    public function generate_application_answer(string $message, array $context): array
    {
        $base_url = rtrim((string) config('ai.base_url'), '/');

        try {
            $response = Http::timeout(120)
                ->connectTimeout(10)
                ->withHeaders([
                    'Accept'      => 'application/json',
                    'Content-Type'=> 'application/json',
                    'X-AI-SECRET' => config('ai.secret'),
                ])
                ->post($base_url . '/api/ai/application-chat', [
                    'message' => $message,
                    'context' => $context,
                ]);

            if ($response->failed()) {
                Log::warning('AI application answer failed HTTP response', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return $this->fallback('AI application answer HTTP failed');
            }

            $json = $response->json();

            if (!is_array($json)) {
                return $this->fallback('AI application answer returned invalid JSON');
            }

            $data = $this->extract_payload($json);

            return $this->normalize_answer($data, 'APPLICATION_DATA');

        } catch (Throwable $e) {
            Log::warning('AI application answer exception', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallback('AI application answer exception');
        }
    }

    private function extract_payload(array $json): array
    {
        // Supports both:
        // 1. direct FastAPI response: { answer: "..." }
        // 2. wrapped response: { status: true, data: { answer: "..." } }

        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    private function normalize_answer(array $data, string $default_type): array
    {
        $answer = trim((string) ($data['answer'] ?? $data['message'] ?? ''));

        return [
            'answer'       => $answer,
            'short_status' => $data['short_status'] ?? null,
            'waiting_on'   => $data['waiting_on'] ?? null,
            'next_action'  => $data['next_action'] ?? null,
            'answer_type'  => $data['answer_type'] ?? strtolower($default_type),
            'confidence'   => (float) ($data['confidence'] ?? 0.7),
            'answer_mode' => $data['answer_mode'] ?? 'fact',

            'resolved_question' => $data['resolved_question']
                ?? $data['user_goal']
                ?? '',

            'scope' => $data['scope'] ?? 'all_records',

            'metric' => $data['metric'] ?? null,
        ];
    }

    private function fallback(string $reason = 'fallback'): array
    {
        // Keep answer empty.
        // Controller will use local DB fallback instead of showing weak AI error message.

        return [
            'answer'       => '',
            'short_status' => null,
            'waiting_on'   => null,
            'next_action'  => null,
            'answer_type'  => 'fallback',
            'confidence'   => 0.0,
            'reason'       => $reason,
            'answer_mode'       => 'fact',
            'resolved_question' => $message ?? '',
            'scope'             => 'all_records',
            'metric'            => null,
        ];
    }
}