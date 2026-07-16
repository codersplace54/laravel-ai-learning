<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatUnderstandService
{
    public function understand(string $message, array $session_meta, array $history): array
    {
        $base_url = rtrim((string) config('ai.base_url'), '/');

        try {
            $response = Http::timeout(35)
                ->connectTimeout(10)
                ->withHeaders([
                    'Accept'      => 'application/json',
                    'Content-Type'=> 'application/json',
                    'X-AI-SECRET' => config('ai.secret'),
                ])
                ->post($base_url . '/api/ai/chat/understand', [
                    'message'      => $message,
                    'session_meta' => $session_meta,
                    'history'      => $history,
                ]);

            if ($response->failed()) {
                Log::channel('ai_chat')->warning('AI understand failed HTTP response', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                if ($response->status() === 429) {
                    return $this->fallback_understand('rate_limit', $session_meta);
                }

                return $this->fallback_understand('AI understand HTTP failed', $session_meta);
            }

            $json = $response->json();

            if (!is_array($json)) {
                return $this->fallback_understand('AI understand returned invalid JSON', $session_meta);
            }

            $data = $this->extract_payload($json);

            return $this->normalize_understand($data);

        } catch (Throwable $e) {
            Log::channel('ai_chat')->warning('AI understand exception', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallback_understand('AI understand exception', $session_meta);
        }
    }

    private function extract_payload(array $json): array
    {
        // Supports both:
        // 1. direct FastAPI response: { route: "...", query_focus: "..." }
        // 2. wrapped response: { status: true, data: { route: "..."} }

        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    private function normalize_understand(array $data): array
    {
        $allowed_routes = [
            'greeting',
            'capabilities',
            'account',
            'application_single',
            'application_collection',
            'service',
            'clarification',
            'exit',
            'unknown',
        ];

        $allowed_families = [
            'application_lifecycle',
            'payment',
            'certificate',
            'documents',
            'service_discovery',
            'eligibility',
            'renewal',
            'notifications',
            'grievance_support',
            'general_knowledge',
            'smalltalk_or_help',
            'unknown',
        ];

        $allowed_kinds = [
            'new_question',
            'follow_up',
            'correction',
            'exit',
            'greeting',
            'unclear',
        ];

        $route = $data['route'] ?? 'unknown';
        $family = $data['capability_family'] ?? 'unknown';
        $kind = $data['message_kind'] ?? 'unclear';

        if (!in_array($route, $allowed_routes, true)) {
            $route = 'unknown';
        }

        if (!in_array($family, $allowed_families, true)) {
            $family = 'unknown';
        }

        if (!in_array($kind, $allowed_kinds, true)) {
            $kind = 'unclear';
        }

        $confidence = (float) ($data['confidence'] ?? 0.7);
        $confidence = max(0, min(1, $confidence));

        $entities = $data['entities'] ?? [];
        $references = $data['references'] ?? ['none'];
        $filters = $data['filters'] ?? [];
        $required_slots = $data['required_slots'] ?? [];
        $missing_slots = $data['missing_slots'] ?? [];

        return [
            'language'               => $data['language'] ?? 'en',
            'message_kind'           => $kind,
            'route'                  => $route,
            'query_focus'            => $data['query_focus'] ?? 'general',
            'answer_mode' => $data['answer_mode'] ?? 'fact',

            'resolved_question' => $data['resolved_question']
                ?? $data['user_goal']
                ?? '',
            'capability_family'      => $family,
            'user_goal'              => $data['user_goal'] ?? '',

            'needs_private_data'     => (bool) ($data['needs_private_data'] ?? false),
            'needs_static_knowledge' => (bool) ($data['needs_static_knowledge'] ?? false),
            'needs_selection'        => (bool) ($data['needs_selection'] ?? false),
            'selection_type'         => $data['selection_type'] ?? null,

            'is_context_switch'      => (bool) ($data['is_context_switch'] ?? false),
            'is_correction'          => (bool) ($data['is_correction'] ?? false),
            'is_exit'                => (bool) ($data['is_exit'] ?? false),

            'entities'               => is_array($entities) ? $entities : [],
            'references'             => is_array($references) ? $references : ['none'],
            'filters'                => is_array($filters) ? $filters : [],
            'required_slots'         => is_array($required_slots) ? $required_slots : [],
            'missing_slots'          => is_array($missing_slots) ? $missing_slots : [],

            'confidence'             => $confidence,
            'clarification_question' => $data['clarification_question'] ?? null,
            'reason'                 => $data['reason'] ?? 'normalized',
        ];
    }

    private function fallback_understand(string $reason = 'fallback', array $session_meta = []): array
    {
        if ($reason === 'rate_limit') {
            $clarification = 'The AI service is temporarily busy. Please wait a moment and try again.';
        } elseif (!empty($session_meta['active_service_id'])) {
            // Service context exists — route to service so conversation continues normally.
            Log::channel('ai_chat')->info('understand_context_fallback', [
                'route'      => 'service',
                'service_id' => $session_meta['active_service_id'],
                'reason'     => $reason,
            ]);

            return [
                'language'               => 'en',
                'message_kind'           => 'follow_up',
                'route'                  => 'service',
                'query_focus'            => 'service_info',
                'answer_mode'            => 'fact',
                'resolved_question'      => '',
                'scope'                  => 'active_service',
                'metric'                 => null,
                'capability_family'      => 'service_discovery',
                'user_goal'              => '',
                'needs_private_data'     => false,
                'needs_static_knowledge' => true,
                'needs_selection'        => false,
                'selection_type'         => null,
                'is_context_switch'      => false,
                'is_correction'          => false,
                'is_exit'                => false,
                'entities'               => [],
                'references'             => ['active_service'],
                'filters'                => [],
                'required_slots'         => [],
                'missing_slots'          => [],
                'confidence'             => 0.6,
                'clarification_question' => null,
                'reason'                 => $reason,
            ];
        } elseif (!empty($session_meta['active_application_id'])) {
            // Application context exists — route to application_single.
            Log::channel('ai_chat')->info('understand_context_fallback', [
                'route'          => 'application_single',
                'application_id' => $session_meta['active_application_id'],
                'reason'         => $reason,
            ]);

            return [
                'language'               => 'en',
                'message_kind'           => 'follow_up',
                'route'                  => 'application_single',
                'query_focus'            => 'application_detail',
                'answer_mode'            => 'fact',
                'resolved_question'      => '',
                'scope'                  => 'active_application',
                'metric'                 => null,
                'capability_family'      => 'application_lifecycle',
                'user_goal'              => '',
                'needs_private_data'     => true,
                'needs_static_knowledge' => false,
                'needs_selection'        => false,
                'selection_type'         => null,
                'is_context_switch'      => false,
                'is_correction'          => false,
                'is_exit'                => false,
                'entities'               => [],
                'references'             => ['active_application'],
                'filters'                => [],
                'required_slots'         => [],
                'missing_slots'          => [],
                'confidence'             => 0.6,
                'clarification_question' => null,
                'reason'                 => $reason,
            ];
        } else {
            $clarification = 'I am temporarily unable to connect to the AI service. Please try again in a moment.';
        }

        return [
            'language'               => 'mixed',
            'message_kind'           => 'unclear',
            'route'                  => 'clarification',
            'query_focus'            => 'clarification',
            'capability_family'      => 'unknown',
            'user_goal'              => '',
            'answer_mode'            => 'fact',
            'resolved_question'      => '',
            'needs_private_data'     => false,
            'needs_static_knowledge' => false,
            'needs_selection'        => false,
            'selection_type'         => null,
            'is_context_switch'      => false,
            'is_correction'          => false,
            'is_exit'                => false,
            'entities'               => [],
            'references'             => ['none'],
            'filters'                => [],
            'required_slots'         => [],
            'missing_slots'          => [],
            'confidence'             => 0.0,
            'clarification_question' => $clarification,
            'reason'                 => $reason,
        ];
    }
}