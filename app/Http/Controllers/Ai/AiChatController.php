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
        $user = User::find(8247);

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

        $user = User::find(8247);

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

        if ($request->filled('application_id')) {
            $pending_intent = $this->get_session_meta($session, 'pending_intent');
            $pending_message = $this->get_session_meta($session, 'pending_message');

            $this->clear_pending_context($session);

            $intent = $pending_intent ?: $this->detect_ai_intent($pending_message ?: $message);
            $answer_message = $pending_message ?: $this->intent_default_application_question($intent);

            return $this->answer_application_intent(
                request: $request,
                session: $session,
                application_id: (int) $request->application_id,
                intent: $intent,
                message: $answer_message
            );
        }

        if ($request->filled('service_id')) {
            $pending_message = $this->get_session_meta($session, 'pending_message');

            $this->clear_pending_context($session);

            return $this->ask_about_service(
                session: $session,
                service_id: (int) $request->service_id,
                message: $pending_message ?: 'Which documents are required for this service?'
            );
        }

        $typed_application_number = $this->extract_application_number_from_message($message);

        if ($typed_application_number) {
            $application = $this->resolve_application_from_message($message, $user->id);

            $this->clear_pending_context($session);

            if (!$application) {
                return $this->direct_answer(
                    session: $session,
                    answer: 'I could not find this application in your account. Please check the application number or select it from your application list.',
                    answer_type: 'application',
                    suggested_questions: ['Show my applications']
                );
            }

            $intent = $this->detect_ai_intent($message);

            return $this->answer_application_intent(
                request: $request,
                session: $session,
                application_id: (int) $application->id,
                intent: $intent,
                message: $message
            );
        }

        $intent = $this->detect_ai_intent($message);

        if ($intent === 'APPLICATION_LIST') {
            $this->clear_pending_context($session);

            return $this->answer_application_list(
                session: $session,
                filter_status: $this->detect_application_list_filter($message)
            );
        }

        if ($intent === 'ACCOUNT_DATA') {
            $this->clear_pending_context($session);

            return $this->answer_account_question($session, $message);
        }

        if ($intent === 'GENERAL_HELP') {
            $this->clear_pending_context($session);

            return $this->answer_general_help_question($session, $message);
        }

        if ($this->intent_requires_application($intent)) {
            $this->clear_session_meta($session, 'awaiting');

            if ($session->active_application_id) {
                return $this->answer_application_intent(
                    request: $request,
                    session: $session,
                    application_id: (int) $session->active_application_id,
                    intent: $intent,
                    message: $message
                );
            }

            $this->set_session_meta($session, 'awaiting', 'application_selection');
            $this->set_session_meta($session, 'pending_intent', $intent);
            $this->set_session_meta($session, 'pending_message', $message);

            return $this->application_selection_response($session);
        }

        if ($this->intent_requires_service($intent)) {
            $service_query = $this->extract_service_query($message);

            if ($service_query) {
                $this->set_session_meta($session, 'pending_intent', $intent);
                $this->set_session_meta($session, 'pending_message', $message);

                return $this->handle_service_query($session, $service_query);
            }

            if ($session->active_service_id) {
                return $this->ask_about_service(
                    session: $session,
                    service_id: (int) $session->active_service_id,
                    message: $message
                );
            }

            $service_id = $this->get_active_application_service_id($session);

            if ($service_id) {
                $session->active_service_id = $service_id;
                $session->save();

                return $this->ask_about_service(
                    session: $session,
                    service_id: (int) $service_id,
                    message: $message
                );
            }

            $this->set_session_meta($session, 'awaiting', 'service_name');
            $this->set_session_meta($session, 'pending_intent', $intent);
            $this->set_session_meta($session, 'pending_message', $message);

            return $this->direct_answer(
                session: $session,
                answer: 'Please type the service name. Example: professional tax, partnership firm, water connection.',
                answer_type: 'service',
                suggested_questions: []
            );
        }

        if ($this->get_session_meta($session, 'awaiting') === 'service_name') {
            return $this->handle_service_query($session, $message);
        }

        return $this->answer_general_help_question($session, $message);
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
                    'What is my application status?',
                    'What should I do next?',
                    'What is my payment status?',
                    'Is my certificate generated?',
                    'Show the details of my application.',
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
        $user = User::find(8247);

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

    private function detect_ai_intent(string $message): string
    {
        $text = Str::lower(trim($message));

        if ($this->is_application_list_question($message)) {
            return 'APPLICATION_LIST';
        }

        if ($this->is_account_question($message)) {
            return 'ACCOUNT_DATA';
        }

        if ($this->is_capability_question($message) || $this->is_greeting($message)) {
            return 'GENERAL_HELP';
        }

        if (Str::contains($text, [
            'how do i apply',
            'how to apply',
            'application process',
            'what is the process',
            'what happens after',
            'contact support',
            'how can i contact',
            'why is my application taking so long',
            'how do i track',
            'how do i download',
            'how do i update',
        ])) {
            return 'GENERAL_HELP';
        }

        if (Str::contains($text, [
            'payment',
            'paid',
            'pay',
            'fee',
            'amount',
            'retry the payment',
            'payment fail',
            'payment failed',
            'payment pending',
            'how much',
        ])) {
            return 'PAYMENT_STATUS';
        }

        if (Str::contains($text, [
            'sent back',
            'send back',
            'send-back',
            'defect',
            'defects',
            'remarks',
            'correction',
            'corrections',
            'resubmit',
            'what should i do next',
            'what do i need to do',
        ])) {
            return 'SEND_BACK_REASON';
        }

        if (Str::contains($text, [
            'timeline',
            'actions taken',
            'action taken',
            'when was my application submitted',
            'when did i submit',
            'who is currently handling',
            'currently handling',
            'which office',
            'processing my application',
        ])) {
            return 'APPLICATION_TIMELINE';
        }

        if (Str::contains($text, [
            'certificate',
            'noc',
            'download certificate',
            'issued',
            'issue date',
            'expire',
            'expiry',
            'valid till',
            'validity',
        ])) {
            return 'CERTIFICATE_STATUS';
        }

        if (Str::contains($text, [
            'renew',
            'renewal',
            'eligible for renewal',
            'renewal process',
        ])) {
            return 'RENEWAL_STATUS';
        }

        if (Str::contains($text, [
            'which documents are required for this service',
            'documents required for this service',
            'service documents',
            'documents for',
            'document for',
            'required documents for',
        ])) {
            return 'SERVICE_DOCUMENTS';
        }

        if (Str::contains($text, [
            'documents verified',
            'document verified',
            'documents missing',
            'document missing',
            'upload more documents',
            're-upload',
            'reupload',
            'document rejected',
            'which document was rejected',
        ])) {
            return 'APPLICATION_DOCUMENT_STATUS';
        }

        if (Str::contains($text, [
            'status',
            'stuck',
            'pending',
            'approved',
            'rejected',
            'under review',
            'processed',
            'completed',
            'latest update',
            'application details',
            'details of my application',
            'what service did i apply',
            'application number',
        ])) {
            return 'APPLICATION_STATUS';
        }

        return 'GENERAL_HELP';
    }

    private function intent_requires_application(?string $intent): bool
    {
        return in_array($intent, [
            'APPLICATION_STATUS',
            'PAYMENT_STATUS',
            'CERTIFICATE_STATUS',
            'RENEWAL_STATUS',
            'SEND_BACK_REASON',
            'APPLICATION_TIMELINE',
            'APPLICATION_DOCUMENT_STATUS',
        ], true);
    }

    private function intent_requires_service(?string $intent): bool
    {
        return in_array($intent, [
            'SERVICE_DOCUMENTS',
        ], true);
    }

    private function intent_default_application_question(?string $intent): string
    {
        return match ($intent) {
            'PAYMENT_STATUS' => 'What is the payment status of this application?',
            'CERTIFICATE_STATUS' => 'What is the certificate or NOC status of this application?',
            'RENEWAL_STATUS' => 'Can this application or certificate be renewed?',
            'SEND_BACK_REASON' => 'Why was this application sent back and what should I do next?',
            'APPLICATION_TIMELINE' => 'Show the timeline and latest update of this application.',
            'APPLICATION_DOCUMENT_STATUS' => 'Are there any missing, rejected, or pending documents for this application?',
            default => 'What is the current status of this application?',
        };
    }

    private function answer_application_intent(
        Request $request,
        AiChatSession $session,
        int $application_id,
        ?string $intent,
        string $message
    ) {
        return $this->ask_about_application(
            request: $request,
            session: $session,
            application_id: $application_id,
            message: $message
        );
    }

    private function is_greeting(string $message): bool
    {
        return in_array(Str::lower(trim($message)), [
            'hi',
            'hii',
            'hello',
            'hey',
            'namaste',
        ], true);
    }

    private function is_capability_question(string $message): bool
    {
        $text = Str::lower($message);

        return Str::contains($text, [
            'what can you answer',
            'what can you do',
            'how can you help',
            'what you can answer',
            'what can i ask',
            'help me',
        ]);
    }

    private function is_account_question(string $message): bool
    {
        $text = Str::lower($message);

        return Str::contains($text, [
            'my username',
            'my user name',
            'my email',
            'my mobile',
            'my account',
            'who am i',
        ]);
    }

    private function is_application_list_question(string $message): bool
    {
        $text = Str::lower($message);

        return Str::contains($text, [
            'my applications',
            'my application list',
            'application list',
            'show applications',
            'show my application',
            'show my applications',
            'show my recent applications',
            'recent applications',
            'list applications',
            'list my applications',
            'other application',
            'other applications',
            'applications by me',
            'application by me',
            'all applications',
            'all my applications',
            'which applications are pending',
            'which applications are approved',
            'which applications are rejected',
            'which applications are under review',
        ]);
    }

    private function detect_application_list_filter(string $message): ?string
    {
        $text = Str::lower($message);

        if (Str::contains($text, ['pending'])) {
            return 'pending';
        }

        if (Str::contains($text, ['approved', 'completed', 'noc issued', 'certificate issued'])) {
            return 'approved';
        }

        if (Str::contains($text, ['rejected', 'refused'])) {
            return 'rejected';
        }

        if (Str::contains($text, ['under review', 'processing', 'in process'])) {
            return 'under_review';
        }

        return null;
    }

    private function answer_application_list(AiChatSession $session, ?string $filter_status = null)
    {
        $this->clear_pending_context($session);

        $applications = UserServiceApplication::query()
            ->where('user_id', $session->user_id)
            ->latest('id')
            ->limit(100)
            ->get();

        if ($filter_status) {
            $applications = $applications
                ->filter(fn($application) => $this->application_matches_filter($application, $filter_status))
                ->values();
        }

        if ($applications->isEmpty()) {
            return $this->direct_answer(
                session: $session,
                answer: $filter_status
                    ? 'I could not find any matching applications in your account.'
                    : 'I could not find any applications in your account.',
                answer_type: 'application',
                suggested_questions: []
            );
        }

        $options = $applications->take(10)->map(function ($application) {
            $application_number = $this->get_application_display_number($application);
            $service_name = $this->get_application_service_name($application);
            $status = $this->get_application_status_text($application);

            return [
                'id' => $application->id,
                'title' => $application_number,
                'subtitle' => $service_name . ' - ' . $status,
            ];
        })->values()->toArray();

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'requires_selection' => true,
                'selection_type' => 'application',
                'message' => 'Here are your recent applications. Please select one.',
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'options' => $options,
                'suggested_questions' => [],
            ],
        ]);
    }

    private function application_matches_filter($application, string $filter_status): bool
    {
        $status = Str::lower($this->get_application_status_text($application));

        return match ($filter_status) {
            'pending' => Str::contains($status, ['pending', 'send_back', 'sent back']),
            'approved' => Str::contains($status, ['approved', 'completed', 'noc_issued', 'certificate']),
            'rejected' => Str::contains($status, ['rejected', 'refused']),
            'under_review' => Str::contains($status, ['review', 'process', 'submitted']),
            default => true,
        };
    }

    private function get_application_display_number($application): string
    {
        return (string) (
            $application->applicationId
            ?? $application->application_id
            ?? $application->application_number
            ?? $application->application_no
            ?? $application->application_reference_no
            ?? 'Application #' . $application->id
        );
    }

    private function get_application_service_name($application): string
    {
        return (string) (
            $application->service->service_title_or_description
            ?? $application->service_title_or_description
            ?? $application->service_name
            ?? 'Application'
        );
    }

    private function get_application_status_text($application): string
    {
        return (string) (
            $application->application_status
            ?? $application->status
            ?? $application->current_status
            ?? 'status not available'
        );
    }

    private function extract_application_number_from_message(string $message): ?string
    {
        if (preg_match('/\b[A-Z]{2,5}(?:-[A-Z0-9]+){1,5}\b/i', $message, $match)) {
            return strtoupper(trim($match[0]));
        }

        return null;
    }

    private function resolve_application_from_message(string $message, int $user_id)
    {
        $application_number = $this->extract_application_number_from_message($message);

        if (!$application_number) {
            return null;
        }

        $normalized_number = strtoupper(str_replace([' ', '_'], ['', '-'], $application_number));

        return UserServiceApplication::query()
            ->where('user_id', $user_id)
            ->where(function ($query) use ($normalized_number) {
                $query->whereRaw("REPLACE(UPPER(`applicationID`), ' ', '') = ?", [$normalized_number])
                    ->orWhereRaw("UPPER(`applicationID`) LIKE ?", ['%' . $normalized_number . '%']);
            })
            ->latest('id')
            ->first();
    }

    private function extract_service_query(string $message): ?string
    {
        $text = $this->normalize_search_text($message);

        if (preg_match('/\bfor\s+(.+)$/i', $text, $match)) {
            return $this->clean_service_query($match[1]);
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

        $stop_words = [
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
            ->reject(fn($token) => in_array($token, $stop_words, true))
            ->values();

        return $tokens->isEmpty() ? null : $tokens->implode(' ');
    }

    private function get_active_application_service_id(AiChatSession $session): ?int
    {
        if (!$session->active_application_id) {
            return null;
        }

        $application = UserServiceApplication::query()
            ->where('id', $session->active_application_id)
            ->where('user_id', $session->user_id)
            ->first();

        if (!$application || empty($application->service_id)) {
            return null;
        }

        return (int) $application->service_id;
    }

    private function answer_general_help_question(AiChatSession $session, string $message)
    {
        return $this->direct_answer(
            session: $session,
            answer: 'I can help with application status, payment, documents, certificate/NOC, renewal, send-back remarks, timelines, and general process questions.',
            answer_type: 'general',
            suggested_questions: [
                'Show my applications',
                'Where is my application stuck?',
                'Which documents are required for this service?',
                'How do I download my certificate?',
            ]
        );
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

    private function clear_pending_context(AiChatSession $session): void
    {
        $meta = $session->meta ?: [];

        unset(
            $meta['awaiting'],
            $meta['pending_intent'],
            $meta['pending_message'],
            $meta['pending_application_question']
        );

        $session->meta = $meta;
        $session->save();
    }
}
