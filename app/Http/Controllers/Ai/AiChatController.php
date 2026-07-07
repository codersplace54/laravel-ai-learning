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
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiChatController extends Controller
{
    public function options()
    {
        $user = User::find(16066);

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
            'active_application_id' => 'nullable|integer',
            'active_service_id' => 'nullable|integer',
        ]);

        $user = User::find(16066);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated user.',
            ], 401);
        }

        $message = trim($request->message);

        $session = $this->get_or_create_session($request, $user->id);

        if ($request->filled('application_id')) {
            $application = UserServiceApplication::where('id', $request->application_id)
                ->where('user_id', $user->id)
                ->first();

            if ($application) {
                $session->active_application_id = $application->id;
                $session->active_service_id = $application->service_id;
            }
        }

        if ($request->filled('service_id')) {
            $session->active_service_id = $request->service_id;
        }

        $session->save();

        $this->save_chat_message(
            session: $session,
            role: 'user',
            message: $message
        );

        $planner = $this->call_ai_planner($session, $message);

        $data_scope = $planner['data_scope'] ?? 'UNKNOWN';

        /*
    |--------------------------------------------------------------------------
    | NO DATA: hi, what is your name, what can you do
    |--------------------------------------------------------------------------
    */
        if ($data_scope === 'NO_DATA') {
            $answer = $planner['direct_answer'] ?: 'Hi! I am your SWAAGAT AI Assistant. I can help with applications, payments, documents, certificates, renewal, and timelines.';

            return $this->direct_answer(
                session: $session,
                answer: $answer,
                answer_type: 'general',
                suggested_questions: [
                    'What can you help me with?',
                    'Show my applications',
                    'Which documents are required?',
                    'Check my payment status',
                ]
            );
        }

        /*
    |--------------------------------------------------------------------------
    | ACCOUNT DATA: username, profile, mobile, email
    |--------------------------------------------------------------------------
    */
        if ($data_scope === 'ACCOUNT_DATA') {
            return $this->answer_account_question($session, $message);
        }

        /*
    |--------------------------------------------------------------------------
    | APPLICATION LIST
    |--------------------------------------------------------------------------
    */
        if ($data_scope === 'APPLICATION_LIST') {
            return $this->answer_application_list($session);
        }

        /*
    |--------------------------------------------------------------------------
    | APPLICATION DATA
    |--------------------------------------------------------------------------
    */
        if ($data_scope === 'APPLICATION_DATA') {
            $application_id = $request->application_id ?: $session->active_application_id;

            if (!$application_id) {
                return $this->application_selection_response($session);
            }

            return $this->ask_about_application(
                request: $request,
                session: $session,
                application_id: (int) $application_id,
                message: $message
            );
        }

        /*
    |--------------------------------------------------------------------------
    | SERVICE DATA
    |--------------------------------------------------------------------------
    */
        if (in_array($data_scope, ['SERVICE_DATA', 'SERVICE_SEARCH', 'RAG_KNOWLEDGE'])) {

            /*
     * 1. If user selected a service from UI, trust that first.
     * Do NOT re-resolve old typed message, otherwise selection loop happens.
     */
            if ($request->filled('service_id')) {
                $service_id = (int) $request->service_id;

                $session->active_service_id = $service_id;
                $session->save();

                return $this->ask_about_service(
                    session: $session,
                    service_id: $service_id,
                    message: $message
                );
            }

            /*
     * 2. If user typed a service name, try resolving from message.
     * Example: "documents for contract labour"
     */
            $resolved = $this->resolve_service_from_message($message);

            if ($resolved['status'] === 'found') {
                $service_id = (int) $resolved['service_id'];

                $session->active_service_id = $service_id;
                $session->save();

                return $this->ask_about_service(
                    session: $session,
                    service_id: $service_id,
                    message: $message
                );
            }

            if ($resolved['status'] === 'multiple') {
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
                        'suggested_questions' => ['Which documents are required?'],
                    ],
                ]);
            }

            /*
     * 3. If no service name in message, use active service from session.
     * Example: user says "which documents are required?"
     */
            $service_id = $session->active_service_id;

            /*
     * 4. If active application exists, use its service.
     */
            if (!$service_id && $session->active_application_id) {
                $application = UserServiceApplication::where('id', $session->active_application_id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($application) {
                    $service_id = $application->service_id;
                    $session->active_service_id = $service_id;
                    $session->save();
                }
            }

            if (!$service_id) {
                return $this->service_selection_response($session);
            }

            return $this->ask_about_service(
                session: $session,
                service_id: (int) $service_id,
                message: $message
            );
        }

        /*
    |--------------------------------------------------------------------------
    | UNKNOWN
    |--------------------------------------------------------------------------
    */
        return $this->direct_answer(
            session: $session,
            answer: 'I can help with your SWAAGAT account, applications, payments, documents, certificates, renewal, and timelines. Please tell me what you want to check.',
            answer_type: 'unknown',
            suggested_questions: [
                'Show my applications',
                'What is my username?',
                'Which documents are required?',
                'Where is my application stuck?',
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
        $application = UserServiceApplication::where('id', $application_id)
            ->where('user_id', $session->user_id)
            ->first();

        if (!$application) {
            return response()->json([
                'status' => false,
                'message' => 'Application not found or not allowed.',
            ], 404);
        }

        $session->active_application_id = $application->id;
        $session->active_service_id = $application->service_id;
        $session->save();

        $internal_request = Request::create(
            '/ai/application-stuck/context',
            'POST',
            [
                'application_id' => $application_id,
                'message' => $message,
            ]
        );

        $internal_request->setUserResolver(function () use ($request) {
            return $request->user();
        });

        $response = app(ApplicationAiContextController::class)->application_chat($internal_request);

        $data = $response->getData(true);

        $answer = data_get($data, 'data.ai_explanation.data.answer')
            ?: data_get($data, 'data.answer')
            ?: 'I could not prepare an answer.';

        $answer_type = data_get($data, 'data.ai_explanation.data.answer_type', 'application');

        $this->save_chat_message(
            session: $session,
            role: 'assistant',
            message: $answer,
            intent: $session->last_intent,
            answer_type: $answer_type
        );

        $data['data']['session_id'] = $session->id;
        $data['data']['active_application_id'] = $session->active_application_id;
        $data['data']['active_service_id'] = $session->active_service_id;
        $data['data']['suggested_questions'] = [
            'What should I do next?',
            'What is my payment status?',
            'Which documents are required?',
            'Is my certificate generated?',
        ];

        return response()->json($data, $response->status());
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

        $session->active_service_id = $service->id;
        $session->save();

        $doc_context = $this->build_service_document_context($service->id, $service->service_title_or_description);

        $answer = $this->format_service_document_answer($doc_context);

        $ai = [
            'answer' => $answer,
            'short_status' => 'Service documents',
            'answer_type' => 'service',
        ];

        $this->save_chat_message(
            session: $session,
            role: 'assistant',
            message: $answer,
            intent: $session->last_intent,
            answer_type: $ai['answer_type'] ?? 'service'
        );

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'answer' => $answer,
                'short_status' => $ai['short_status'] ?? null,
                'answer_type' => $ai['answer_type'] ?? 'service',
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'suggested_questions' => [
                    'Which documents are required?',
                    'Are any documents optional?',
                ],
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

            if ($q->display_rule || $q->condition_label) {
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

    private function resolve_service_from_message(string $message): array
    {
        $keyword = Str::lower($message);

        $keyword = preg_replace('/\b(documents?|required|needed|upload|files?|for|the|a|an|this|service|what|which|do|i|need|submit|please|tell|me|about)\b/i', ' ', $keyword);

        $keyword = trim(preg_replace('/\s+/', ' ', $keyword));

        /*
     * Common spelling / wording fixes
     */
        $keyword = str_replace([
            'labout',
            'labor',
            'laber',
        ], 'labour', $keyword);

        if (strlen($keyword) < 3) {
            return ['status' => 'not_found'];
        }

        /*
     * First: exact phrase match
     */
        $phrase_matches = ServiceMaster::where('service_title_or_description', 'like', '%' . $keyword . '%')
            ->limit(8)
            ->get(['id', 'service_title_or_description']);

        if ($phrase_matches->count() === 1) {
            return [
                'status' => 'found',
                'service_id' => $phrase_matches->first()->id,
            ];
        }

        if ($phrase_matches->count() > 1) {
            return [
                'status' => 'multiple',
                'options' => $phrase_matches->map(fn($s) => [
                    'id' => $s->id,
                    'title' => $s->service_title_or_description,
                    'subtitle' => 'Service',
                ])->values()->toArray(),
            ];
        }

        /*
     * Second: word scoring match
     */
        $tokens = collect(explode(' ', $keyword))
            ->map(fn($t) => trim($t))
            ->filter(fn($t) => strlen($t) >= 3)
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return ['status' => 'not_found'];
        }

        $services = ServiceMaster::query()
            ->get(['id', 'service_title_or_description']);

        $scored = $services->map(function ($service) use ($tokens) {
            $title = Str::lower($service->service_title_or_description);
            $score = 0;

            foreach ($tokens as $token) {
                if (Str::contains($title, $token)) {
                    $score += 10;
                }

                foreach (explode(' ', $title) as $word) {
                    $word = preg_replace('/[^a-z0-9]/i', '', $word);

                    if (strlen($word) >= 3 && levenshtein($token, $word) <= 1) {
                        $score += 6;
                    }
                }
            }

            return [
                'id' => $service->id,
                'title' => $service->service_title_or_description,
                'subtitle' => 'Service',
                'score' => $score,
            ];
        })
            ->filter(fn($item) => $item['score'] > 0)
            ->sortByDesc('score')
            ->take(8)
            ->values();

        if ($scored->isEmpty()) {
            return ['status' => 'not_found'];
        }

        /*
     * If one result is clearly best, auto-select it.
     */
        if ($scored->count() === 1) {
            return [
                'status' => 'found',
                'service_id' => $scored->first()['id'],
            ];
        }

        return [
            'status' => 'multiple',
            'options' => $scored->map(fn($s) => [
                'id' => $s['id'],
                'title' => $s['title'],
                'subtitle' => $s['subtitle'],
            ])->values()->toArray(),
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
        $services = ServiceMaster::query()
            ->orderBy('service_title_or_description')
            ->limit(100)
            ->get([
                'id',
                'service_title_or_description',
            ]);

        return response()->json([
            'status' => true,
            'data' => [
                'session_id' => $session->id,
                'requires_selection' => true,
                'selection_type' => 'service',
                'message' => 'Please select which service you want to ask about.',
                'active_application_id' => $session->active_application_id,
                'active_service_id' => $session->active_service_id,
                'options' => $services->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'title' => $service->service_title_or_description,
                        'subtitle' => 'Service',
                    ];
                })->values(),
                'suggested_questions' => [
                    'Which documents are required?',
                    'What is the process for this service?',
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

            if ($response->failed()) {
                return [
                    'data_scope' => 'UNKNOWN',
                    'confidence' => 0,
                ];
            }

            return $response->json() ?: [
                'data_scope' => 'UNKNOWN',
                'confidence' => 0,
            ];
        } catch (\Exception $e) {
            return [
                'data_scope' => 'UNKNOWN',
                'confidence' => 0,
            ];
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
                $line = '- ' . ($doc['label'] ?? 'Document');

                if (!empty($doc['allowed_types'])) {
                    $line .= ' — Allowed: ' . $doc['allowed_types'];
                }

                $lines[] = $line;
            }
        }

        if (!empty($optional)) {
            $lines[] = '';
            $lines[] = 'Optional documents:';

            foreach ($optional as $doc) {
                $line = '- ' . ($doc['label'] ?? 'Document');

                if (!empty($doc['allowed_types'])) {
                    $line .= ' — Allowed: ' . $doc['allowed_types'];
                }

                $lines[] = $line;
            }
        }

        if (!empty($conditional)) {
            $lines[] = '';
            $lines[] = 'Conditional documents:';

            foreach ($conditional as $doc) {
                $line = '- ' . ($doc['label'] ?? 'Document');

                if (!empty($doc['condition'])) {
                    $line .= ' — Condition: ' . $doc['condition'];
                }

                if (!empty($doc['allowed_types'])) {
                    $line .= ' — Allowed: ' . $doc['allowed_types'];
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
