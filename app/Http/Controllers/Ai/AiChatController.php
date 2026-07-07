<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ServiceMaster;
use App\Models\UserServiceApplication;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    public function options()
    {
        $user = User::find(13047);

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
            'message' => 'required|string|max:1500',
            'mode' => 'nullable|string|in:auto,application,service',
            'application_id' => 'nullable|integer',
            'service_id' => 'nullable|integer',
        ]);

        $user = User::find(13047);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated user.',
            ], 401);
        }

        $message = trim($request->message);
        $mode = $request->mode ?: 'auto';

        /**
         * If application selected, directly use existing application AI flow.
         */
        if ($request->filled('application_id')) {
            return $this->ask_about_application(
                request: $request,
                application_id: (int) $request->application_id,
                message: $message
            );
        }

        /**
         * If service selected, for now return service context placeholder.
         * Next we will connect service_document_context here.
         */
        if ($request->filled('service_id')) {
            return $this->ask_about_service(
                service_id: (int) $request->service_id,
                message: $message
            );
        }

        /**
         * Auto mode:
         * If user did not select anything, ask them to choose application/service.
         */
        if ($mode === 'auto') {
            if ($this->looks_like_service_question($message)) {
                return $this->service_selection_response();
            }

            return $this->application_selection_response();
        }

        if ($mode === 'application') {
            return $this->application_selection_response();
        }

        if ($mode === 'service') {
            return $this->service_selection_response();
        }

        return response()->json([
            'status' => false,
            'message' => 'Could not understand what you want to ask about.',
        ]);
    }

    private function ask_about_application(Request $request, int $application_id, string $message)
    {
        $application = UserServiceApplication::where('id', $application_id)
            ->where('user_id', 13047)
            ->first();

        if (!$application) {
            return response()->json([
                'status' => false,
                'message' => 'Application not found or not allowed.',
            ], 404);
        }

        /**
         * Reuse your existing ApplicationAiContextController.
         * That controller already builds application context and calls FastAPI.
         */
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

        return app(ApplicationAiContextController::class)->application_chat($internal_request);
    }

    private function ask_about_service(int $service_id, string $message)
    {
        $service = ServiceMaster::query()
            ->where('id', $service_id)
            ->first([
                'id',
                'service_title_or_description',
            ]);

        if (!$service) {
            return response()->json([
                'status' => false,
                'message' => 'Service not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'answer' => 'Service selected: ' . $service->service_title_or_description . '. Next we need to connect service document requirements so I can answer which documents are required.',
                'short_status' => 'Service selected',
                'answer_type' => 'service',
                'service_context' => [
                    'service_id' => $service->id,
                    'service_name' => $service->service_title_or_description,
                ],
            ],
        ]);
    }

    private function application_selection_response()
    {
        $applications = UserServiceApplication::with([
                'service:id,service_title_or_description'
            ])
            ->where('user_id', 13047)
            ->orderByDesc('id')
            ->limit(15)
            ->get([
                'id',
                'applicationId',
                'service_id',
                'status',
                'payment_status',
            ]);

        if ($applications->count() === 1) {
            $app = $applications->first();

            $fake_request = request();

            return $this->ask_about_application(
                request: $fake_request,
                application_id: $app->id,
                message: request('message', 'Tell me about my application.')
            );
        }

        return response()->json([
            'status' => true,
            'data' => [
                'requires_selection' => true,
                'selection_type' => 'application',
                'message' => 'Please select which application you want to ask about.',
                'options' => $applications->map(function ($app) {
                    return [
                        'id' => $app->id,
                        'title' => $app->applicationId ?: ('Application #' . $app->id),
                        'subtitle' => trim(($app->service->service_title_or_description ?? 'Service') . ' — ' . ($app->status ?? '')),
                    ];
                })->values(),
            ],
        ]);
    }

    private function service_selection_response()
    {
        $services = ServiceMaster::query()
            ->orderBy('service_title_or_description')
            ->limit(50)
            ->get([
                'id',
                'service_title_or_description',
            ]);

        return response()->json([
            'status' => true,
            'data' => [
                'requires_selection' => true,
                'selection_type' => 'service',
                'message' => 'Please select which service you want to ask about.',
                'options' => $services->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'title' => $service->service_title_or_description,
                        'subtitle' => 'Service',
                    ];
                })->values(),
            ],
        ]);
    }

    private function looks_like_service_question(string $message): bool
    {
        $text = Str::lower($message);

        return Str::contains($text, [
            'document',
            'documents',
            'upload',
            'required',
            'requirement',
            'service',
            'file',
            'files',
            'which docs',
            'which document',
            'kya upload',
            'documents required',
        ]);
    }
}