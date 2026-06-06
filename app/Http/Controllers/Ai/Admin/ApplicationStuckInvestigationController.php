<?php

namespace App\Http\Controllers\Ai\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApplicationStuckInvestigationRequest;
use App\Models\AiInvestigationLog;
use App\Services\Ai\FastApiAiService;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ApplicationStuckInvestigationController extends Controller
{
    public function investigate(
        ApplicationStuckInvestigationRequest $request,
        FastApiAiService $ai_service
    ) {
        try {
            $search_type = $request->input('search_type');
            $search_value = $request->input('search_value');
            $issue_text = $request->input('issue_text') ?: 'Find why this application is stuck.';

            $payload = [
                'issue_text' => $issue_text,
                'search' => [
                    'search_type' => $search_type,
                    'search_value' => $search_value,
                ],
            ];

            $ai_response = $ai_service->investigate_application_stuck($payload);

            return response()->json([
                'status' => 1,
                'message' => 'Application stuck investigation completed.',
                'data' => $ai_response,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Application stuck investigation failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}