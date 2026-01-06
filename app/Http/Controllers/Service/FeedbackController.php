<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserFeedback;
use App\Models\User;
use App\Models\UserServiceApplication;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ServiceFeedbackExport;

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

    public function service_feedback_list(Request $request)
    {
        
        $user = auth()->user();
        $perPage = $request->get('per_page', 15);

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

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->from_date . ' 00:00:00');
        }

        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->to_date . ' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($user_query) use ($search) {
                    $user_query->where('user_name', 'like', '%' . $search . '%');
                })->orWhere('feedback', 'like', '%' . $search . '%');
            });
        }

        $feedbacks = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = $feedbacks->getCollection()->map(function ($feedback) {
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
            'pagination' => [
                'current_page' => $feedbacks->currentPage(),
                'last_page' => $feedbacks->lastPage(),
                'per_page' => $feedbacks->perPage(),
                'total' => $feedbacks->total(),
            ],
        ], 200);
    }

    public function service_feedback_export(Request $request)
    {
        try {
            $filters = [
                'department_id' => $request->department_id,
                'service_id' => $request->service_id,
                'from_date' => $request->from_date,
                'to_date' => $request->to_date,
                'search' => $request->search,
            ];

            return Excel::download(new ServiceFeedbackExport($filters), 'service_feedback.xlsx');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Failed to generate Excel file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
