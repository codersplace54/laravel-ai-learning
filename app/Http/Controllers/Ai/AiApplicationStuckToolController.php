<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\ApplicationStuckToolService;
use Illuminate\Http\Request;
use Throwable;

class AiApplicationStuckToolController extends Controller
{
    public function run_tool(Request $request, ApplicationStuckToolService $tool_service)
    {
        try {
            $request->validate([
                'tool_name' => 'required|string|max:100',
                'arguments' => 'nullable|array',
            ]);

            $tool_name = $request->input('tool_name');
            $arguments = $request->input('arguments', []);

            $data = $tool_service->run_tool($tool_name, $arguments);

            return response()->json([
                'status' => 1,
                'tool_name' => $tool_name,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Tool execution failed.',
                'tool_name' => $request->input('tool_name'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}