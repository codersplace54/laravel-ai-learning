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
    public function get_pending_feedback_applications(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $already_reviewed_service_ids = UserFeedback::where('user_id', $user->id)
                ->pluck('service_id')
                ->toArray();

            $applications = UserServiceApplication::with('service:id,service_title_or_description')
                ->where('user_id', $user->id)
                ->where('status', 'noc_issued')
                ->where('updated_at', '<=', now()->subDays(10))
                ->whereNotIn('service_id', $already_reviewed_service_ids)
                ->get(['id', 'applicationId', 'service_id', 'updated_at'])
                ->map(fn($app) => [
                    'application_id'   => $app->id,
                    'application_number' => $app->applicationId,
                    'service_id'       => $app->service_id,
                    'service_name'     => $app->service->service_title_or_description ?? null,
                    'approved_on'      => $app->updated_at,
                ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Pending feedback applications fetched successfully.',
                'data'    => $applications,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Failed to fetch pending feedback applications.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

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

            $statuses = ['pending', 'resolved'];

            $application = UserServiceApplication::where('id', $request->application_id)->first();
            $user_id = $application->user_id;
            $service_id = $application->service_id;
            $department_id = $application->service->department->id;

            $user_feedback = UserFeedback::where('user_id', $user_id)
                ->where('service_id', $service_id)
                ->first();

            if ($user_feedback) {

                $data = [
                    'satisfaction' => $request->satisfaction,
                    'feedback'     => $request->feedback,
                    'suggestions'  => $request->suggestions,
                ];

                if ($request->filled('status') && in_array($request->status, $statuses)) {
                    $data['status'] = $request->status;
                }

                $user_feedback->update($data);
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
                    'status' => 'pending',
                ]);

                $user_feedback->update([
                    'ticket_id' => 'QT-' . $user_feedback->created_at->format('Y') . '-' . str_pad($user_feedback->id, 3, '0', STR_PAD_LEFT)
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
            $dept_id = $user->department_user?->department_id;
            $query->where('department_id', $dept_id);
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

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
                'status'       => $feedback->status,
                'already_rated' => !empty($feedback->satisfaction),
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

    public function service_feedback_add_remark(Request $request)
    {

        try {
            $request->validate([
                'feedback_id' => 'required|exists:user_feedbacks,id',
                'remark'      => 'required|string',
                'status'      => 'nullable|in:resolved,pending',
            ]);

            $user = auth()->user();
            $allowed = ['admin', 'department', 'support'];

            if (!in_array($user->user_type, $allowed)) {
                return response()->json(['status' => 0, 'message' => 'Unauthorized.'], 403);
            }

            $feedback = UserFeedback::where('id', $request->feedback_id)->first();

            $update_data = [
                'remark'      => $request->remark,
                'resolved_at' => $feedback->resolved_at ?? now(),
            ];

            if ($request->filled('status')) {
                $update_data['status'] = $request->status;
                $update_data['resolved_by'] = auth()->id();
            }

            $feedback->update($update_data);

            return response()->json([
                'status'  => 1,
                'message' => 'Remark added successfully.',
                'data'    => $feedback,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json(
                [
                    'status' => 0,
                    'message' => 'Failed to add remark.',
                    'error' => $e->getMessage()
                ],
                500
            );
        }
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
