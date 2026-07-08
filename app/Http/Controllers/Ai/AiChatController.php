<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiChatSession;
use App\Models\AiChatMessage;
use App\Models\ServiceMaster;
use App\Models\ServiceQuestionnaire;
use App\Models\User;
use App\Models\UserServiceApplication;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class AiChatController extends Controller
{
    public function options()
    {
        $user = User::find(9113);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated user.',
            ], 401);
        }

        $applications = UserServiceApplication::with([
            'service:id,service_title_or_description'
        ])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get([
                'id',
                'applicationId',
                'service_id',
                'status',
                'payment_status',
                'created_at',
            ])
            ->map(function ($app) {
                return [
                    'id' => $app->id,
                    'application_number' => $app->applicationId,
                    'service_id' => $app->service_id,
                    'service_name' => $app->service->service_title_or_description ?? null,
                    'status' => $app->status,
                    'payment_status' => $app->payment_status,
                    'created_at' => optional($app->created_at)->toDateTimeString(),
                ];
            })
            ->values();

        $services = ServiceMaster::query()
            ->orderBy('service_title_or_description')
            ->limit(200)
            ->get([
                'id',
                'service_title_or_description',
            ])
            ->map(function ($service) {
                return [
                    'id' => $service->id,
                    'service_name' => $service->service_title_or_description,
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'applications' => $applications,
                'services' => $services,
            ],
        ]);
    }

    public function chat(Request $request)
    {
        $request->validate([
            'session_id' => 'nullable|integer',
            'message' => 'required|string|max:1500',
            'application_id' => 'nullable|integer',
            'service_id' => 'nullable|integer',
        ]);

        // TODO: switch to Auth::user() in production
        $user = User::find(16066);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated user.',
            ], 401);
        }

        $message = trim($request->message);

        $session = $this->get_or_create_session($request, $user->id);

        $this->save_chat_message(
            session: $session,
            role: 'user',
            message: $message
        );

        // 1. explicit selection from chat UI — always wins
        if ($request->filled('application_id')) {
            $this->clear_session_meta($session, 'awaiting');

            return $this->ask_about_application(
                request: $request,
                session: $session,
                application_id: (int) $request->application_id,
                message: $message
            );
        }

        if ($request->filled('service_id')) {
            $this->clear_session_meta($session, 'awaiting');

            return $this->ask_about_service(
                session: $session,
                service_id: (int) $request->service_id,
                message: 'Continue with this selected service'
            );
        }

        // 2. application number typed in message e.g. SPC-106-009676
        $applicationFromMessage = $this->resolve_application_from_message($message, $user->id);

        if ($applicationFromMessage) {
            $this->clear_session_meta($session, 'awaiting');

            return $this->ask_about_application(
                request: $request,
                session: $session,
                application_id: (int) $applicationFromMessage->id,
                message: $message
            );
        }

        // 3. global commands — must run before awaiting-service-name check
        if ($this->is_application_list_question($message)) {
            $this->clear_session_meta($session, 'awaiting');

            return $this->answer_application_list($session);
        }

        if ($this->is_account_question($message)) {
            $this->clear_session_meta($session, 'awaiting');

            return $this->answer_account_question($session, $message);
        }

        if ($this->is_greeting($message)) {
            $this->clear_session_meta($session, 'awaiting');

            return $this->direct_answer(
                session: $session,
                answer: 'Hi! I can help with your applications, payments, documents, certificates, renewal, and timelines.',
                answer_type: 'general',
                suggested_questions: [
                    'Show my applications',
                    'Which documents are required?',
                    'When can I renew?',
                ]
            );
        }

        if ($this->is_capability_question($message)) {
            $this->clear_session_meta($session, 'awaiting');

            return $this->direct_answer(
                session: $session,
                answer: 'I can help you check application status, payment status, required documents, certificate/NOC details, renewal information, send-back remarks, and application timeline.',
                answer_type: 'general',
                suggested_questions: [
                    'Show my applications',
                    'Which documents are required?',
                    'When can I renew?',
                ]
            );
        }

        // 4. application-specific questions (status, payment, renewal, etc.)
        if ($this->is_application_question($message)) {
            $this->clear_session_meta($session, 'awaiting');

            if ($session->active_application_id) {
                return $this->ask_about_application(
                    request: $request,
                    session: $session,
                    application_id: (int) $session->active_application_id,
                    message: $message
                );
            }

            return $this->application_selection_response($session);
        }

        // 5. document group buttons (show required / optional / conditional)
        if ($this->is_service_group_command($message)) {
            $this->clear_session_meta($session, 'awaiting');

            if ($session->active_service_id) {
                return $this->ask_about_service(
                    session: $session,
                    service_id: (int) $session->active_service_id,
                    message: $message
                );
            }

            $this->set_session_meta($session, 'awaiting', 'service_name');

            return $this->direct_answer(
                session: $session,
                answer: 'Please type the service name. Example: professional tax, partnership firm, water connection.',
                answer_type: 'service',
                suggested_questions: []
            );
        }

        // 6. user is replying with a service name after being asked
        if ($this->get_session_meta($session, 'awaiting') === 'service_name') {
            return $this->handle_service_query($session, $message);
        }

        // 7. document question with or without service name in message
        if ($this->is_service_document_question($message)) {
            return $this->handle_service_document_question($session, $message);
        }

        // 8. AI planner fallback — only runs when nothing above matched
        $planner = $this->call_ai_planner($session, $message);
        $scope = $planner['data_scope'] ?? 'UNKNOWN';

        if ($scope === 'RATE_LIMITED') {
            return $this->direct_answer(
                session: $session,
                answer: 'AI service is busy right now. Please wait a moment and try again.',
                answer_type: 'general',
                suggested_questions: []
            );
        }

        if ($scope === 'APPLICATION_LIST') {
            $this->clear_session_meta($session, 'awaiting');

            return $this->answer_application_list($session);
        }

        if ($scope === 'ACCOUNT_DATA') {
            $this->clear_session_meta($session, 'awaiting');

            return $this->answer_account_question($session, $message);
        }

        if ($scope === 'APPLICATION_DATA') {
            $this->clear_session_meta($session, 'awaiting');

            if ($session->active_application_id) {
                return $this->ask_about_application(
                    request: $request,
                    session: $session,
                    application_id: (int) $session->active_application_id,
                    message: $message
                );
            }

            return $this->application_selection_response($session);
        }

        if (in_array($scope, ['SERVICE_DATA', 'SERVICE_SEARCH', 'RAG_KNOWLEDGE'])) {
            return $this->handle_service_document_question($session, $message);
        }

        // 9. nothing matched
        return $this->direct_answer(
            session: $session,
            answer: 'Please ask about your application, payment, renewal, certificate, or service documents.',
            answer_type: 'unknown',
            suggested_questions: [
                'Show my applications',
                'Which documents are required?',
                'When can I renew?',
            ]
        );
    }

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

        return AiChatSession::create([
            'user_id' => $user_id,
            'title' => 'SWAAGAT AI Chat',
        ]);
    }

    private function save_chat_message(AiChatSession $session, string $role, string $message, ?string $intent = null, ?string $answer_type = null, array $metadata = []): void
    {
        AiChatMessage::create([
            'ai_chat_session_id' => $session->id,
            'user_id' => $session->user_id,
            'role' => $role,
            'message' => $message,
            'intent' => $intent,
            'answer_type' => $answer_type,
            'metadata' => $metadata,
        ]);
    }

    private function direct_answer(AiChatSession $session, string $answer, string $answer_type, array $suggested_questions = [])
    {
        $this->save_chat_message(
            session: $session,
            role: 'assistant',
            message: $answer,
            intent: $session->last_intent,
            answer_type: $answer_type
        );

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $answer,
                'short_status' => null,
                'answer_type' => $answer_type,
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => $suggested_questions,
            ],
        ]);
    }

    private function ask_about_application(Request $request, AiChatSession $session, int $application_id, string $message)
    {
        $application = UserServiceApplication::with([
            'service:id,service_title_or_description,noc_validity,fixed_expiry_date,department_id',
            'service.renewalCycles',
            'service.department:id,name',
        ])
            ->where('id', $application_id)
            ->where('user_id', $session->user_id)
            ->select([
                'id',
                'applicationId',
                'user_id',
                'service_id',
                'status',
                'payment_status',
                'total_fee',
                'final_fee',
                'effective_fee',
                'paid_amount',
                'GRN_number',
                'created_at',
                'updated_at',
                'application_date',
                'NOC_certificate',
                'NOC_rejection_certificate',
                'NOC_generationDate',
                'NOC_letter_number',
                'NOC_letter_date',
                'NOC_expiry_date',
                'renewal_cycle_id',
                'renewal',
                'renewalYear',
                'PreviousNOCexpiryDate',
                'external_valid_till',
                'external_noc_number',
                'is_third_party',
                'previous_application_id',
            ])
            ->first();

        if (!$application) {
            return response()->json(['status' => false, 'message' => 'Application not found or not allowed.'], 404);
        }

        $session->active_application_id = $application->id;
        $session->active_service_id = $application->service_id;
        $session->save();

        $context = $this->build_application_context($application);

        $ai = $this->call_application_ai($message, $context);

        $answer = $ai['answer'] ?? 'I could not prepare an answer.';
        $answer_type = $ai['answer_type'] ?? 'application';

        $this->save_chat_message(
            session: $session,
            role: 'assistant',
            message: $answer,
            intent: $session->last_intent,
            answer_type: $answer_type
        );

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $answer,
                'short_status' => $ai['short_status'] ?? null,
                'answer_type' => $answer_type,
                'waiting_on' => $ai['waiting_on'] ?? null,
                'next_action' => $ai['next_action'] ?? null,
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => [
                    'What should I do next?',
                    'What is my payment status?',
                    'Which documents are required?',
                    'Is my certificate generated?',
                ],
            ],
        ]);
    }

    private function build_application_context($application): array
    {
        $approved_at = DB::table('application_workflow_assignments')
            ->where('application_id', $application->id)
            ->where('status', 'approved')
            ->whereNotNull('action_taken_at')
            ->orderByDesc('action_taken_at')
            ->value('action_taken_at');

        $latest_assignment = DB::table('application_workflow_assignments as awa')
            ->leftJoin('departments as d', 'd.id', '=', 'awa.department_id')
            ->where('awa.application_id', $application->id)
            ->orderByDesc('awa.id')
            ->select([
                'awa.id',
                'awa.application_id',
                'awa.service_id',
                'awa.step_number',
                'awa.step_type',
                'awa.department_id',
                'd.name as department_name',
                'awa.hierarchy_level',
                'awa.assigned_to_group',
                'awa.status',
                'awa.action_taken_by',
                'awa.action_taken_at',
                'awa.remarks',
                'awa.created_at',
                'awa.updated_at',
            ])
            ->first();

        $recent_assignments = DB::table('application_workflow_assignments as awa')
            ->leftJoin('departments as d', 'd.id', '=', 'awa.department_id')
            ->where('awa.application_id', $application->id)
            ->orderByDesc('awa.id')
            ->limit(8)
            ->select([
                'awa.id',
                'awa.step_number',
                'awa.step_type',
                'awa.department_id',
                'd.name as department_name',
                'awa.hierarchy_level',
                'awa.status',
                'awa.action_taken_by',
                'awa.action_taken_at',
                'awa.remarks',
                'awa.created_at',
                'awa.updated_at',
            ])
            ->get();

        $approval_flow = DB::table('service_approval_flows')
            ->where('service_id', $application->service_id)
            ->orderBy('step_number')
            ->get();

        $latest_payment = DB::table('payment_orders')
            ->whereJsonContains('application_id', $application->id)
            ->orderByDesc('id')
            ->first();

        $latest_send_back = DB::table('application_workflow_assignments as awa')
            ->leftJoin('departments as d', 'd.id', '=', 'awa.department_id')
            ->where('awa.application_id', $application->id)
            ->where('awa.status', 'send_back')
            ->orderByDesc('awa.action_taken_at')
            ->orderByDesc('awa.id')
            ->select([
                'awa.id',
                'awa.step_number',
                'awa.step_type',
                'awa.department_id',
                'd.name as department_name',
                'awa.hierarchy_level',
                'awa.status',
                'awa.action_taken_by',
                'awa.action_taken_at',
                'awa.remarks',
            ])
            ->first();

        $basic = [
            'id'                => $application->id,
            'application_number' => $application->applicationId,
            'service_id'        => $application->service_id,
            'service_name'      => $application->service->service_title_or_description ?? null,
            'status'            => $application->status,
            'payment_status'    => $application->payment_status,
            'created_at'        => optional($application->created_at)->toDateTimeString(),
            'application_date'      => optional($application->application_date)->toDateTimeString(),
            'approved_at'       => $approved_at,
            'updated_at'        => optional($application->updated_at)->toDateTimeString(),
        ];

        $waiting_on = $this->compute_waiting_on($application, $latest_assignment, $approval_flow);

        $send_back_context = $latest_send_back ? [
            'was_sent_back'   => true,
            'remarks'         => $latest_send_back->remarks,
            'department_name' => $latest_send_back->department_name ?? null,
            'step_number'     => $latest_send_back->step_number ?? null,
            'sent_back_at'    => $latest_send_back->action_taken_at ?? null,
        ] : [
            'was_sent_back' => false,
            'remarks'       => null,
        ];

        $total_fee     = (float) ($application->total_fee ?? 0);
        $effective_fee = (float) ($application->effective_fee ?? 0);
        $paid_amount   = (float) ($application->paid_amount ?? 0);
        $payment_amount = $latest_payment ? (float) ($latest_payment->payment_amount ?? 0) : 0;
        $is_zero_fee   = $total_fee <= 0 && $effective_fee <= 0 && $paid_amount <= 0;

        $amount_to_pay = null;
        if (!$is_zero_fee && $application->payment_status === 'pending') {
            $amount_to_pay = $effective_fee ?: ($payment_amount ?: (max($total_fee - $paid_amount, 0) ?: null));
        }

        $payment_context = [
            'payment_status'        => $application->payment_status,
            'is_zero_fee'           => $is_zero_fee,
            'total_fee'             => $total_fee,
            'effective_fee'         => $effective_fee,
            'paid_amount'           => $paid_amount,
            'amount_to_pay'         => $amount_to_pay,
            'amount_to_pay_display' => $amount_to_pay ? ('₹' . rtrim(rtrim(number_format($amount_to_pay, 2, '.', ''), '0'), '.')) : null,
            'grn_number'            => $application->GRN_number,
            'latest_payment_status' => $latest_payment->payment_status ?? null,
            'latest_payment_amount' => $payment_amount,
        ];

        $certificate_context = [
            'certificate_available'  => !empty($application->NOC_certificate),
            'rejection_available'    => !empty($application->NOC_rejection_certificate),
            'noc_letter_number'      => $application->NOC_letter_number ?? null,
            'noc_letter_date'        => $application->NOC_letter_date ?? null,
            'noc_generation_date'    => $application->NOC_generationDate ?? null,
            'noc_expiry_date'        => $application->NOC_expiry_date ?? null,
            'external_valid_till'    => $application->external_valid_till ?? null,
            'external_noc_number'    => $application->external_noc_number ?? null,
        ];

        $renewal_context = [
            'renewal'                  => $application->renewal,
            'renewal_year'             => $application->renewalYear ?? null,
            'previous_application_id'  => $application->previous_application_id ?? null,
            'noc_expiry_date'          => $application->NOC_expiry_date ?? null,
            'previous_noc_expiry_date' => $application->PreviousNOCexpiryDate ?? null,
        ];

        $timeline = [[
            'type'  => 'application_created',
            'title' => 'Application created',
            'date'  => optional($application->created_at)->toDateTimeString(),
        ]];

        foreach ($recent_assignments->reverse()->values() as $a) {
            $timeline[] = [
                'type'            => 'assignment',
                'step_number'     => $a->step_number ?? null,
                'step_type'       => $a->step_type ?? null,
                'department_name' => $a->department_name ?? null,
                'status'          => $a->status ?? null,
                'remarks'         => $a->remarks ?? null,
                'action_taken_at' => $a->action_taken_at ?? null,
                'created_at'      => $a->created_at ?? null,
            ];
        }

        return [
            'application'       => $basic,
            'waiting_on'        => $waiting_on,
            'latest_assignment' => $latest_assignment,
            'recent_assignments' => $recent_assignments,
            'send_back_context' => $send_back_context,
            'payment_context'   => $payment_context,
            'certificate_context' => $certificate_context,
            'renewal_context'   => $renewal_context,
            'timeline'          => $timeline,
        ];
    }

    private function compute_waiting_on($application, $latest_assignment, $approval_flow): string
    {
        $status         = $application->status ?? '';
        $payment_status = $application->payment_status ?? '';

        if (in_array($status, ['approved', 'noc_issued', 'completed', 'certificate_issued'])) {
            return 'none';
        }
        if ($status === 'draft') return 'applicant';
        if ($status === 'send_back') return 'applicant';
        if ($payment_status === 'pending') return 'applicant';

        if ($latest_assignment) {
            $as = $latest_assignment->status ?? '';
            if ($as === 'pending' && empty($latest_assignment->action_taken_at)) return 'department';
            if ($as === 'send_back') return 'applicant';
        }

        return 'system';
    }

    private function call_application_ai(string $message, array $context): array
    {
        try {
            $base_url = rtrim(config('ai.base_url'), '/');

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
                return ['answer' => 'I could not prepare an answer.', 'answer_type' => 'application'];
            }

            return $response->json() ?: ['answer' => 'I could not prepare an answer.', 'answer_type' => 'application'];
        } catch (\Exception $e) {
            return ['answer' => 'I could not prepare an answer.', 'answer_type' => 'application'];
        }
    }

    private function ask_about_service(AiChatSession $session, int $service_id, string $message)
    {
        $service = ServiceMaster::query()
            ->where('id', $service_id)
            ->first(['id', 'service_title_or_description']);

        if (!$service) {
            return response()->json([
                'status' => false,
                'message' => 'Service not found.',
            ], 404);
        }

        $this->clear_session_meta($session, 'awaiting');

        $session->active_service_id = $service->id;
        $session->save();

        $doc_context = $this->build_service_document_context(
            $service->id,
            $service->service_title_or_description
        );

        $answer = $this->answer_service_document_question($message, $doc_context);
        $suggestions = $this->service_suggestions($doc_context);

        $this->save_chat_message(
            session: $session,
            role: 'assistant',
            message: $answer,
            answer_type: 'service'
        );

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $answer,
                'short_status' => $service->service_title_or_description,
                'answer_type' => 'service',
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => $suggestions,
            ],
        ]);
    }

    private function build_service_document_context(int $service_id, string $service_name): array
    {
        $file_types = ['file', 'upload', 'document', 'attachment', 'image', 'pdf'];

        $questions = ServiceQuestionnaire::where('service_id', $service_id)
            ->where('status', 1)
            ->whereIn('question_type', $file_types)
            ->orderBy('display_order')
            ->get([
                'id',
                'question_label',
                'question_type',
                'is_required',
                'display_rule',
                'condition_label',
            ]);

        $required = [];
        $optional = [];
        $conditional = [];

        foreach ($questions as $q) {
            $doc = [
                'label' => trim($q->question_label),
            ];

            $has_display_rule = !empty($q->display_rule) && $q->display_rule !== 'null';
            $has_condition_label = !empty($q->condition_label) && $q->condition_label !== 'null';

            if ($has_display_rule || $has_condition_label) {
                $conditional[] = $doc;
            } elseif ($q->is_required === 'yes' || $q->is_required == 1 || $q->is_required === true) {
                $required[] = $doc;
            } else {
                $optional[] = $doc;
            }
        }

        return [
            'service_id' => $service_id,
            'service_name' => $service_name,
            'required_documents' => $required,
            'optional_documents' => $optional,
            'conditional_documents' => $conditional,
            'source' => 'service_document_requirements',
        ];
    }

    private function call_ai_answer(string $message, string $data_scope, array $context): array
    {
        try {
            $base_url = rtrim(config('ai.base_url'), '/');

            $response = Http::timeout(60)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-AI-SECRET' => config('ai.secret'),
                ])
                ->post($base_url . '/api/ai/chat/answer', [
                    'message' => $message,
                    'data_scope' => $data_scope,
                    'context' => $context,
                ]);

            if ($response->failed()) {
                return ['answer' => 'I could not prepare an answer.', 'answer_type' => 'service'];
            }

            return $response->json() ?: ['answer' => 'I could not prepare an answer.', 'answer_type' => 'service'];
        } catch (\Exception $e) {
            return ['answer' => 'I could not prepare an answer.', 'answer_type' => 'service'];
        }
    }

    private function application_selection_response(AiChatSession $session)
    {
        $applications = UserServiceApplication::with([
            'service:id,service_title_or_description'
        ])
            ->where('user_id', $session->user_id)
            ->orderByDesc('id')
            ->limit(15)
            ->get([
                'id',
                'applicationId',
                'service_id',
                'status',
                'payment_status',
            ]);

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'requires_selection' => true,
                'selection_type' => 'application',
                'message' => 'Please select which application you want to ask about.',
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'options' => $applications->map(function ($app) {
                    return [
                        'id' => $app->id,
                        'title' => $app->applicationId ?: ('Application #' . $app->id),
                        'subtitle' => trim(($app->service->service_title_or_description ?? 'Service') . ' — ' . ($app->status ?? '')),
                    ];
                })->values(),
                'suggested_questions' => [
                    'Where is my application stuck?',
                    'What is my payment status?',
                ],
            ],
        ]);
    }

    private function service_selection_response(AiChatSession $session)
    {
        $this->set_session_meta($session, 'awaiting', 'service_name');

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => 'Please type the service name. Example: documents for professional tax.',
                'short_status' => null,
                'answer_type' => 'service',
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => [],
            ],
        ]);
    }

    private function call_ai_planner(AiChatSession $session, string $message): array
    {
        try {
            $recent_messages = AiChatMessage::where('ai_chat_session_id', $session->id)
                ->latest()
                ->limit(6)
                ->get()
                ->reverse()
                ->map(function ($msg) {
                    return [
                        'role' => $msg->role,
                        'message' => $msg->message,
                    ];
                })
                ->values()
                ->toArray();

            $base_url = rtrim(config('ai.base_url'), '/');

            $response = Http::timeout(60)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-AI-SECRET' => config('ai.secret'),
                ])
                ->post($base_url . '/api/ai/chat/plan', [
                    'message' => $message,
                    'active_application_id' => $session->active_application_id,
                    'active_service_id' => $session->active_service_id,
                    'recent_messages' => $recent_messages,
                ]);

            if ($response->status() === 429) {
                return ['data_scope' => 'RATE_LIMITED', 'confidence' => 0];
            }

            if ($response->failed()) {
                return ['data_scope' => 'UNKNOWN', 'confidence' => 0];
            }

            return $response->json() ?: ['data_scope' => 'UNKNOWN', 'confidence' => 0];
        } catch (\Exception $e) {
            return ['data_scope' => 'UNKNOWN', 'confidence' => 0];
        }
    }

    private function answer_account_question(AiChatSession $session, string $message)
    {
        $user = Auth::user();

        $context = [
            'account_context' => [
                'id' => $user->id,
                'name' => $user->name ?? null,
                'username' => $user->user_name ?? null,
                'email' => $user->email ?? null,
                'mobile' => $user->mobile_no ?? $user->mobile ?? null,
                'status' => $user->status ?? null,
                'created_at' => optional($user->created_at)->toDateTimeString(),
            ],
        ];

        $ai = $this->call_ai_answer(
            message: $message,
            data_scope: 'ACCOUNT_DATA',
            context: $context
        );

        $this->save_chat_message(
            session: $session,
            role: 'assistant',
            message: $ai['answer'] ?? '',
            answer_type: $ai['answer_type'] ?? 'account'
        );

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $ai['answer'] ?? 'I could not prepare an answer.',
                'short_status' => $ai['short_status'] ?? null,
                'answer_type' => $ai['answer_type'] ?? 'account',
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => [
                    'Show my applications',
                    'What is my registered mobile?',
                    'What is my account email?',
                ],
            ],
        ]);
    }

    private function answer_application_list(AiChatSession $session)
    {
        $applications = UserServiceApplication::with([
            'service:id,service_title_or_description'
        ])
            ->where('user_id', $session->user_id)
            ->orderByDesc('id')
            ->limit(10)
            ->get([
                'id',
                'applicationId',
                'service_id',
                'status',
                'payment_status',
                'created_at',
            ]);

        if ($applications->isEmpty()) {
            return $this->direct_answer(
                session: $session,
                answer: 'I could not find any applications in your account.',
                answer_type: 'application_list'
            );
        }

        $lines = $applications->map(function ($app, $index) {
            $number = $app->applicationId ?: ('Application #' . $app->id);
            $service = $app->service->service_title_or_description ?? 'Service';
            $status = $app->status ?? 'unknown';

            return ($index + 1) . ". {$number} — {$service} — {$status}";
        })->implode("\n");

        $answer = "Here are your recent applications:\n" . $lines;

        $this->save_chat_message(
            session: $session,
            role: 'assistant',
            message: $answer,
            answer_type: 'application_list'
        );

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $answer,
                'short_status' => 'Recent applications',
                'answer_type' => 'application_list',
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => [
                    'Where is my application stuck?',
                    'What is my payment status?',
                ],
            ],
        ]);
    }

    private function format_service_document_answer(array $doc_context): string
    {
        $required = $doc_context['required_documents'] ?? [];
        $optional = $doc_context['optional_documents'] ?? [];
        $conditional = $doc_context['conditional_documents'] ?? [];

        if (empty($required) && empty($optional) && empty($conditional)) {
            return 'I could not find configured document requirements for this service.';
        }

        $lines = [];

        $lines[] = 'For ' . ($doc_context['service_name'] ?? 'this service') . ', these documents are configured:';

        if (!empty($required)) {
            $lines[] = '';
            $lines[] = 'Required documents:';

            foreach ($required as $doc) {
                $lines[] = '- ' . ($doc['label'] ?? 'Document');
            }
        }

        if (!empty($optional)) {
            $lines[] = '';
            $lines[] = 'Optional documents:';

            foreach ($optional as $doc) {
                $lines[] = '- ' . ($doc['label'] ?? 'Document');
            }
        }

        if (!empty($conditional)) {
            $lines[] = '';
            $lines[] = 'Conditional documents:';

            foreach ($conditional as $doc) {
                $lines[] = '- ' . ($doc['label'] ?? 'Document');
            }
        }

        return implode("\n", $lines);
    }

    private function answer_service_document_question(string $message, array $doc_context): string
    {
        $lower = Str::lower(trim($message));

        $explicit_required = Str::contains($lower, ['required documents', 'show required', 'only required']);
        $explicit_optional = Str::contains($lower, ['optional documents', 'show optional', 'only optional']);
        $explicit_conditional = Str::contains($lower, ['conditional documents', 'show conditional', 'only conditional']);

        if ($explicit_required && !$explicit_optional && !$explicit_conditional) {
            return $this->format_document_group_answer(
                $doc_context,
                'required_documents',
                'Required documents'
            );
        }

        if ($explicit_optional && !$explicit_required && !$explicit_conditional) {
            return $this->format_document_group_answer(
                $doc_context,
                'optional_documents',
                'Optional documents'
            );
        }

        if ($explicit_conditional && !$explicit_required && !$explicit_optional) {
            return $this->format_document_group_answer(
                $doc_context,
                'conditional_documents',
                'Conditional documents'
            );
        }

        // default: show full list unless user asked for a specific group
        if ($this->is_full_document_list_question($message)) {
            return $this->format_service_document_answer($doc_context);
        }

        // check if user is asking about a specific document
        $matched = $this->find_matching_document($message, $doc_context);

        if ($matched) {
            $answer = $matched['label'] . ' is configured for this service as a ' . strtolower($matched['group']) . '.';

            if ($matched['group'] === 'Conditional document') {
                $answer .= ' It may be required only in applicable cases.';
            }

            $answer .= ' Detailed explanation for this document is not available in the system.';

            return $answer;
        }

        return $this->format_service_document_answer($doc_context);
    }

    private function find_matching_document(string $message, array $doc_context): ?array
    {
        $groups = [
            'required_documents' => 'Required document',
            'optional_documents' => 'Optional document',
            'conditional_documents' => 'Conditional document',
        ];

        $message_normalized = $this->normalize_match_text($message);

        $best = null;
        $best_score = 0;

        foreach ($groups as $key => $group_name) {
            foreach (($doc_context[$key] ?? []) as $doc) {
                $label = $doc['label'] ?? '';
                $label_normalized = $this->normalize_match_text($label);

                if (!$label_normalized) {
                    continue;
                }

                $score = 0;

                if ($message_normalized === $label_normalized) {
                    $score += 100;
                }

                if (Str::contains($message_normalized, $label_normalized)) {
                    $score += 80;
                }

                $label_tokens = collect(explode(' ', $label_normalized))
                    ->filter(fn($t) => strlen($t) >= 4)
                    ->values();

                foreach ($label_tokens as $token) {
                    if (Str::contains($message_normalized, $token)) {
                        $score += 15;
                    }
                }

                if ($score > $best_score) {
                    $best_score = $score;
                    $best = [
                        'label' => $label,
                        'group' => $group_name,
                        'score' => $score,
                    ];
                }
            }
        }

        return $best_score >= 15 ? $best : null;
    }

    private function message_matches_document_label(string $message, array $doc_context): bool
    {
        return $this->find_matching_document($message, $doc_context) !== null;
    }

    private function normalize_match_text(string $text): string
    {
        $text = Str::lower($text);

        $text = str_replace([
            'documents',
            'document',
            'docs',
            'doc',
            'what is',
            'what are',
            'tell me about',
            'explain',
            'meaning of',
            '?',
            '.',
            ',',
            ':',
            ';',
            '/',
            '-',
            '_',
            '(',
            ')',
        ], ' ', $text);

        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function format_document_group_answer(array $doc_context, string $key, string $title): string
    {
        $docs = $doc_context[$key] ?? [];

        if (empty($docs)) {
            return 'No ' . strtolower($title) . ' are configured for this service.';
        }

        $lines = [];
        $lines[] = $title . ' for ' . ($doc_context['service_name'] ?? 'this service') . ':';

        foreach ($docs as $doc) {
            $lines[] = '- ' . ($doc['label'] ?? 'Document');
        }

        return implode("\n", $lines);
    }

    private function service_suggestions(array $doc_context): array
    {
        $suggestions = [];

        if (!empty($doc_context['required_documents'])) {
            $suggestions[] = 'Show required documents';
        }

        if (!empty($doc_context['optional_documents'])) {
            $suggestions[] = 'Show optional documents';
        }

        if (!empty($doc_context['conditional_documents'])) {
            $suggestions[] = 'Show conditional documents';
        }

        return $suggestions;
    }

    private function extract_application_number_from_message(string $message): ?string
    {
        preg_match('/\bSPC-[A-Z0-9]+-[A-Z0-9]+\b/i', $message, $matches);

        return $matches[0] ?? null;
    }

    private function resolve_application_from_message(string $message, int $user_id): ?UserServiceApplication
    {
        $application_number = $this->extract_application_number_from_message($message);

        if (!$application_number) {
            return null;
        }

        return UserServiceApplication::where('user_id', $user_id)
            ->where(function ($query) use ($application_number) {
                $query->where('applicationId', $application_number)
                    ->orWhere('applicationId', strtoupper($application_number))
                    ->orWhere('applicationId', strtolower($application_number));
            })
            ->first();
    }

    private function is_service_document_message(string $message): bool
    {
        $text = Str::lower(trim($message));

        if (Str::startsWith($text, 'for ')) {
            return true;
        }

        return Str::contains($text, [
            'document',
            'documents',
            'doc',
            'docs',
            'upload',
            'file',
            'files',
        ]);
    }

    private function normalize_service_search_text(string $text): string
    {
        $text = Str::lower($text);

        $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function clean_service_search_query(string $text): ?string
    {
        $text = $this->normalize_service_search_text($text);

        $text = preg_replace('/\b(what|which|do|does|i|we|need|needed|required|require|documents?|docs?|files?|upload|submit|for|this|that|service|please|tell|me|about|is|are|the|a|an)\b/i', ' ', $text);

        $text = trim(preg_replace('/\s+/', ' ', $text));

        return strlen($text) >= 3 ? $text : null;
    }

    private function get_session_meta(AiChatSession $session, string $key, $default = null)
    {
        $meta = $session->meta ?: [];
        return $meta[$key] ?? $default;
    }

    private function set_session_meta(AiChatSession $session, string $key, $value): void
    {
        $meta = $session->meta ?: [];
        $meta[$key] = $value;
        $session->meta = $meta;
        $session->save();
    }

    private function clear_session_meta(AiChatSession $session, string $key): void
    {
        $meta = $session->meta ?: [];
        unset($meta[$key]);
        $session->meta = $meta;
        $session->save();
    }

    private function extract_explicit_service_query(string $message): ?string
    {
        $text = $this->normalize_search_text($message);

        if (preg_match('/\bfor\s+(.+)$/i', $text, $m)) {
            return $this->clean_search_query($m[1]);
        }

        return null;
    }

    private function clean_search_query(string $text): ?string
    {
        $text = $this->normalize_search_text($text);

        $stopWords = [
            'what',
            'which',
            'do',
            'does',
            'did',
            'i',
            'we',
            'you',
            'need',
            'needed',
            'required',
            'require',
            'want',
            'document',
            'documents',
            'doc',
            'docs',
            'file',
            'files',
            'upload',
            'submit',
            'for',
            'this',
            'that',
            'service',
            'please',
            'tell',
            'me',
            'about',
            'is',
            'are',
            'the',
            'a',
            'an',
            'give',
            'show',
            'list',
            'of'
        ];

        $tokens = collect(explode(' ', $text))
            ->filter(fn($token) => strlen($token) >= 3)
            ->reject(fn($token) => in_array($token, $stopWords))
            ->values();

        if ($tokens->isEmpty()) {
            return null;
        }

        return $tokens->implode(' ');
    }

    private function resolve_service_by_query(string $query): array
    {
        $query = $this->clean_search_query($query);

        if (!$query) {
            return ['status' => 'not_found'];
        }

        $queryTokens = collect(explode(' ', $this->normalize_search_text($query)))
            ->filter(fn($token) => strlen($token) >= 3)
            ->unique()
            ->values();

        if ($queryTokens->isEmpty()) {
            return ['status' => 'not_found'];
        }

        $services = ServiceMaster::query()
            ->get(['id', 'service_title_or_description']);

        $scored = $services->map(function ($service) use ($query, $queryTokens) {
            $title = $this->normalize_search_text($service->service_title_or_description);

            $titleTokens = collect(explode(' ', $title))
                ->filter(fn($token) => strlen($token) >= 3)
                ->values();

            $score = 0;
            $matchedTokens = 0;

            if (Str::contains($title, $this->normalize_search_text($query))) {
                $score += 120;
            }

            foreach ($queryTokens as $queryToken) {
                $best = 0;

                foreach ($titleTokens as $titleToken) {
                    if ($queryToken === $titleToken) {
                        $best = max($best, 40);
                        continue;
                    }

                    if (Str::contains($titleToken, $queryToken) || Str::contains($queryToken, $titleToken)) {
                        $best = max($best, 25);
                        continue;
                    }

                    $distance = levenshtein($queryToken, $titleToken);
                    $allowedDistance = max(1, (int) floor(strlen($queryToken) * 0.35));

                    if ($distance <= $allowedDistance) {
                        $best = max($best, 22);
                        continue;
                    }

                    if (metaphone($queryToken) && metaphone($queryToken) === metaphone($titleToken)) {
                        $best = max($best, 15);
                    }
                }

                if ($best > 0) {
                    $matchedTokens++;
                    $score += $best;
                }
            }

            if ($matchedTokens === $queryTokens->count()) {
                $score += 60;
            }

            return [
                'id' => $service->id,
                'title' => $service->service_title_or_description,
                'subtitle' => 'Service',
                'score' => $score,
            ];
        })
            ->filter(fn($item) => $item['score'] >= 40)
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            return ['status' => 'not_found'];
        }

        $top = $scored->first();
        $second = $scored->get(1);

        // auto-select only when top result is clearly ahead
        if (!$second || ($top['score'] >= 140 && ($top['score'] - $second['score']) >= 60)) {
            return [
                'status' => 'found',
                'service_id' => $top['id'],
            ];
        }

        return [
            'status' => 'multiple',
            'options' => $scored
                ->take(5)
                ->map(fn($item) => [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'subtitle' => 'Service',
                ])
                ->values()
                ->toArray(),
        ];
    }

    private function is_greeting(string $message): bool
    {
        return in_array(Str::lower(trim($message)), ['hi', 'hii', 'hello', 'hey', 'namaste']);
    }

    private function is_account_question(string $message): bool
    {
        return Str::contains(Str::lower($message), [
            'my username',
            'my user name',
            'my email',
            'my mobile',
            'my account',
            'who am i',
        ]);
    }

    private function is_capability_question(string $message): bool
    {
        $text = Str::lower($message);

        return Str::contains($text, [
            'what can you do',
            'what can you help',
            'what things',
            'help me with',
        ]);
    }

    private function is_application_list_question(string $message): bool
    {
        return Str::contains(Str::lower($message), [
            'my applications',
            'application list',
            'show applications',
            'show my application',
            'list applications',
            'another application',
        ]);
    }

    private function is_application_question(string $message): bool
    {
        return Str::contains(Str::lower($message), [
            'renew',
            'renewal',
            'validity',
            'expire',
            'expiry',
            'status',
            'payment',
            'paid',
            'fee',
            'grn',
            'certificate',
            'noc',
            'created',
            'submitted',
            'approved',
            'stuck',
            'pending',
            'send back',
            'sent back',
            'remarks',
            'timeline',
            'application',
        ]);
    }

    private function is_service_document_question(string $message): bool
    {
        return Str::contains(Str::lower($message), [
            'document',
            'documents',
            'doc',
            'docs',
            'upload',
            'file',
            'files',
            'required documents',
            'which documents',
        ]);
    }

    private function handle_service_document_question(AiChatSession $session, string $message)
    {
        $serviceQuery = $this->extract_service_query($message);

        // service name found inside the message e.g. "documents for professional tax"
        if ($serviceQuery) {
            return $this->handle_service_query($session, $serviceQuery);
        }

        // no active service — ask user to type one
        if (!$session->active_service_id) {
            $this->set_session_meta($session, 'awaiting', 'service_name');

            return $this->direct_answer(
                session: $session,
                answer: 'Please type the service name. Example: professional tax, partnership firm, water connection.',
                answer_type: 'service',
                suggested_questions: []
            );
        }

        return $this->ask_about_service(
            session: $session,
            service_id: (int) $session->active_service_id,
            message: $message
        );
    }

    private function handle_service_query(AiChatSession $session, string $query)
    {
        $resolved = $this->resolve_service_by_query($query);

        if ($resolved['status'] === 'found') {
            $serviceId = (int) $resolved['service_id'];

            $session->active_service_id = $serviceId;
            $this->clear_session_meta($session, 'awaiting');
            $session->save();

            return $this->ask_about_service(
                session: $session,
                service_id: $serviceId,
                message: 'Which documents are required for this service?'
            );
        }

        if ($resolved['status'] === 'multiple') {
            $this->set_session_meta($session, 'awaiting', 'service_name');

            return response()->json([
                'status' => true,
                'data' => [
                    'session_id' => $session->id,
                    'requires_selection' => true,
                    'selection_type' => 'service',
                    'message' => 'I found multiple matching services. Please select the correct one.',
                    'active_application_id' => $session->active_application_id,
                    'active_service_id' => $session->active_service_id,
                    'options' => $resolved['options'],
                    'suggested_questions' => [],
                ],
            ]);
        }

        $this->set_session_meta($session, 'awaiting', 'service_name');

        return $this->direct_answer(
            session: $session,
            answer: 'I could not find that service. Please type the service name again.',
            answer_type: 'service',
            suggested_questions: []
        );
    }

    private function extract_service_query(string $message): ?string
    {
        $text = $this->normalize_search_text($message);

        if (preg_match('/\bfor\s+(.+)$/i', $text, $m)) {
            return $this->clean_service_query($m[1]);
        }

        $clean = $this->clean_service_query($text);

        if ($this->is_service_document_question($message) && $clean) {
            return $clean;
        }

        return null;
    }

    private function normalize_search_text(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function clean_service_query(string $text): ?string
    {
        $text = $this->normalize_search_text($text);

        $stopWords = [
            'what',
            'which',
            'do',
            'does',
            'did',
            'i',
            'we',
            'you',
            'need',
            'needed',
            'required',
            'require',
            'want',
            'document',
            'documents',
            'doc',
            'docs',
            'file',
            'files',
            'upload',
            'submit',
            'for',
            'this',
            'that',
            'service',
            'please',
            'tell',
            'me',
            'about',
            'is',
            'are',
            'the',
            'a',
            'an',
            'give',
            'show',
            'list',
            'of',
        ];

        $tokens = collect(explode(' ', $text))
            ->filter(fn($token) => strlen($token) >= 3)
            ->reject(fn($token) => in_array($token, $stopWords))
            ->values();

        return $tokens->isEmpty() ? null : $tokens->implode(' ');
    }

    private function is_service_group_command(string $message): bool
    {
        $text = Str::lower(trim($message));

        return in_array($text, [
            'show required documents',
            'show optional documents',
            'show conditional documents',
            'required documents',
            'optional documents',
            'conditional documents',
        ]);
    }

    private function is_full_document_list_question(string $message): bool
    {
        $text = Str::lower(trim($message));

        if (Str::contains($text, [
            'continue with this selected service',
            'documents for',
            'document for',
            'docs for',
            'which documents',
            'what documents',
            'all documents',
            'documents required',
            'required documents',
            'documents i need',
            'documents need',
            'document list',
        ])) {
            return true;
        }

        return false;
    }
}
