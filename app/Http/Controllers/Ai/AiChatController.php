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

class AiChatController extends Controller
{
    public function __construct(
        private ChatUnderstandService $understand_service,
        private ChatLiveDataService   $live_data,
        private ChatAnswerService     $answer_service,
    ) {}

    // -------------------------------------------------------------------------
    // OPTIONS — load initial data for the chat UI
    // -------------------------------------------------------------------------

    public function options(Request $request)
    {
        $user_id = $this->get_user_id();

        $applications = UserServiceApplication::with(['service:id,service_title_or_description'])
            ->where('user_id', $user_id)
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'applicationId', 'service_id', 'status', 'payment_status', 'created_at'])
            ->map(fn($a) => [
                'id'                 => $a->id,
                'application_number' => $a->applicationId,
                'service_id'         => $a->service_id,
                'service_name'       => $a->service->service_title_or_description ?? null,
                'status'             => $a->status,
                'payment_status'     => $a->payment_status,
                'created_at'         => optional($a->created_at)->toDateTimeString(),
            ])->values();

        $services = ServiceMaster::orderBy('service_title_or_description')
            ->limit(200)
            ->get(['id', 'service_title_or_description'])
            ->map(fn($s) => ['id' => $s->id, 'service_name' => $s->service_title_or_description])
            ->values();

        return response()->json(['status' => true, 'data' => ['applications' => $applications, 'services' => $services]]);
    }

    // -------------------------------------------------------------------------
    // CHAT — main entry point
    // -------------------------------------------------------------------------

    public function chat(Request $request)
    {
        $request->validate([
            'session_id'     => 'nullable|integer',
            'message'        => 'required|string|max:1500',
            'application_id' => 'nullable|integer',
            'service_id'     => 'nullable|integer',
        ]);

        $user_id = $this->get_user_id();
        $message = trim($request->message);
        $session = $this->get_or_create_session($request, $user_id);

        // Save user message
        $this->save_message($session, 'user', $message);

        // --- Handle explicit selection from frontend (user clicked an option) ---
        if ($request->filled('application_id')) {
            return $this->handle_application_selection($session, (int) $request->application_id, $message);
        }

        if ($request->filled('service_id')) {
            return $this->handle_service_selection($session, (int) $request->service_id, $message);
        }

        // --- Build history for semantic understanding ---
        $history      = $this->load_history($session, 10);
        $session_meta = $this->build_session_meta($session);

        // --- Semantic understanding via FastAPI ---
        $understanding = $this->understand_service->understand($message, $session_meta, $history);

        // --- Handle conversation exit ---
        if ($understanding['is_exit']) {
            return $this->handle_exit($session);
        }

        // --- Handle correction: re-run with corrected entity ---
        if ($understanding['is_correction']) {
            return $this->handle_correction($session, $message, $understanding);
        }

        // --- Route by capability family ---
        return $this->route_by_capability($session, $message, $understanding);
    }

    // -------------------------------------------------------------------------
    // ROUTING
    // -------------------------------------------------------------------------

    private function route_by_capability(AiChatSession $session, string $message, array $u)
    {
        $family = $u['capability_family'];
        $kind   = $u['message_kind'];

        // Greeting
        if ($kind === 'greeting' || ($family === 'smalltalk_or_help' && $kind === 'greeting')) {
            return $this->answer_greeting($session);
        }

        // Smalltalk / capabilities / help
        if ($family === 'smalltalk_or_help') {
            // Could be account question or just capabilities
            if ($u['needs_private_data']) {
                return $this->handle_account_question($session, $message, $u);
            }
            return $this->answer_capabilities($session);
        }

        // Application list ("show my applications", "how many applications", "list noc issued" etc.)
        if ($this->is_application_list_request($message, $u)) {
            return $this->handle_application_list($session, $message, $u);
        }

        // Application-lifecycle, payment, certificate, renewal
        if (in_array($family, ['application_lifecycle', 'payment', 'certificate', 'renewal'])) {
            return $this->handle_application_family($session, $message, $u);
        }

        // Documents for a service
        if ($family === 'documents') {
            return $this->handle_documents_family($session, $message, $u);
        }

        // Service discovery / eligibility / fees / processing time
        if (in_array($family, ['service_discovery', 'eligibility'])) {
            return $this->handle_service_discovery($session, $message, $u);
        }

        // Notifications
        if ($family === 'notifications') {
            return $this->answer_static($session, $message, 'notifications', 'Please check the Notifications section in your portal dashboard for the latest updates.');
        }

        // Grievance / support
        if ($family === 'grievance_support') {
            return $this->answer_static($session, $message, 'grievance_support', 'For grievances or support, please use the Feedback/Grievance section in the portal or contact the concerned department directly.');
        }

        // General knowledge / FAQ / SOP — RAG placeholder
        if ($family === 'general_knowledge') {
            return $this->handle_general_knowledge($session, $message, $u);
        }

        // Unknown — try to be helpful based on context
        if ($family === 'unknown') {
            return $this->handle_unknown($session, $message, $u);
        }

        // Clarification needed
        if (!empty($u['clarification_question'])) {
            return $this->ask_clarification($session, $u['clarification_question']);
        }

        return $this->answer_capabilities($session);
    }

    // -------------------------------------------------------------------------
    // APPLICATION FAMILY (lifecycle, payment, certificate, renewal)
    // -------------------------------------------------------------------------

    private function handle_application_family(AiChatSession $session, string $message, array $u)
    {
        $resolved = $this->resolve_application_id($session, $message, $u);

        // User typed a specific application number but it was not found in their account
        if ($resolved['explicit_not_found']) {
            $typed = $resolved['typed_number'] ?? 'that application number';
            return $this->reply(
                $session,
                "I could not find application **{$typed}** in your account. Please check the number or select from your application list.",
                'application',
                ['Show my applications']
            );
        }

        if ($resolved['id']) {
            $this->clear_pending($session);
            return $this->answer_with_application($session, $resolved['id'], $message, $u);
        }

        // No application resolved — store pending plan and ask user to select
        $this->set_pending_plan($session, [
            'capability_family' => $u['capability_family'],
            'user_goal'         => $u['user_goal'],
            'original_message'  => $message,
            'required_slots'    => $u['required_slots'] ?? [],
        ]);

        return $this->ask_application_selection($session);
    }

    private function answer_with_application(AiChatSession $session, int $application_id, string $message, array $u)
    {
        $context = $this->live_data->fetch_application_context($application_id, $session->user_id);

        if (!$context) {
            return $this->reply($session, 'I could not find that application in your account.', 'application', []);
        }

        // Update session active application
        $app_number = $context['application']['application_number'] ?? null;
        $service_id = $context['application']['service_id'] ?? null;

        $this->update_session_entity($session, $application_id, $app_number, $service_id, $u['capability_family']);

        $ai = $this->answer_service->generate_application_answer($message, $context);

        return $this->reply($session, $ai['answer'] ?? 'I could not prepare an answer.', $ai['answer_type'] ?? 'application', [
            'What is my application status?',
            'What should I do next?',
            'What is my payment status?',
            'Is my certificate generated?',
        ], $ai['short_status'] ?? null, $ai['waiting_on'] ?? null, $ai['next_action'] ?? null);
    }

    // -------------------------------------------------------------------------
    // DOCUMENTS FAMILY
    // -------------------------------------------------------------------------

    private function handle_documents_family(AiChatSession $session, string $message, array $u)
    {
        // 1. Try active session service or entity stack
        $service_id = $this->resolve_service_id($session, $message, $u);

        if ($service_id) {
            $this->clear_pending($session);
            return $this->answer_with_service_documents($session, $service_id, $message);
        }

        // 2. Try entity from FastAPI understanding
        $service_entity = collect($u['entities'] ?? [])->firstWhere('type', 'service');
        $service_text   = $service_entity['text'] ?? null;

        // 3. Also try extracting service name directly from message if no entity found
        if (!$service_text) {
            $service_text = $this->extract_service_name_from_message($message);
        }

        if ($service_text) {
            $resolved = $this->live_data->resolve_service_by_name($service_text);

            if ($resolved['status'] === 'found') {
                $this->clear_pending($session);
                return $this->answer_with_service_documents($session, $resolved['service_id'], $message);
            }

            if ($resolved['status'] === 'multiple') {
                $this->set_pending_plan($session, [
                    'capability_family' => 'documents',
                    'user_goal'         => $u['user_goal'],
                    'original_message'  => $message,
                    'required_slots'    => ['service'],
                ]);
                return $this->ask_service_selection($session, $resolved['options']);
            }
        }

        // 4. Ask user to type service name
        $this->set_pending_plan($session, [
            'capability_family' => 'documents',
            'user_goal'         => $u['user_goal'],
            'original_message'  => $message,
            'required_slots'    => ['service'],
        ]);

        return $this->reply($session, 'Please type the service name you want document requirements for. Example: professional tax, partnership firm, factory license.', 'service', []);
    }

    private function answer_with_service_documents(AiChatSession $session, int $service_id, string $message)
    {
        $context = $this->live_data->fetch_service_document_context($service_id);

        if (!$context) {
            return $this->reply($session, 'I could not find that service.', 'service', []);
        }

        $session->active_service_id = $service_id;
        $this->update_meta($session, 'active_service_id', $service_id);
        $this->update_meta($session, 'active_service_name', $context['service_name']);
        $this->update_meta($session, 'active_topic', 'documents');
        $session->save();

        $ai = $this->answer_service->generate($message, 'SERVICE_DATA', $context);

        return $this->reply($session, $ai['answer'] ?? 'I could not prepare an answer.', 'service', [
            'Show required documents',
            'Show optional documents',
            'Show conditional documents',
        ], $context['service_name']);
    }

    // -------------------------------------------------------------------------
    // SERVICE DISCOVERY
    // -------------------------------------------------------------------------

    private function handle_service_discovery(AiChatSession $session, string $message, array $u)
    {
        $service_id = $this->resolve_service_id($session, $message, $u);

        if (!$service_id) {
            // Try entity from understanding
            $service_entity = collect($u['entities'] ?? [])->firstWhere('type', 'service');
            $service_text   = $service_entity['text'] ?? $this->extract_service_name_from_message($message);

            if ($service_text) {
                $resolved = $this->live_data->resolve_service_by_name($service_text);
                if ($resolved['status'] === 'found') {
                    $service_id = $resolved['service_id'];
                }
            }
        }

        if ($service_id) {
            $context = $this->live_data->fetch_service_document_context($service_id);
            if ($context) {
                $ai = $this->answer_service->generate($message, 'SERVICE_DATA', $context);
                return $this->reply($session, $ai['answer'] ?? 'I could not prepare an answer.', 'service', [], $context['service_name']);
            }
        }

        return $this->handle_general_knowledge($session, $message, $u);
    }

    // -------------------------------------------------------------------------
    // GENERAL KNOWLEDGE (RAG placeholder)
    // -------------------------------------------------------------------------

    private function handle_general_knowledge(AiChatSession $session, string $message, array $u)
    {
        $context = [
            'user_goal'    => $u['user_goal'],
            'capability'   => $u['capability_family'],
            'session_meta' => $this->build_session_meta($session),
        ];

        // If there's an active application, include its basic info so Groq can answer follow-ups
        if ($session->active_application_id) {
            $app_context = $this->live_data->fetch_application_context(
                (int) $session->active_application_id,
                $session->user_id
            );
            if ($app_context) {
                $context['application_context'] = $app_context;
            }
        }

        $ai = $this->answer_service->generate($message, 'GENERAL', $context);

        return $this->reply($session, $ai['answer'] ?? 'I could not find information on that. Please contact the concerned department.', 'general', [
            'Show my applications',
            'What documents are required?',
            'What is my application status?',
        ]);
    }

    // -------------------------------------------------------------------------
    // SELECTION HANDLERS (user clicked an option from the UI)
    // -------------------------------------------------------------------------

    private function handle_application_selection(AiChatSession $session, int $application_id, string $message)
    {
        $pending = $this->get_meta($session, 'pending_plan');
        $original_message = $pending['original_message'] ?? $message;

        $this->clear_pending($session);

        $u = ['capability_family' => $pending['capability_family'] ?? 'application_lifecycle'];

        return $this->answer_with_application($session, $application_id, $original_message, $u);
    }

    private function handle_service_selection(AiChatSession $session, int $service_id, string $message)
    {
        $pending = $this->get_meta($session, 'pending_plan');
        $original_message = $pending['original_message'] ?? $message;

        $this->clear_pending($session);

        return $this->answer_with_service_documents($session, $service_id, $original_message);
    }

    // -------------------------------------------------------------------------
    // CORRECTION HANDLER
    // -------------------------------------------------------------------------

    private function handle_correction(AiChatSession $session, string $message, array $u)
    {
        // Clear stale active service/application if correction changes the entity
        $service_entity = collect($u['entities'] ?? [])->firstWhere('type', 'service');

        if ($service_entity) {
            $resolved = $this->live_data->resolve_service_by_name($service_entity['text']);

            if ($resolved['status'] === 'found') {
                $session->active_service_id = $resolved['service_id'];
                $this->update_meta($session, 'active_service_id', $resolved['service_id']);
                $this->update_meta($session, 'active_service_name', $resolved['service_name'] ?? null);
                $session->save();

                return $this->answer_with_service_documents($session, $resolved['service_id'], $message);
            }
        }

        // Fall through to normal routing
        return $this->route_by_capability($session, $message, array_merge($u, ['is_correction' => false]));
    }

    // -------------------------------------------------------------------------
    // APPLICATION LIST
    // -------------------------------------------------------------------------

    private function is_application_list_request(string $message, array $u): bool
    {
        $text = strtolower($message);
        $list_keywords = [
            'application list', 'my applications', 'show applications', 'list applications',
            'all applications', 'how many application', 'how much application', 'how many app',
            'noc issued', 'which are approved', 'which are pending', 'which are rejected',
            'applications which', 'application which', 'payment pending application',
            'pending payment', 'show all my', 'list my application',
        ];

        foreach ($list_keywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    private function handle_application_list(AiChatSession $session, string $message, array $u)
    {
        $text = strtolower($message);

        // Detect filter from message
        $filter = null;
        if (str_contains($text, 'noc issued') || str_contains($text, 'approved') || str_contains($text, 'completed')) {
            $filter = 'approved';
        } elseif (str_contains($text, 'pending') && str_contains($text, 'payment')) {
            $filter = 'payment_pending';
        } elseif (str_contains($text, 'pending')) {
            $filter = 'pending';
        } elseif (str_contains($text, 'rejected')) {
            $filter = 'rejected';
        } elseif (str_contains($text, 'expired')) {
            $filter = 'expired';
        }

        $all_applications = UserServiceApplication::with(['service:id,service_title_or_description'])
            ->where('user_id', $session->user_id)
            ->latest('id')
            ->get(['id', 'applicationId', 'service_id', 'status', 'payment_status', 'created_at', 'NOC_expiry_date']);

        $total = $all_applications->count();

        // Apply filter
        $filtered = $all_applications;
        if ($filter === 'approved') {
            $filtered = $all_applications->filter(fn($a) => in_array($a->status, ['approved', 'noc_issued', 'completed', 'certificate_issued']));
        } elseif ($filter === 'payment_pending') {
            $filtered = $all_applications->filter(fn($a) => $a->payment_status === 'pending');
        } elseif ($filter === 'pending') {
            $filtered = $all_applications->filter(fn($a) => in_array($a->status, ['pending', 'submitted', 'under_review', 'send_back']));
        } elseif ($filter === 'rejected') {
            $filtered = $all_applications->filter(fn($a) => $a->status === 'rejected');
        } elseif ($filter === 'expired') {
            $filtered = $all_applications->filter(fn($a) => $a->status === 'expired');
        }

        $filtered = $filtered->values();
        $count    = $filtered->count();

        // Build summary for AI
        $summary_list = $filtered->take(20)->map(fn($a) => [
            'application_number' => $a->applicationId ?? ('App #' . $a->id),
            'service_name'       => $a->service->service_title_or_description ?? 'Unknown Service',
            'status'             => $a->status,
            'payment_status'     => $a->payment_status,
            'created_at'         => optional($a->created_at)->toDateString(),
        ])->toArray();

        $context = [
            'total_applications'   => $total,
            'filtered_count'       => $count,
            'filter_applied'       => $filter,
            'applications'         => $summary_list,
        ];

        $ai = $this->answer_service->generate($message, 'APPLICATION_LIST', $context);

        // Also return as selectable options if count is reasonable
        $options = $filtered->take(15)->map(fn($a) => [
            'id'       => $a->id,
            'title'    => $a->applicationId ?? ('Application #' . $a->id),
            'subtitle' => ($a->service->service_title_or_description ?? 'Service') . ' — ' . ($a->status ?? ''),
        ])->values()->toArray();

        $this->save_message($session, 'assistant', $ai['answer'] ?? '', 'application_list');

        return response()->json([
            'status' => true,
            'data'   => [
                'session_id'            => $session->id,
                'answer'                => $ai['answer'] ?? 'I could not prepare an answer.',
                'short_status'          => $ai['short_status'] ?? null,
                'answer_type'           => 'application_list',
                'active_application_id' => $session->active_application_id,
                'active_service_id'     => $session->active_service_id,
                'requires_selection'    => $count > 0,
                'selection_type'        => 'application',
                'options'               => $options,
                'suggested_questions'   => [
                    'Where is my application stuck?',
                    'What is my payment status?',
                    'Show applications with payment pending',
                    'Show approved applications',
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // ACCOUNT QUESTION
    // -------------------------------------------------------------------------

    private function handle_account_question(AiChatSession $session, string $message, array $u)
    {
        $context = $this->live_data->fetch_account_context($session->user_id);

        if (!$context) {
            return $this->reply($session, 'I could not fetch your account details.', 'account', []);
        }

        $ai = $this->answer_service->generate($message, 'ACCOUNT_DATA', ['account' => $context]);

        return $this->reply($session, $ai['answer'] ?? 'I could not prepare an answer.', 'account', [
            'Show my applications',
            'What is my application status?',
        ]);
    }

    // -------------------------------------------------------------------------
    // UNKNOWN HANDLER — try to be helpful using context
    // -------------------------------------------------------------------------

    private function handle_unknown(AiChatSession $session, string $message, array $u)
    {
        // If there's an active application, try to answer about it
        if ($session->active_application_id) {
            return $this->answer_with_application($session, (int) $session->active_application_id, $message, $u);
        }

        // If clarification question available, ask it
        if (!empty($u['clarification_question'])) {
            return $this->ask_clarification($session, $u['clarification_question']);
        }

        return $this->answer_capabilities($session);
    }

    private function handle_exit(AiChatSession $session)
    {
        $this->clear_pending($session);

        // Clear active entities on exit
        $session->active_application_id = null;
        $session->active_service_id     = null;
        $session->meta = [];
        $session->save();

        return $this->reply($session, 'Thank you for using SWAAGAT. Have a great day! Feel free to ask if you need help again.', 'general', []);
    }

    // -------------------------------------------------------------------------
    // SIMPLE ANSWERS
    // -------------------------------------------------------------------------

    private function answer_greeting(AiChatSession $session)
    {
        return $this->reply($session, 'Hello! I am SWAAGAT AI Assistant. I can help you with application status, payment, documents, certificates, renewal, and more. What would you like to know?', 'general', [
            'Show my applications',
            'What documents are required?',
            'What is my application status?',
            'How do I renew my license?',
        ]);
    }

    private function answer_capabilities(AiChatSession $session)
    {
        return $this->reply($session, 'I can help with: application status, payment status, certificate/NOC, renewal, document requirements, service information, eligibility, and general process questions.', 'general', [
            'Show my applications',
            'Where is my application stuck?',
            'Which documents are required for this service?',
            'How do I download my certificate?',
        ]);
    }

    private function answer_static(AiChatSession $session, string $message, string $type, string $text)
    {
        return $this->reply($session, $text, $type, []);
    }

    private function ask_clarification(AiChatSession $session, string $question)
    {
        return $this->reply($session, $question, 'clarification', []);
    }

    // -------------------------------------------------------------------------
    // SELECTION PROMPTS
    // -------------------------------------------------------------------------

    private function ask_application_selection(AiChatSession $session)
    {
        $applications = $this->live_data->fetch_user_applications($session->user_id);

        $this->save_message($session, 'assistant', 'Please select which application you want to ask about.');

        return response()->json([
            'status' => true,
            'data'   => [
                'session_id'             => $session->id,
                'requires_selection'     => true,
                'selection_type'         => 'application',
                'message'                => 'Please select which application you want to ask about.',
                'active_application_id'  => $session->active_application_id,
                'active_service_id'      => $session->active_service_id,
                'options'                => $applications,
                'suggested_questions'    => [],
            ],
        ]);
    }

    private function ask_service_selection(AiChatSession $session, array $options)
    {
        $this->save_message($session, 'assistant', 'I found multiple matching services. Please select the correct one.');

        return response()->json([
            'status' => true,
            'data'   => [
                'session_id'            => $session->id,
                'requires_selection'    => true,
                'selection_type'        => 'service',
                'message'               => 'I found multiple matching services. Please select the correct one.',
                'active_application_id' => $session->active_application_id,
                'active_service_id'     => $session->active_service_id,
                'options'               => $options,
                'suggested_questions'   => [],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // ENTITY RESOLUTION
    // -------------------------------------------------------------------------

    /**
     * Returns ['id' => int|null, 'explicit_not_found' => bool]
     * explicit_not_found = true means user typed a specific number but it wasn't found — do NOT fall back silently.
     */
    private function resolve_application_id(AiChatSession $session, string $message, array $u): array
    {
        // 1. Explicit application number typed in message — highest priority
        if (preg_match('/\b[A-Z]{2,5}(?:-[A-Z0-9]+){1,5}\b/i', $message, $match)) {
            $app = $this->live_data->resolve_application_by_number($match[0], $session->user_id);
            if ($app) {
                return ['id' => (int) $app->id, 'explicit_not_found' => false, 'typed_number' => $match[0]];
            }
            // User typed a specific number but it was not found — do NOT fall back
            return ['id' => null, 'explicit_not_found' => true, 'typed_number' => $match[0]];
        }

        // 2. Entity from FastAPI understanding (application entity)
        $app_entity = collect($u['entities'] ?? [])->firstWhere('type', 'application');
        if ($app_entity && !empty($app_entity['text'])) {
            $app = $this->live_data->resolve_application_by_number($app_entity['text'], $session->user_id);
            if ($app) {
                return ['id' => (int) $app->id, 'explicit_not_found' => false, 'typed_number' => null];
            }
            // Entity found in message but not in DB — do NOT fall back silently
            return ['id' => null, 'explicit_not_found' => true, 'typed_number' => $app_entity['text']];
        }

        // 3. Active session application — use for follow-ups, context switches, pronoun references
        if ($session->active_application_id) {
            $refs = $u['references'] ?? [];
            $kind = $u['message_kind'] ?? '';
            if (
                in_array('active_application', $refs) ||
                in_array($kind, ['follow_up', 'correction']) ||
                $u['is_context_switch']
            ) {
                return ['id' => (int) $session->active_application_id, 'explicit_not_found' => false, 'typed_number' => null];
            }
        }

        // 4. Entity stack top (only for pronoun/implicit references, no explicit number typed)
        $entity_stack = $this->get_meta($session, 'entity_stack', []);
        $top = collect($entity_stack)->firstWhere('type', 'application');
        if ($top && in_array('active_application', $u['references'] ?? [])) {
            return ['id' => (int) $top['id'], 'explicit_not_found' => false, 'typed_number' => null];
        }

        return ['id' => null, 'explicit_not_found' => false, 'typed_number' => null];
    }

    private function resolve_service_id(AiChatSession $session, string $message, array $u): ?int
    {
        // 1. Active session service (only if message references it)
        if ($session->active_service_id && in_array('active_service', $u['references'] ?? [])) {
            return (int) $session->active_service_id;
        }

        // 2. Entity stack
        $entity_stack = $this->get_meta($session, 'entity_stack', []);
        $top = collect($entity_stack)->firstWhere('type', 'service');
        if ($top) {
            return (int) $top['id'];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // SESSION STATE
    // -------------------------------------------------------------------------

    private function update_session_entity(AiChatSession $session, int $app_id, ?string $app_number, ?int $service_id, string $topic): void
    {
        $session->active_application_id = $app_id;
        if ($service_id) {
            $session->active_service_id = $service_id;
        }

        $meta = $session->meta ?: [];
        $meta['active_topic']              = $topic;
        $meta['active_application_id']     = $app_id;
        $meta['active_application_number'] = $app_number;
        if ($service_id) {
            $meta['active_service_id'] = $service_id;
        }

        // Push to entity stack
        $stack = $meta['entity_stack'] ?? [];
        $stack = array_filter($stack, fn($e) => !($e['type'] === 'application' && $e['id'] === $app_id));
        array_unshift($stack, ['type' => 'application', 'id' => $app_id, 'label' => $app_number]);
        $meta['entity_stack'] = array_values(array_slice($stack, 0, 5));

        $session->meta = $meta;
        $session->save();
    }

    private function set_pending_plan(AiChatSession $session, array $plan): void
    {
        $meta = $session->meta ?: [];
        $meta['pending_plan'] = $plan;
        $session->meta = $meta;
        $session->save();
    }

    private function clear_pending(AiChatSession $session): void
    {
        $meta = $session->meta ?: [];
        unset($meta['pending_plan']);
        $session->meta = $meta;
        $session->save();
    }

    private function get_meta(AiChatSession $session, string $key, $default = null)
    {
        return ($session->meta ?? [])[$key] ?? $default;
    }

    private function update_meta(AiChatSession $session, string $key, $value): void
    {
        $meta = $session->meta ?: [];
        $meta[$key] = $value;
        $session->meta = $meta;
        // caller must call $session->save()
    }

    private function build_session_meta(AiChatSession $session): array
    {
        $meta = $session->meta ?: [];

        return [
            'active_topic'              => $meta['active_topic'] ?? null,
            'active_application_id'     => $session->active_application_id,
            'active_application_number' => $meta['active_application_number'] ?? null,
            'active_service_id'         => $session->active_service_id,
            'active_service_name'       => $meta['active_service_name'] ?? null,
            'pending_plan'              => $meta['pending_plan'] ?? null,
            'entity_stack'              => $meta['entity_stack'] ?? [],
            'language'                  => $meta['language'] ?? 'en',
        ];
    }

    // -------------------------------------------------------------------------
    // HISTORY
    // -------------------------------------------------------------------------

    private function load_history(AiChatSession $session, int $limit = 10): array
    {
        return AiChatMessage::where('ai_chat_session_id', $session->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['role', 'message'])
            ->reverse()
            ->values()
            ->map(fn($m) => ['role' => $m->role, 'message' => $m->message])
            ->toArray();
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function get_or_create_session(Request $request, int $user_id): AiChatSession
    {
        if ($request->filled('session_id')) {
            $session = AiChatSession::where('id', $request->session_id)
                ->where('user_id', $user_id)
                ->first();

            if ($session) {
                return $session;
            }
        }

        return AiChatSession::create(['user_id' => $user_id, 'title' => 'SWAAGAT AI Chat']);
    }

    private function save_message(AiChatSession $session, string $role, string $message, ?string $answer_type = null): void
    {
        AiChatMessage::create([
            'ai_chat_session_id' => $session->id,
            'user_id'            => $session->user_id,
            'role'               => $role,
            'message'            => $message,
            'answer_type'        => $answer_type,
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
    ) {
        $this->save_message($session, 'assistant', $answer, $answer_type);

        return response()->json([
            'status' => true,
            'data'   => [
                'session_id'            => $session->id,
                'answer'                => $answer,
                'short_status'          => $short_status,
                'answer_type'           => $answer_type,
                'waiting_on'            => $waiting_on,
                'next_action'           => $next_action,
                'active_application_id' => $session->active_application_id,
                'active_service_id'     => $session->active_service_id,
                'suggested_questions'   => $suggested_questions,
            ],
        ]);
    }

    private function extract_service_name_from_message(string $message): ?string
    {
        $text = strtolower($message);

        // Strip common prefixes to isolate service name
        $prefixes = [
            'and for', 'for', 'about', 'documents for', 'docs for',
            'what documents for', 'documents needed for', 'required for',
            'documents required for', 'what are the documents for',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                $text = trim(substr($text, strlen($prefix)));
                break;
            }
        }

        // Remove trailing noise
        $text = preg_replace('/(\?|\.|service|please|tell me|show me)$/i', '', trim($text));
        $text = trim($text);

        return strlen($text) >= 3 ? $text : null;
    }

    private function get_user_id(): int
    {
        // TODO: replace with Auth::id() when auth middleware is applied to chat routes
        return 8247;
    }
}
