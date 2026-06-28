<?php

namespace App\Http\Controllers\Ai\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\FastApiAiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApplicationStuckInvestigationController extends Controller
{
    public function investigate(Request $request, FastApiAiService $fastApiAiService)
    {
        $validator = Validator::make($request->all(), [
            'search_type' => 'required|string|in:application_id,applicationId,mobile,order_id,grn',
            'search_value' => 'required|string',
            'issue_text' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = [
            'issue_text' => $request->issue_text ?: 'Where is this application stuck?',
            'search' => [
                'search_type' => $request->search_type,
                'search_value' => $request->search_value,
            ],
        ];

        $result = $fastApiAiService->applicationStuckInvestigator($payload);

        return response()->json($result);
    }
}
