<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ServiceApprovalFlow;
use App\Models\User;
use App\Models\UserServiceApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ApplicationStuckContextController extends Controller
{
    public function get_context(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'application_id'     => 'nullable|integer',
            'application_number' => 'nullable|string|max:100',
            'message'            => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }


        try {

            $application_query = UserServiceApplication::with([
                'service:id,service_title_or_description'
            ]);

            if ($request->application_id) {
                $application_query->where('id', $request->application_id);
            }

            if ($request->application_number) {
                $application_query->where('applicationId', $request->application_number);
            }

            $application = $application_query
                ->select([
                    'id',
                    'applicationId',
                    'user_id',
                    'service_id',
                    'status',
                    'payment_status',
                    'total_fee',
                    'final_fee',
                    'effective_fee',
                    'paid_amount',
                    'GRN_number',
                    'created_at',
                    'updated_at',
                ])
                ->first();

            if (!$application) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Application not found.',
                ], 404);
            }

            $latest_assignment = DB::table('application_workflow_assignments as awa')
                ->leftJoin('departments as d', 'd.id', '=', 'awa.department_id')
                ->where('awa.application_id', $application->id)
                ->orderByDesc('awa.id')
                ->select([
                    'awa.id',
                    'awa.application_id',
                    'awa.service_id',
                    'awa.step_number',
                    'awa.step_type',
                    'awa.department_id',
                    'd.name as department_name',
                    'awa.hierarchy_level',
                    'awa.assigned_to_group',
                    'awa.status',
                    'awa.action_taken_by',
                    'awa.action_taken_at',
                    'awa.remarks',
                    'awa.created_at',
                    'awa.updated_at',
                ])
                ->first();

            $recent_assignments = DB::table('application_workflow_assignments as awa')
                ->leftJoin('departments as d', 'd.id', '=', 'awa.department_id')
                ->where('awa.application_id', $application->id)
                ->orderByDesc('awa.id')
                ->limit(8)
                ->select([
                    'awa.id',
                    'awa.step_number',
                    'awa.step_type',
                    'awa.department_id',
                    'd.name as department_name',
                    'awa.hierarchy_level',
                    'awa.status',
                    'awa.action_taken_by',
                    'awa.action_taken_at',
                    'awa.remarks',
                    'awa.created_at',
                    'awa.updated_at',
                ])
                ->get();

            $approval_flow = DB::table('service_approval_flows as saf')
                ->where('saf.service_id', $application->service_id)
                ->orderBy('saf.step_number', 'asc')
                ->get();

            $latest_payment = DB::table('payment_orders')
                ->whereJsonContains('application_id', $application->id)
                ->orderByDesc('id')
                ->first();

            $stuck_context = $this->build_stuck_context(
                application: $application,
                latest_assignment: $latest_assignment,
                latest_payment: $latest_payment,
                approval_flow: $approval_flow
            );

            $context_data = [
                'application' => [
                    'id'                 => $application->id,
                    'application_number' => $application->applicationId,
                    'service_id'         => $application->service_id,
                    'service_name'       => $application->service->service_title_or_description ?? null,
                    'status'             => $application->status,
                    'payment_status'     => $application->payment_status,
                    'total_fee'          => $application->total_fee,
                    'final_fee'          => $application->final_fee,
                    'effective_fee'      => $application->effective_fee,
                    'paid_amount'        => $application->paid_amount,
                    'grn_number'         => $application->GRN_number,
                    'created_at'         => $application->created_at,
                    'updated_at'         => $application->updated_at,
                ],
                'stuck_context'      => $stuck_context,
                'latest_assignment'  => $latest_assignment,
                'recent_assignments' => $recent_assignments,
                'latest_payment'     => $latest_payment,
            ];

            $message = $request->message;
            $ai_explanation = $this->call_fastapi_stuck_explanation(
                message: $message,
                context_data: $context_data
            );

            return response()->json([
                'status' => true,
                'data'   => [
                    'context'        => $context_data,
                    'ai_explanation' => $ai_explanation,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
                'line'   => $e->getLine(),
            ], 500);
        }
    }

    private function call_fastapi_stuck_explanation(string $message, array $context_data): array
    {
        try {
            $base_url = rtrim(config('ai.base_url'), '/');

            $response = Http::timeout(120)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-AI-SECRET'  => config('services.fastapi_ai.secret'),
                ])
                ->post($base_url . '/api/ai/application-stuck-explain', [
                    'message' => $message,
                    'context' => $context_data,
                ]);

            if ($response->failed()) {
                Log::error('FastAPI application stuck explanation failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return [
                    'status'  => false,
                    'message' => 'AI explanation failed.',
                    'error'   => $response->json() ?: $response->body(),
                ];
            }

            return [
                'status' => true,
                'data'   => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('FastAPI application stuck explanation exception', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
            ]);

            return [
                'status'  => false,
                'message' => 'Could not connect to AI service.',
                'error'   => $e->getMessage(),
            ];
        }
    }

    private function build_stuck_context($application, $latest_assignment, $latest_payment, $approval_flow): array
    {
        $waiting_on = 'unknown';
        $current_state = 'unknown';
        $is_stuck = true;
        $summary = 'Could not determine where the application is stuck.';
        $reason = 'Application data is not enough to determine the current waiting point.';
        $next_action = 'Please check application, payment, and workflow details.';

        if ($application->payment_status === 'pending') {
            $waiting_on = 'applicant';
            $current_state = 'payment_pending';
            $summary = 'Application is waiting for payment.';
            $reason = 'Application payment status is pending.';
            $next_action = 'Applicant should complete the payment.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action'
            );
        }

        if (
            $application->payment_status === 'paid'
            && empty($application->GRN_number)
            && !in_array($application->status, ['approved', 'noc_issued', 'completed'])
        ) {
            $waiting_on = 'system';
            $current_state = 'grn_missing';
            $summary = 'Payment is paid but GRN number is missing.';
            $reason = 'Payment is marked paid, but GRN number is not available.';
            $next_action = 'Admin should verify payment writeback or GRN generation.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action'
            );
        }

        if (!$latest_assignment) {
            $waiting_on = 'system';
            $current_state = 'assignment_missing';
            $summary = 'No workflow assignment found for this application.';
            $reason = 'Application has no latest assignment row.';
            $next_action = 'Admin should check approval flow assignment creation.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action'
            );
        }

        if ($latest_assignment->status === 'pending' && empty($latest_assignment->action_taken_at)) {
            $waiting_on = 'department';
            $current_state = 'department_action_pending';
            $summary = 'Application is pending with the assigned department.';
            $reason = 'Latest assignment status is pending and no action has been taken yet.';
            $next_action = 'Department/officer should review and take action on the application.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action'
            );
        }

        if ($latest_assignment->status === 'send_back' && !empty($latest_assignment->action_taken_at)) {
            if ($application->status === 're_submitted') {
                $waiting_on = 'system';
                $current_state = 'assignment_missing_after_resubmission';
                $summary = 'Application was re-submitted but no newer pending assignment was found.';
                $reason = 'Latest assignment is still send_back, but application status is re_submitted.';
                $next_action = 'Admin should check whether a new workflow assignment was created after re-submission.';
            } else {
                $waiting_on = 'applicant';
                $current_state = 'clarification_pending_from_user';
                $summary = 'Application was sent back by the department.';
                $reason = 'Department has taken action and sent the application back with remarks.';
                $next_action = 'Applicant should check remarks, upload required clarification/documents, and resubmit.';
            }

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action'
            );
        }

        if (
            in_array($application->status, ['noc_issued'])
        ) {
            $is_stuck = false;
            $waiting_on = 'none';
            $current_state = 'noc_issued';
            $summary = 'Application does not look stuck.';
            $reason = 'Application has reached a final status.';
            $next_action = 'No action required unless certificate/NOC download is not available.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action'
            );
        }

        if (
            in_array($application->status, ['approved'])
        ) {
            if ($approval_flow->isEmpty()) {
                $is_stuck = false;
                $waiting_on = 'none';
                $current_state = 'approved';
                $summary = 'Application does not look stuck.';
                $reason = 'Application has reached a final status.';
                $next_action = 'No action required unless certificate/NOC download is not available.';

                return compact(
                    'is_stuck',
                    'waiting_on',
                    'current_state',
                    'summary',
                    'reason',
                    'next_action'
                );
            }
        }

        if ($latest_assignment->status === 'approved' && !empty($latest_assignment->action_taken_at)) {
            $waiting_on = 'system';
            $current_state = 'next_step_or_final_status_check';
            $summary = 'Latest workflow step is approved.';
            $reason = 'Latest assignment was approved, but application has not reached final status yet.';
            $next_action = 'Admin should check if next workflow step or final status update is pending.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action'
            );
        }

        return compact(
            'is_stuck',
            'waiting_on',
            'current_state',
            'summary',
            'reason',
            'next_action'
        );
    }
}
