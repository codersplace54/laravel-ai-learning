<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserFeedback;
use App\Models\User;

class UserFeedbackController extends Controller
{
    public function user_feedback_store(Request $request)
    {

        try {

            DB::beginTransaction();

            $request->validate([
                'user_name' => 'required',
                'email'     => 'required|email',
                'department_id' => 'required|exists:departments,id',
                'satisfaction' => 'required|integer|in:1,2,3,4,5',
                'feedback' => 'required|string',
                'suggestions' => 'nullable|string',
            ]);

            $user = User::where('user_name', $request->user_name)
                ->where('email_id', $request->email)
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => 1,
                    'message' => 'Email or username is incorrect.',
                ], 422);
            }

            $user_feedback = UserFeedback::where('user_id', $user->id)
                ->where('department_id', $request->department_id)
                ->first();

            if ($user_feedback) {

                $user_feedback->update([
                    'satisfaction' => $request->satisfaction,
                    'feedback' => $request->feedback,
                    'suggestions' => $request->suggestions,
                ]);

                DB::commit();

                return response()->json([
                    'status' => 1,
                    'message' => 'Feedback updated successfully!',
                    'data' => $user_feedback
                ], 200);
            } else {
                $user_feedback = UserFeedback::create([
                    'user_id' => $user->id,
                    'department_id' => $request->department_id,
                    'satisfaction' => $request->satisfaction,
                    'feedback' => $request->feedback,
                    'suggestions' => $request->suggestions,
                ]);

                DB::commit();

                return response()->json([
                    'status' => 1,
                    'message' => 'Feedback submitted successfully!',
                    'data' => $user_feedback
                ], 201);
            }
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

    public function user_feedback_list()
    {
        $departments = Department::withAvg('feedbacks', 'satisfaction')
            ->withCount('feedbacks')
            ->whereHas('feedbacks')
            ->orderByDesc('feedbacks_avg_satisfaction')
            ->get(['id', 'name']);

        $data = $departments->map(function ($department) {
            $avg = round($department->feedbacks_avg_satisfaction, 1);
            $count = $department->feedbacks_count;

            return [
                'department_id'   => $department->id,
                'department_name' => $department->name,
                'avg_rating'      => $avg,
                'ratings_count'   => $count,
            ];
        })->values();

        return response()->json([
            'status'  => 1,
            'message' => 'User feedback grouped by department fetched successfully.',
            'data'    => $data,
        ], 200);
    }


    public function user_feedback_details(Request $request)
    {
        try {
            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
            ]);

            $department = Department::withAvg('feedbacks', 'satisfaction')
                ->withCount('feedbacks')
                ->find($request->department_id);

            if ($department->feedbacks_count < 1) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No reviews found for this department.',
                ], 404);
            }

            $feedbacks = UserFeedback::with('user:id,user_name')
                ->where('department_id', $department->id)
                ->latest('created_at')
                ->get();

            $items = $feedbacks->map(function ($feedback) {
                return [
                    'id'          => $feedback->id,
                    'user_name'   => $feedback->user?->user_name,
                    'created_at'  => optional($feedback->created_at)->format('d/m/Y'),
                    'rating'      => $feedback->satisfaction,
                    'feedback'    => $feedback->feedback,
                    'suggestions' => $feedback->suggestions,
                ];
            });

            $avg = round($department->feedbacks_avg_satisfaction, 2);
            $count = $department->feedbacks_count;

            return response()->json([
                'status'  => 1,
                'message' => 'Department reviews fetched successfully.',
                'summary' => [
                    'department_id'   => $department->id,
                    'department_name' => $department->name,
                    'avg_rating'      => $avg,
                    'ratings_count'   => $count,
                ],
                'data'    => $items,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Failed to retrieve department reviews.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
