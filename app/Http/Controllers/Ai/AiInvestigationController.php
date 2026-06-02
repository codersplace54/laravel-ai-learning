<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiInvestigationRequest;
use App\Models\AiInvestigationLog;
use App\Services\Ai\AiInvestigationDataService;
use App\Services\Ai\FastApiAiService;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AiInvestigationController extends Controller
{
    public function investigate_application(
        AiInvestigationRequest $request,
        AiInvestigationDataService $data_service,
        FastApiAiService $ai_service
    ) {
        try {
            $search_type = $request->input('search_type');
            $search_value = $request->input('search_value');
            $issue_text = $request->input('issue_text');

            $payload = $data_service->build_payload(
                search_type: $search_type,
                search_value: $search_value,
                issue_text: $issue_text
            );

            $ai_response = $ai_service->investigate_application($payload);

            return response()->json([
                'status' => 1,
                'message' => 'Investigation completed successfully.',
                'data' => [
                    'application' => $payload['application'],
                    'user' => $payload['user'],
                    'service' => $payload['service'],
                    'ai_diagnosis' => $ai_response,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Investigation failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}