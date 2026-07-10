<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class ChatUnderstandService
{
    public function understand(string $message, array $session_meta, array $history): array
    {
        $base_url = rtrim(config('ai.base_url'), '/');

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-AI-SECRET'  => config('ai.secret'),
                ])
                ->post($base_url . '/api/ai/chat/understand', [
                    'message'      => $message,
                    'session_meta' => $session_meta,
                    'history'      => $history,
                ]);

            if ($response->failed()) {
                return $this->fallback_understand();
            }

            return $response->json() ?: $this->fallback_understand();
        } catch (\Exception $e) {
            return $this->fallback_understand();
        }
    }

    private function fallback_understand(): array
    {
        return [
            'language'             => 'en',
            'message_kind'         => 'unclear',
            'capability_family'    => 'unknown',
            'user_goal'            => '',
            'needs_private_data'   => false,
            'needs_static_knowledge' => false,
            'is_context_switch'    => false,
            'is_correction'        => false,
            'is_exit'              => false,
            'entities'             => [],
            'references'           => ['none'],
            'required_slots'       => [],
            'missing_slots'        => [],
            'confidence'           => 0.0,
            'clarification_question' => null,
            'reason'               => 'fallback',
        ];
    }
}
