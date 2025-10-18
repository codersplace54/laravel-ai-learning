<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserFeedback;

class UserFeedbackController extends Controller
{
    public function user_feedback_store(Request $request)
    {

        try {

            DB::beginTransaction();

            $request->validate(
                [
                    'user_id' => 'required|exists:users,id',
                    'department_id' => 'required|exists:departments,id',
                    'satisfaction' => 'required|integer|in:1,2,3,4,5',
                    'feedback' => 'required|string',
                    'suggestions' => 'nullable|string',
                ]
            );

            $existing = UserFeedback::where('user_id', $request->user_id)
                ->where('department_id', $request->department_id)
                ->first();

            if ($existing) {
                $existing->update([
                    'satisfaction' => $request->satisfaction,
                    'feedback' => $request->feedback,
                    'suggestions' => $request->suggestions,
                ]);

                return response()->json([
                    'message' => 'Feedback updated successfully!',
                    'data' => $existing
                ], 200);
            } else {
                $feedback = UserFeedback::create([
                    'user_id' => $request->user_id,
                    'department_id' => $request->department_id,
                    'satisfaction' => $request->satisfaction,
                    'feedback' => $request->feedback,
                    'suggestions' => $request->suggestions,
                ]);

                return response()->json([
                    'message' => 'Feedback submitted successfully!',
                    'data' => $feedback
                ], 201);
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => "Feedback submited",
                'data' => $feedback,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to create feedback.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function user_feedback_get()
    {
        $feedbackData = UserFeedback::get();

        $transformedData = $feedbackData->map(function ($feedback) {
            return [
                'id' => $feedback->id,
                'department_name' => $feedback->department->name ?? 'No name found',
                'satisfaction' => $feedback->satisfaction
            ];
        });

        return response()->json([
            'satatus' => 1,
            'data' => $transformedData,
        ]);
    }


    public function user_feedback_view(Request $request)
    {
        try {

            $request->validate(
                [
                    'department_id' => 'required|exists:departments,id',
                ]
            );

            $feedback = UserFeedback::where('department_id', $request->department_id)->get();

            return response()->json([
                'status' => 1,
                'data' => $feedback,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Failed to retrieve department.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
