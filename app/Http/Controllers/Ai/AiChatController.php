<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiChatMessage;
use App\Models\AiChatSession;
use App\Models\ServiceMaster;
use App\Models\UserServiceApplication;
use App\Services\Ai\ChatAnswerService;
use App\Services\Ai\ChatLiveDataService;
use App\Services\Ai\ChatUnderstandService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Services\Ai\ApplicationCollectionQueryService;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    public function __construct(
        private ChatUnderstandService             $understand_service,
        private ChatLiveDataService               $live_data,
        private ChatAnswerService                 $answer_service,
        private ApplicationCollectionQueryService $application_collection_query_service,
    ) {}

    // ---------------------------------------------------------------------
    // CHAT
    // ---------------------------------------------------------------------

    public function chat(Request $request)
    {
        $request->validate([
            'session_id' => 'nullable|integer',
            'message' => 'required|string|max:1500',
            'application_id' => 'nullable|integer',
            'service_id' => 'nullable|integer',
        ]);

        $user_id = $this->get_user_id();
        $message = trim((string) $request->input('message'));
        $session = $this->get_or_create_session($request, $user_id);

        $this->save_message($session, 'user', $message);

        if ($request->filled('application_id')) {
            return $this->handle_application_selection($session, (int) $request->input('application_id'), $message);
        }

        if ($request->filled('service_id')) {
            return $this->handle_service_selection($session, (int) $request->input('service_id'), $message);
        }

        $history = $this->load_history($session, 10);
        $session_meta = $this->build_session_meta($session);

        $understanding = $this->safe_understand($message, $session_meta, $history);
        $plan = $this->make_plan($understanding, $session);
        Log::channel('ai_chat')->info('AI Plan: ' . json_encode($plan, JSON_PRETTY_PRINT));
        if ($plan['route'] === 'exit') {
            return $this->handle_exit($session);
        }

        if ($plan['route'] === 'greeting') {
            return $this->answer_greeting($session);
        }

        if ($plan['route'] === 'capabilities') {
            if ($plan['query_focus'] === 'out_of_scope') {
                return $this->reply(
                    $session,
                    "Sorry, I'm not able to answer this question. I can help with queries related to SWAAGAT applications, payments, certificates, documents, and services.",
                    'out_of_scope',
                    [
                        'Show my applications',
                        'What is my application status?',
                    ]
                );
            }

            return $this->answer_capabilities($session);
        }

        if ($plan['route'] === 'clarification') {
            return $this->ask_clarification(
                $session,
                $plan['clarification_question'] ?: 'Please clarify if your question is about an application or a service.'
            );
        }

        $route = $plan['route'] ?? null;

        if (in_array($route, [
            'application_collection',
            'account',
        ], true)) {
            $this->clear_pending($session);
        }
        
        return match ($plan['route']) {
            'application_single' => $this->handle_application_single($session, $message, $plan),
            'application_collection' => $this->handle_application_collection($session, $message, $plan),
            'service' => $this->handle_service($session, $message, $plan),
            'account' => $this->handle_account($session, $message, $plan),
            'service_discovery' => $this->handle_service_discovery($session, $message, $plan),
            default => $this->handle_unknown($session, $message, $plan),
        };
    }

    // ---------------------------------------------------------------------
    // AI UNDERSTANDING + PLAN
    // ---------------------------------------------------------------------

    private function safe_understand(
        string $message,
        array $session_meta,
        array $history
    ): array {
        $request_id = (string) Str::uuid();

        Log::channel('ai_chat')->info('SWAAGAT understand request starting', [
            'request_id' => $request_id,
            'message' => $message,
            'session_meta' => $session_meta,
            'history_count' => count($history),
        ]);

        try {
            $understanding = $this->understand_service->understand(
                $message,
                $session_meta,
                $history
            );

            if (!is_array($understanding)) {
                throw new \RuntimeException(
                    'Understand service returned '
                        . get_debug_type($understanding)
                        . ' instead of an array.'
                );
            }

            if (empty($understanding['route'])) {
                throw new \RuntimeException(
                    'Understand service response does not contain route. Response: '
                        . json_encode($understanding)
                );
            }

            Log::channel('ai_chat')->info('SWAAGAT understand request completed', [
                'request_id' => $request_id,
                'route' => $understanding['route'] ?? null,
                'query_focus' => $understanding['query_focus'] ?? null,
                'answer_mode' => $understanding['answer_mode'] ?? null,
            ]);

            return $understanding;
        } catch (Throwable $e) {
            
            Log::channel('ai_chat')->error('SWAAGAT AI understand failed', [
                'request_id' => $request_id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'message' => $message,
                'trace' => $e->getTraceAsString(),
            ]);

            /*
         * Send the exception to Laravel's configured exception reporter too.
         */
            report($e);

            /*
         * Infrastructure fallback for basic greetings.
         * This is not business-question keyword routing.
         */
            if (
                preg_match(
                    '/^\s*(hi|hello|hey|good\s+morning|good\s+afternoon|good\s+evening)\b[!,.?\s]*$/i',
                    $message
                )
            ) {
                return [
                    'route' => 'greeting',
                    'query_focus' => 'greeting',
                    'answer_mode' => 'fact',
                    'resolved_question' => $message,
                    'scope' => 'all_records',
                    'metric' => null,
                    'message_kind' => 'greeting',
                    'capability_family' => 'smalltalk_or_help',
                    'user_goal' => 'greet',
                    'needs_private_data' => false,
                    'needs_static_knowledge' => false,
                    'references' => ['none'],
                    'entities' => [],
                    'filters' => [],
                    'confidence' => 1,
                    'clarification_question' => null,
                    'is_exit' => false,
                ];
            }

            /*
         * Never return an unrelated application/service clarification
         * when the actual problem is AI-service connectivity.
         */
            return [
                'route' => 'clarification',
                'query_focus' => 'ai_service_unavailable',
                'answer_mode' => 'fact',
                'resolved_question' => $message,
                'scope' => 'all_records',
                'metric' => null,
                'message_kind' => 'unclear',
                'capability_family' => 'unknown',
                'user_goal' => 'understand service unavailable',
                'needs_private_data' => false,
                'needs_static_knowledge' => false,
                'references' => ['none'],
                'entities' => [],
                'filters' => [],
                'confidence' => 1,
                'clarification_question' =>
                'I am temporarily unable to connect to the AI understanding service. Please try again in a moment.',
                'is_exit' => false,
            ];
        }
    }

    private function make_plan(array $u, AiChatSession $session): array
    {
        $raw_route = strtolower((string) ($u['route'] ?? $u['operation'] ?? ''));
        $family = strtolower((string) ($u['capability_family'] ?? 'unknown'));
        $kind = strtolower((string) ($u['message_kind'] ?? 'new_question'));
        $focus = strtolower((string) ($u['query_focus'] ?? $u['operation'] ?? $u['user_goal'] ?? 'general'));
        $refs = $u['references'] ?? ['none'];
        $confidence = (float) ($u['confidence'] ?? 0.7);

        $route = $this->normalize_route($raw_route, $family, $kind, $focus, $refs, $session);

        if (!empty($u['is_exit'])) {
            $route = 'exit';
        }

        if ($confidence < 0.45 && !empty($u['clarification_question'])) {
            $route = 'clarification';
        }

        return [
            'route' => $route,
            'query_focus' => $focus,
            'answer_mode' => $u['answer_mode'] ?? 'fact',

            'resolved_question' => $u['resolved_question']
                ?? $u['user_goal']
                ?? '',
            'scope' => $u['scope'] ?? 'all_records',

            'metric' => $u['metric'] ?? null,
            'user_goal' => $u['user_goal'] ?? '',
            'message_kind' => $kind,
            'capability_family' => $family,
            'references' => is_array($refs) ? $refs : ['none'],
            'entities' => is_array($u['entities'] ?? null) ? $u['entities'] : [],
            'filters' => is_array($u['filters'] ?? null) ? $u['filters'] : [],
            'needs_selection' => (bool) ($u['needs_selection'] ?? false),
            'selection_type' => $u['selection_type'] ?? null,
            'required_slots' => is_array($u['required_slots'] ?? null)
                ? $u['required_slots']
                : [],
            'missing_slots' => is_array($u['missing_slots'] ?? null)
                ? $u['missing_slots']
                : [],
            'clarification_question' => $u['clarification_question'] ?? null,
            'is_context_switch' => (bool) ($u['is_context_switch'] ?? false),
            'is_correction' => (bool) ($u['is_correction'] ?? false),
            'confidence' => $confidence,
            'raw' => $u,
        ];
    }

    private function normalize_route(
        string $raw_route,
        string $family,
        string $kind,
        string $focus,
        array $refs,
        AiChatSession $session
    ): string {
        $direct = [
            'greeting' => 'greeting',
            'capabilities' => 'capabilities',
            'help' => 'capabilities',
            'exit' => 'exit',
            'clarification' => 'clarification',
            'account' => 'account',
            'account_answer' => 'account',

            'application_single' => 'application_single',
            'application_single_answer' => 'application_single',
            'application_detail' => 'application_single',
            'application_detail_status' => 'application_single',
            'application_status' => 'application_single',
            'application_stuck_reason' => 'application_single',
            'application_next_action' => 'application_single',
            'payment_status' => 'application_single',
            'certificate_status' => 'application_single',
            'application_timeline' => 'application_single',
            'application_history' => 'application_single',
            'application_documents' => 'application_single',
            'application_receipt' => 'application_single',
            'application_verification' => 'application_single',
            'application_correction' => 'application_single',
            'application_cancel' => 'application_single',
            'application_grievance' => 'application_single',

            'application_collection' => 'application_collection',
            'application_collection_answer' => 'application_collection',
            'application_count' => 'application_collection',
            'application_list' => 'application_collection',
            'application_filter' => 'application_collection',
            'latest_application' => 'application_collection',
            'duplicate_applications' => 'application_collection',

            'service_discovery' => 'service_discovery',
            'service_recommendation' => 'service_discovery',
            'service_selection_help' => 'service_discovery',
            'service' => 'service',
            'service_answer' => 'service',
            'service_info' => 'service',
            'documents_for_service' => 'service',
            'service_documents' => 'service',
            'service_processing_time' => 'service',
            'service_eligibility' => 'service',
            'service_fee' => 'service',
        ];

        if (isset($direct[$raw_route])) {
            return $direct[$raw_route];
        }

        if ($kind === 'greeting') {
            return 'greeting';
        }

        if ($family === 'smalltalk_or_help') {
            return $this->bool_from_focus($focus, ['account', 'username', 'email', 'mobile'])
                ? 'account'
                : 'capabilities';
        }

        if ($family === 'service_discovery') {
            return 'service_discovery';
        }

        if (in_array($family, [
            'documents',
            'eligibility',
            'general_knowledge',
        ], true)) {
            return 'service';
        }

        if (in_array($family, ['application_lifecycle', 'payment', 'certificate', 'renewal', 'notifications', 'grievance_support'], true)) {
            if ($this->looks_like_collection_focus($focus)) {
                return 'application_collection';
            }

            return 'application_single';
        }

        $pending = $this->get_meta($session, 'pending_plan');

        if (is_array($pending) && in_array('pending_plan', $refs, true)) {
            return $pending['route'] ?? 'clarification';
        }

        return 'clarification';
    }

    private function looks_like_collection_focus(string $focus): bool
    {
        return $this->bool_from_focus($focus, [
            'count',
            'total',
            'list',
            'all applications',
            'filter',
            'latest',
            'duplicate',
            'multiple applications',
        ]);
    }

    private function bool_from_focus(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // APPLICATION SINGLE
    // ---------------------------------------------------------------------

    private function handle_application_single(AiChatSession $session, string $message, array $plan)
    {
        $resolved = $this->resolve_application_id($session, $message, $plan);

        if ($resolved['explicit_not_found']) {
            $typed = $resolved['typed_number'] ?: 'that application number';

            return $this->reply(
                $session,
                "I could not find application **{$typed}** in your account. Please check the number or choose from your application list.",
                'application_not_found',
                ['Show my applications']
            );
        }

        if (!$resolved['id']) {
            $this->set_pending_plan($session, [
                'route' => 'application_single',
                'query_focus' => $plan['query_focus'] ?? 'application_detail',
                'original_message' => $message,
                'selection_type' => 'application',
            ]);

            return $this->ask_application_selection($session);
        }

        $this->clear_pending($session);

        return $this->answer_with_application($session, (int) $resolved['id'], $message, $plan);
    }

    private function answer_with_application(AiChatSession $session, int $application_id, string $message, array $plan)
    {
        $context = $this->live_data->fetch_application_context($application_id, $session->user_id);

        if (!$context) {
            return $this->reply($session, 'I could not find that application in your account.', 'application_not_found', []);
        }

        $app_number = $this->first_value($context, [
            'application.application_number',
            'application.applicationId',
            'application.application_id',
        ]);

        $service_id = $this->first_value($context, [
            'application.service_id',
            'service.id',
        ]);

        $this->set_active_application($session, $application_id, $app_number, $service_id, $plan['query_focus'] ?? 'application');

        $ai = $this->safe_application_answer($message, $context, $plan);

        return $this->reply(
            $session,
            $ai['answer'],
            $ai['answer_type'] ?? 'application',
            [
                'Show the complete history of my application',
                'What stage is my application currently in?',
                'How much did I pay?',
                'Can i renew?',
            ],
            $ai['short_status'] ?? null,
            $ai['waiting_on'] ?? null,
            $ai['next_action'] ?? null
        );
    }

    private function safe_application_answer(string $message, array $context, array $plan): array
    {
        try {
            $context['_ai_plan'] = [
                'route' => 'application_single',
                'query_focus' => $plan['query_focus'] ?? null,
                'user_goal' => $plan['user_goal'] ?? null,
            ];

            $ai = $this->answer_service->generate($message, 'APPLICATION_DATA', $context);

            if (is_array($ai) && !empty($ai['answer'])) {
                return $ai;
            }
        } catch (Throwable $e) {
            Log::channel('ai_chat')->warning('SWAAGAT application answer failed', [
                'error' => $e->getMessage(),
                'message' => $message,
            ]);
        }

        return [
            'answer' => $this->local_application_fallback($context),
            'answer_type' => 'application_fallback',
            'short_status' => $this->first_value($context, ['application.status', 'status']),
        ];
    }

    private function local_application_fallback(array $context): string
    {
        $number = $this->first_value($context, ['application.application_number', 'application.applicationId'], 'your application');
        $service = $this->first_value($context, ['application.service_name', 'service.service_name', 'service.name'], 'the selected service');
        $status = $this->first_value($context, ['application.status', 'status'], 'not available');
        $payment = $this->first_value($context, ['application.payment_status', 'payment.status'], null);
        $updated = $this->first_value($context, ['application.updated_at', 'updated_at', 'last_update'], null);

        $lines = [];
        $lines[] = "I found **{$number}** for **{$service}**.";
        $lines[] = "Current status: **{$status}**.";

        if ($payment) {
            $lines[] = "Payment status: **{$payment}**.";
        }

        if ($updated) {
            $lines[] = "Last update: **{$updated}**.";
        }

        $lines[] = 'I could not generate the detailed AI reply right now, but these details are from your live application data.';

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------------
    // APPLICATION COLLECTION
    // ---------------------------------------------------------------------

    private function handle_application_collection(
        AiChatSession $session,
        string $message,
        array $plan
    ) {
        /*
     * Previous collection is used for follow-ups like:
     * "Are these all expired?"
     */
        $last_collection = $this->get_meta(
            $session,
            'last_collection',
            []
        );

        if (!is_array($last_collection)) {
            $last_collection = [];
        }

        /*
     * PHP performs the authoritative database work:
     * filters, counts, totals, all-match checks, etc.
     */
        $result = $this->application_collection_query_service->execute(
            (int) $session->user_id,
            $plan,
            $last_collection
        );

        /*
     * Save collection memory for future follow-ups.
     */
        if (
            array_key_exists('last_collection', $result)
            && is_array($result['last_collection'])
        ) {
            $meta = $this->meta($session);
            $meta['last_collection'] = $result['last_collection'];

            $session->meta = $meta;
            $session->save();
        }

        /*
     * Use the complete resolved question, not vague raw wording.
     */
        $question = trim(
            (string) ($plan['resolved_question'] ?? '')
        );

        if ($question === '') {
            $question = $message;
        }

        /*
     * Send the verified PHP result to Python only for explanation.
     */
        $context = [
            '_ai_plan' => [
                'route' => 'application_collection',
                'query_focus' => $plan['query_focus'] ?? null,
                'answer_mode' => $plan['answer_mode'] ?? 'list',
                'scope' => $plan['scope'] ?? 'all_records',
                'metric' => $plan['metric'] ?? null,
                'filters' => $plan['filters'] ?? [],
                'resolved_question' => $question,
            ],

            /*
         * Python must treat this result as authoritative.
         */
            'query_result' => $result,
        ];

        /*
     * PHP message remains the fallback when Python is unavailable.
     */
        $fallback = $result['message']
            ?? 'I could not prepare a reliable answer from the available application data.';

        $ai = $this->safe_generic_answer(
            $question,
            'APPLICATION_COLLECTION_DATA',
            $context,
            $fallback
        );

        $options = is_array($result['options'] ?? null)
            ? $result['options']
            : [];

        return $this->reply(
            $session,
            $ai['answer'] ?? $fallback,
            $ai['answer_type'] ?? 'application_collection',
            [],
            $ai['short_status'] ?? null,
            $ai['waiting_on'] ?? null,
            $ai['next_action'] ?? null,
            false,
            null,
            $options
        );
    }

    // ---------------------------------------------------------------------
    // SERVICE DISCOVERY
    // ---------------------------------------------------------------------

    private function handle_service_discovery(
        AiChatSession $session,
        string $message,
        array $plan
    ) {
        $message_kind = strtolower(
            trim((string) ($plan['message_kind'] ?? 'new_question'))
        );

        $is_context_switch = (bool) (
            $plan['is_context_switch']
            ?? $plan['raw']['is_context_switch']
            ?? false
        );

        $pending_plan = $this->get_meta(
            $session,
            'pending_plan',
            []
        );

        if (!is_array($pending_plan)) {
            $pending_plan = [];
        }

        $is_discovery_follow_up = (
            $message_kind === 'follow_up'
            && !$is_context_switch
            && ($pending_plan['route'] ?? null) === 'service_discovery'
        );

        if (!$is_discovery_follow_up) {
            $pending_plan = [];
        }

        $clarification_count = (int) (
            $pending_plan['clarification_count']
            ?? 0
        );

        $question = trim(
            (string) (
                $plan['resolved_question']
                ?? $message
            )
        );

        if ($question === '') {
            $question = $message;
        }

        // A genuine follow-up must retain the complete original requirement.
        // This is based on the semantic planner result, not fixed phrases.
        if ($is_discovery_follow_up) {
            $original_message = trim(
                (string) (
                    $pending_plan['original_message']
                    ?? ''
                )
            );

            if ($original_message !== '') {
                $question = $original_message
                    . "\nAdditional details: "
                    . $message;
            }
        }

        // Service-discovery clarification is handled only after RAG retrieval.
        // The understanding stage only classifies and consolidates context.

        $context = [
            '_ai_plan' => [
                'route' => 'service_discovery',
                'query_focus' => $plan['query_focus']
                    ?? 'service_recommendation',
                'answer_mode' => $plan['answer_mode']
                    ?? 'recommendation',
                'resolved_question' => $question,
                'user_goal' => $plan['user_goal'] ?? null,
                'message_kind' => $message_kind,
                'is_context_switch' => $is_context_switch,
                'clarification_already_asked' => $clarification_count >= 1,
                'filters' => $plan['filters'] ?? [],
            ],
        ];

        // Send the exact latest message separately. The complete consolidated
        // requirement is already available in context.resolved_question.
        $ai = $this->safe_generic_answer(
            $message,
            'SERVICE_DISCOVERY',
            $context,
            'I could not complete the service search right now. Please try again after a moment.'
        );

        $raw_candidates = is_array(
            $ai['candidate_services'] ?? null
        )
            ? $ai['candidate_services']
            : [];

        $candidate_reasons = [];

        foreach ($raw_candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $service_id = (int) (
                $candidate['service_id']
                ?? $candidate['id']
                ?? 0
            );

            if ($service_id <= 0) {
                continue;
            }

            $candidate_reasons[$service_id] = trim(
                (string) ($candidate['reason'] ?? '')
            );

            if (count($candidate_reasons) >= 3) {
                break;
            }
        }

        $services = empty($candidate_reasons)
            ? collect()
            : ServiceMaster::query()
                ->whereIn('id', array_keys($candidate_reasons))
                ->get([
                    'id',
                    'service_title_or_description',
                ])
                ->keyBy('id');

        $options = [];

        foreach ($candidate_reasons as $service_id => $reason) {
            $service = $services->get($service_id);

            if (!$service) {
                continue;
            }

            $options[] = [
                'id' => (int) $service->id,
                'title' => $service->service_title_or_description,
                'subtitle' => $reason !== ''
                    ? $reason
                    : 'This service may match your requirement.',
            ];
        }

        $needs_clarification = (bool) (
            $ai['needs_clarification']
            ?? false
        );

        $final_clarification = trim(
            (string) (
                $ai['clarification_question']
                ?? ''
            )
        );

        if ($clarification_count >= 1) {
            $needs_clarification = false;
            $final_clarification = '';
        }

        if (
            $needs_clarification
            && $final_clarification !== ''
        ) {
            $this->set_pending_plan(
                $session,
                [
                    'route' => 'service_discovery',
                    'query_focus' => 'service_recommendation',
                    'answer_mode' => 'recommendation',
                    'original_message' => $question,
                    'clarification_question' => $final_clarification,
                    'clarification_count' => 1,
                ]
            );

            return $this->ask_clarification(
                $session,
                $final_clarification
            );
        }

        // Build the visible answer only from ServiceMaster records.
        // IDs remain internal and service titles stay exactly as configured.
        if (count($options) === 1) {
            $answer = 'The matching SWAAGAT service is **'
                . $options[0]['title']
                . '**.';
        } elseif (count($options) > 1) {
            $lines = [
                'These SWAAGAT services match the requirements you described:',
            ];

            foreach ($options as $index => $option) {
                $lines[] = ($index + 1)
                    . '. **'
                    . $option['title']
                    . '**';
            }

            $answer = implode("\n", $lines);
        } else {
            $answer = trim((string) ($ai['answer'] ?? ''));

            if ($answer === '') {
                $answer = 'I could not identify a verified SWAAGAT service that directly matches the requirement from the available guidance.';
            }
        }

        $needs_service_selection = count($options) > 1;

        if (count($options) === 1) {
            // A single verified discovery result is already selected.
            // Persist it so follow-up questions such as "what is its fee?"
            // can use the active service without asking for the name again.
            $this->set_active_service(
                $session,
                (int) $options[0]['id'],
                (string) $options[0]['title'],
                'service_discovery'
            );

            $this->clear_pending($session);
        } elseif ($needs_service_selection) {
            // Multiple valid services require an explicit user selection.
            $this->set_pending_plan(
                $session,
                [
                    'route' => 'service',
                    'query_focus' => 'service_info',
                    'user_goal' => 'select one of the matched services',
                    'original_message' => $question,
                    'selection_type' => 'service',
                    'options' => $options,
                ]
            );
        } else {
            $this->clear_pending($session);
        }

        return $this->reply(
            $session,
            $answer,
            'service_discovery',
            [],
            null,
            null,
            null,
            $needs_service_selection,
            $needs_service_selection ? 'service' : null,
            $options
        );
    }

    // ---------------------------------------------------------------------
    // SERVICE
    // ---------------------------------------------------------------------

    private function handle_service(AiChatSession $session, string $message, array $plan)
    {
        $service_id = $this->resolve_service_id($session, $message, $plan);

        if (!$service_id) {
            $service_text = $this->service_text_from_entities(
                $plan['entities'] ?? []
            );

            // Entity extraction may miss an explicitly typed service name.
            // In that case, resolve the user's actual message as a fallback.
            $texts_to_resolve = array_values(
                array_unique(
                    array_filter(
                        [
                            $service_text,
                            trim($message),
                            trim((string) ($plan['resolved_question'] ?? '')),
                        ],
                        fn($value) => is_string($value) && $value !== ''
                    )
                )
            );

            foreach ($texts_to_resolve as $text_to_resolve) {
                $resolved = $this->live_data->resolve_service_by_name(
                    $text_to_resolve
                );

                if (($resolved['status'] ?? null) === 'found') {
                    $service_id = (int) $resolved['service_id'];
                    break;
                }

                if (($resolved['status'] ?? null) === 'multiple') {
                    $this->set_pending_plan(
                        $session,
                        [
                            'route' => 'service',
                            'query_focus' => $plan['query_focus']
                                ?? 'service_info',
                            'original_message' => $message,
                            'selection_type' => 'service',
                        ]
                    );

                    return $this->ask_service_selection(
                        $session,
                        $resolved['options'] ?? []
                    );
                }
            }
        }

        if (!$service_id) {
            $this->set_pending_plan($session, [
                'route' => 'service',
                'query_focus' => $plan['query_focus'] ?? 'service_info',
                'original_message' => $message,
                'selection_type' => 'service',
            ]);

            return $this->reply(
                $session,
                'Please tell me the service name. Example: professional tax, factory license, trade license.',
                'service_need_name',
                [],
                null,
                null,
                null,
                true,
                'service',
                []
            );
        }

        $this->clear_pending($session);

        return $this->answer_with_service($session, (int) $service_id, $message, $plan);
    }

    private function answer_with_service(AiChatSession $session, int $service_id, string $message, array $plan)
    {
        $context = $this->live_data->fetch_service_document_context($service_id);

        if (!$context) {
            return $this->reply($session, 'I could not find that service.', 'service_not_found', []);
        }

        $service_name = $context['service_name'] ?? $context['service']['service_name'] ?? $context['service']['name'] ?? null;

        $this->set_active_service($session, $service_id, $service_name, $plan['query_focus'] ?? 'service');

        $context['_ai_plan'] = [
            'route' => 'service',
            'query_focus' => $plan['query_focus'] ?? null,
            'answer_mode' => $plan['answer_mode'] ?? 'fact',
            'resolved_question' => trim((string) ($plan['resolved_question'] ?? '')) ?: $message,
            'user_goal' => $plan['user_goal'] ?? null,
            'filters' => $plan['filters'] ?? [],
        ];

        $answer_question = trim(
            (string) (
                $context['_ai_plan']['resolved_question']
                ?? ''
            )
        ) ?: $message;

        Log::channel('ai_chat')->info('SWAAGAT service RAG context', [
            'service_id'        => $service_id,
            'resolved_question' => $plan['resolved_question'] ?? $message,
            'query_focus'       => $plan['query_focus'] ?? null,
        ]);

        $ai = $this->safe_generic_answer(
            $answer_question,
            'SERVICE_DATA',
            $context,
            $this->local_service_fallback($context)
        );

        return $this->reply(
            $session,
            $ai['answer'],
            $ai['answer_type'] ?? 'service',
            [
                'What is the processing time for this service?',
                'What is the fee for this service?',
                'What documents are required?',
                'What are the eligibility criteria?',
            ],
            $ai['short_status'] ?? $service_name
        );
    }

    private function local_service_fallback(array $context): string
    {
        return 'I am temporarily unable to connect to the AI service. Please try again in a moment.';
    }

    private function safe_generic_answer(string $message, string $scope, array $context, string $fallback): array
    {
        try {
            $ai = $this->answer_service->generate($message, $scope, $context);

            if (is_array($ai) && !empty($ai['answer'])) {
                return $ai;
            }
        } catch (Throwable $e) {
            Log::channel('ai_chat')->warning('SWAAGAT AI answer failed', [
                'scope' => $scope,
                'error' => $e->getMessage(),
                'message' => $message,
            ]);
        }

        return [
            'answer' => $fallback,
            'answer_type' => strtolower($scope) . '_fallback',
            'short_status' => null,
        ];
    }

    // ---------------------------------------------------------------------
    // ACCOUNT
    // ---------------------------------------------------------------------

    private function handle_account(AiChatSession $session, string $message, array $plan)
    {
        try {
            $context = $this->live_data->fetch_account_context($session->user_id);
        } catch (Throwable $e) {
            Log::channel('ai_chat')->warning('SWAAGAT account context failed', [
                'error' => $e->getMessage(),
            ]);

            $context = null;
        }

        if (!$context) {
            return $this->reply($session, 'I could not fetch your account details right now.', 'account', []);
        }

        $ai = $this->safe_generic_answer(
            $message,
            'ACCOUNT_DATA',
            ['account' => $context],
            'I found your account, but I could not prepare the detailed reply right now.'
        );

        return $this->reply($session, $ai['answer'], $ai['answer_type'] ?? 'account', [
            'Show my applications',
            'Mera application number kya hai?',
        ]);
    }

    // ---------------------------------------------------------------------
    // SELECTION HANDLERS
    // ---------------------------------------------------------------------

    private function handle_application_selection(AiChatSession $session, int $application_id, string $message)
    {
        $pending = $this->get_meta($session, 'pending_plan', []);
        $original_message = $pending['original_message'] ?? $message;

        $plan = [
            'route' => 'application_single',
            'query_focus' => $pending['query_focus'] ?? 'application_detail',
            'user_goal' => $pending['user_goal'] ?? '',
            'message_kind' => 'follow_up',
            'references' => ['selected_option'],
            'entities' => [],
            'filters' => [],
        ];

        $this->clear_pending($session);

        return $this->answer_with_application($session, $application_id, $original_message, $plan);
    }

    private function handle_service_selection(AiChatSession $session, int $service_id, string $message)
    {
        $pending = $this->get_meta($session, 'pending_plan', []);
        $original_message = $pending['original_message'] ?? $message;

        $plan = [
            'route' => 'service',
            'query_focus' => $pending['query_focus'] ?? 'service_info',
            'user_goal' => $pending['user_goal'] ?? '',
            'message_kind' => 'follow_up',
            'references' => ['selected_option'],
            'entities' => [],
            'filters' => [],
        ];

        $this->clear_pending($session);

        return $this->answer_with_service($session, $service_id, $original_message, $plan);
    }

    // ---------------------------------------------------------------------
    // SELECTION RESPONSES
    // ---------------------------------------------------------------------

    private function ask_application_selection(AiChatSession $session)
    {
        $applications = $this->live_data->fetch_user_applications($session->user_id);
        $message = 'Please select which application you want to ask about.';

        $this->save_message($session, 'assistant', $message, 'application_selection');

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $message,
                'message' => $message,
                'answer_type' => 'application_selection',
                'requires_selection' => true,
                'selection_type' => 'application',
                'options' => $applications,
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => [],
            ],
        ]);
    }

    private function ask_service_selection(AiChatSession $session, array $options)
    {
        $message = 'I found multiple matching services. Please select the correct one.';

        $this->save_message($session, 'assistant', $message, 'service_selection');

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $message,
                'message' => $message,
                'answer_type' => 'service_selection',
                'requires_selection' => true,
                'selection_type' => 'service',
                'options' => $options,
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => [],
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // ENTITY RESOLUTION
    // ---------------------------------------------------------------------

    private function resolve_application_id(AiChatSession $session, string $message, array $plan): array
    {
        // 1. If user typed a real application number, that has first priority.
        if (preg_match('/\b[A-Z]{2,8}(?:-[A-Z0-9]+){1,6}\b/i', $message, $match)) {
            $app = $this->live_data->resolve_application_by_number($match[0], $session->user_id);

            if ($app) {
                return [
                    'id' => (int) $app->id,
                    'explicit_not_found' => false,
                    'typed_number' => $match[0],
                ];
            }

            return [
                'id' => null,
                'explicit_not_found' => true,
                'typed_number' => $match[0],
            ];
        }

        // 2. If AI provided application id, use it.
        foreach ($plan['entities'] ?? [] as $entity) {
            if (($entity['type'] ?? null) === 'application' && !empty($entity['id'])) {
                return [
                    'id' => (int) $entity['id'],
                    'explicit_not_found' => false,
                    'typed_number' => null,
                ];
            }
        }

        $refs = $plan['references'] ?? [];
        $kind = $plan['message_kind'] ?? '';

        // 3. If AI says this refers to active application, use active_application_id.
        if (
            $session->active_application_id &&
            (
                in_array('active_application', $refs, true) ||
                in_array($kind, ['follow_up', 'correction'], true) ||
                !empty($plan['raw']['is_context_switch'] ?? false)
            )
        ) {
            return [
                'id' => (int) $session->active_application_id,
                'explicit_not_found' => false,
                'typed_number' => null,
            ];
        }

        // 4. Only try entity text if it looks like a real application number.
        // Do NOT try "my application", "this application", etc.
        foreach ($plan['entities'] ?? [] as $entity) {
            if (($entity['type'] ?? null) !== 'application') {
                continue;
            }

            $text = trim((string) ($entity['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if (!preg_match('/\b[A-Z]{2,8}(?:-[A-Z0-9]+){1,6}\b/i', $text, $match)) {
                continue;
            }

            $app = $this->live_data->resolve_application_by_number($match[0], $session->user_id);

            if ($app) {
                return [
                    'id' => (int) $app->id,
                    'explicit_not_found' => false,
                    'typed_number' => null,
                ];
            }

            return [
                'id' => null,
                'explicit_not_found' => true,
                'typed_number' => $match[0],
            ];
        }

        return [
            'id' => null,
            'explicit_not_found' => false,
            'typed_number' => null,
        ];
    }

    private function resolve_service_id(AiChatSession $session, string $message, array $plan): ?int
    {
        $refs = $plan['references'] ?? [];
        $kind = $plan['message_kind'] ?? '';

        if ($session->active_service_id && (
            in_array('active_service', $refs, true) ||
            in_array($kind, ['follow_up', 'correction'], true)
        )) {
            return (int) $session->active_service_id;
        }

        foreach ($plan['entities'] ?? [] as $entity) {
            if (($entity['type'] ?? null) === 'service' && !empty($entity['id'])) {
                return (int) $entity['id'];
            }
        }

        return null;
    }

    private function service_text_from_entities(array $entities): ?string
    {
        foreach ($entities as $entity) {
            if (($entity['type'] ?? null) === 'service') {
                $text = trim((string) ($entity['normalized'] ?? $entity['text'] ?? ''));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // SIMPLE ANSWERS
    // ---------------------------------------------------------------------

    private function answer_greeting(AiChatSession $session)
    {
        return $this->reply($session, 'Hello! I am SWAAGAT AI Assistant. I can help you with your applications and service information. What would you like to know?', 'greeting', [
            'Show my applications',
            'What is my application status?',
            'Documents required for a service',
            'Service processing time batao',
        ]);
    }

    private function answer_capabilities(AiChatSession $session)
    {
        return $this->reply($session, 'I can help with application status, application history, payment, certificate/NOC, uploaded documents, field verification, and service details like documents, eligibility, fees, and processing time.', 'capabilities', [
            'Show my applications',
            'What is the status of my application?',
            'What documents are required?',
            'What is the processing time for this service?',
        ]);
    }

    private function ask_clarification(AiChatSession $session, string $question)
    {
        return $this->reply($session, $question, 'clarification', [
            'Question is related to an application.',
            'Question is related to a service.',
            'Show my applications.',
        ]);
    }

    private function handle_unknown(AiChatSession $session, string $message, array $plan)
    {
        if ($session->active_application_id) {
            $plan['query_focus'] = $plan['query_focus'] ?? 'application_follow_up';
            $plan['references'] = ['active_application'];
            $plan['message_kind'] = 'follow_up';

            return $this->answer_with_application($session, (int) $session->active_application_id, $message, $plan);
        }

        if ($session->active_service_id) {
            $plan['query_focus'] = $plan['query_focus'] ?? 'service_follow_up';
            $plan['references'] = ['active_service'];
            $plan['message_kind'] = 'follow_up';

            return $this->answer_with_service($session, (int) $session->active_service_id, $message, $plan);
        }

        return $this->ask_clarification($session, 'Please tell me if this is about your application or about a service.');
    }

    private function handle_exit(AiChatSession $session)
    {
        $session->active_application_id = null;
        $session->active_service_id = null;
        $session->meta = [];
        $session->save();

        return $this->reply($session, 'Thank you for using SWAAGAT. You can come back anytime if you need help.', 'exit', []);
    }

    // ---------------------------------------------------------------------
    // SESSION STATE
    // ---------------------------------------------------------------------

    private function set_active_application(AiChatSession $session, int $application_id, ?string $application_number, ?int $service_id, string $topic): void
    {
        $meta = $this->meta($session);

        $session->active_application_id = $application_id;

        if ($service_id) {
            $session->active_service_id = $service_id;
            $meta['active_service_id'] = $service_id;
        }

        $meta['active_topic'] = $topic;
        $meta['active_application_id'] = $application_id;
        $meta['active_application_number'] = $application_number;

        $stack = $meta['entity_stack'] ?? [];
        $stack = array_values(array_filter($stack, fn($e) => !(($e['type'] ?? null) === 'application' && (int) ($e['id'] ?? 0) === $application_id)));

        array_unshift($stack, [
            'type' => 'application',
            'id' => $application_id,
            'label' => $application_number,
        ]);

        $meta['entity_stack'] = array_slice($stack, 0, 5);

        $session->meta = $meta;
        $session->save();
    }

    private function set_active_service(AiChatSession $session, int $service_id, ?string $service_name, string $topic): void
    {
        $meta = $this->meta($session);

        $session->active_service_id = $service_id;
        $meta['active_topic'] = $topic;
        $meta['active_service_id'] = $service_id;
        $meta['active_service_name'] = $service_name;

        $stack = $meta['entity_stack'] ?? [];
        $stack = array_values(array_filter($stack, fn($e) => !(($e['type'] ?? null) === 'service' && (int) ($e['id'] ?? 0) === $service_id)));

        array_unshift($stack, [
            'type' => 'service',
            'id' => $service_id,
            'label' => $service_name,
        ]);

        $meta['entity_stack'] = array_slice($stack, 0, 5);

        $session->meta = $meta;
        $session->save();
    }

    private function set_pending_plan(AiChatSession $session, array $plan): void
    {
        $meta = $this->meta($session);
        $meta['pending_plan'] = $plan;
        $session->meta = $meta;
        $session->save();
    }

    private function clear_pending(AiChatSession $session): void
    {
        $meta = $this->meta($session);
        unset($meta['pending_plan']);
        $session->meta = $meta;
        $session->save();
    }

    private function get_meta(AiChatSession $session, string $key, mixed $default = null): mixed
    {
        $meta = $this->meta($session);

        return $meta[$key] ?? $default;
    }

    private function meta(AiChatSession $session): array
    {
        $meta = $session->meta ?: [];

        if (is_string($meta)) {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($meta) ? $meta : [];
    }

    private function build_session_meta(AiChatSession $session): array
    {
        $meta = $this->meta($session);

        return [
            'active_topic' => $meta['active_topic'] ?? null,
            'active_application_id' => $session->active_application_id,
            'active_application_number' => $meta['active_application_number'] ?? null,
            'active_service_id' => $session->active_service_id,
            'active_service_name' => $meta['active_service_name'] ?? null,
            'pending_plan' => $meta['pending_plan'] ?? null,
            'entity_stack' => $meta['entity_stack'] ?? [],
            'language' => $meta['language'] ?? 'en',
        ];
    }

    // ---------------------------------------------------------------------
    // HISTORY + RESPONSE HELPERS
    // ---------------------------------------------------------------------

    private function get_or_create_session(Request $request, int $user_id): AiChatSession
    {
        if ($request->filled('session_id')) {
            $session = AiChatSession::where('id', $request->input('session_id'))
                ->where('user_id', $user_id)
                ->first();

            if ($session) {
                return $session;
            }
        }

        return AiChatSession::create([
            'user_id' => $user_id,
            'title' => 'SWAAGAT AI Chat',
            'meta' => [],
        ]);
    }

    private function load_history(AiChatSession $session, int $limit = 10): array
    {
        return AiChatMessage::where('ai_chat_session_id', $session->id)
            ->latest('id')
            ->limit($limit)
            ->get(['role', 'message'])
            ->reverse()
            ->values()
            ->map(fn($m) => [
                'role' => $m->role,
                'message' => $m->message,
            ])
            ->toArray();
    }

    private function save_message(AiChatSession $session, string $role, string $message, ?string $answer_type = null): void
    {
        AiChatMessage::create([
            'ai_chat_session_id' => $session->id,
            'user_id' => $session->user_id,
            'role' => $role,
            'message' => $message,
            'answer_type' => $answer_type,
        ]);
    }

    private function reply(
        AiChatSession $session,
        string $answer,
        string $answer_type,
        array $suggested_questions = [],
        ?string $short_status = null,
        ?string $waiting_on = null,
        ?string $next_action = null,
        bool $requires_selection = false,
        ?string $selection_type = null,
        array $options = [],
    ) {
        $this->save_message($session, 'assistant', $answer, $answer_type);

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $answer,
                'message' => $answer,
                'answer_type' => $answer_type,
                'short_status' => $short_status,
                'waiting_on' => $waiting_on,
                'next_action' => $next_action,
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'requires_selection' => $requires_selection,
                'selection_type' => $selection_type,
                'options' => $options,
                'suggested_questions' => $suggested_questions,
            ],
        ]);
    }

    private function first_value(array $data, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private function get_user_id(): int
    {
        return Auth::id() ?: 13909;
    }
}