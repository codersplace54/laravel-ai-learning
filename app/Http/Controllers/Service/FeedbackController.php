<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserFeedback;
use App\Models\User;
use App\Models\UserServiceApplication;

class FeedbackController extends Controller
{
    public function service_feedback_store(Request $request)
    {

        try {

            DB::beginTransaction();

            $request->validate([
                'application_id' => 'required|exists:user_service_applications,id',
                'satisfaction' => 'required|integer|in:1,2,3,4,5',
                'feedback' => 'required|string',
                'suggestions' => 'nullable|string',
            ]);

            $application = UserServiceApplication::where('id', $request->application_id)->first();
            $user_id = $application->user_id;
            $service_id = $application->service_id;
            $department_id = $application->service->department->id;

            $user_feedback = UserFeedback::where('user_id', $user_id)
                ->where('service_id', $service_id)
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
                    'user_id' => $user_id,
                    'service_id' => $service_id,
                    'department_id' => $department_id,
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

    public function service_feedback_list()
    {
        $user = auth()->user();

        $query = UserFeedback::with(
            'user:id,user_name',
            'service:id,service_title_or_description,department_id'
        );

        if ($user->user_type === 'department') {
            $query->where('department_id', $user->id);
            
        }

        if ($user->user_type === 'individual') {
            $query->where('user_id', $user->id);
        }

        $feedbacks = $query->get();

        $data = $feedbacks->map(function ($feedback) {
            return [
                'username'     => $feedback->user->user_name ?? null,
                'service'      => $feedback->service->service_title_or_description ?? null,
                'satisfaction' => $feedback->satisfaction,
                'feedback'     => $feedback->feedback,
                'suggestions'  => $feedback->suggestions,
                'submitted_on' => $feedback->created_at,
                'already_rated'=> !empty($feedback->satisfaction),
            ];
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Service feedback fetched successfully.',
            'data'    => $data,
        ], 200);
    }
}
