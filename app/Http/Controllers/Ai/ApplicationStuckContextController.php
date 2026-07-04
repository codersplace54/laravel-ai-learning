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

            $payment_context = $this->build_payment_context(
                application: $application,
                latest_payment: $latest_payment,
                approval_flow: $approval_flow
            );

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
                'payment_context'    => $payment_context,
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

    private function build_payment_context($application, $latest_payment, $approval_flow): array
    {
        $application_status = strtolower(trim((string) ($application->status ?? '')));
        $payment_status = strtolower(trim((string) ($application->payment_status ?? '')));

        $total_fee = (float) ($application->total_fee ?? 0);
        $effective_fee = (float) ($application->effective_fee ?? 0);
        $paid_amount = (float) ($application->paid_amount ?? 0);
        $extra_payment = (float) ($application->extra_payment ?? 0);

        $has_grn = !empty($application->GRN_number);
        
        $has_approval_flow = $approval_flow->isNotEmpty();
            
        $is_zero_fee_application = (
            $total_fee <= 0
            && $effective_fee <= 0
            && $paid_amount <= 0
        );

        $has_payable_amount = (
            $total_fee > 0
            || $effective_fee > 0
            || $paid_amount > 0
            || $extra_payment > 0
        );

        $payment_required = $has_payable_amount && !$is_zero_fee_application;
        $grn_required = $payment_required;

        $current_state = 'unknown';
        $payment_meaning = 'Could not determine payment status.';
        $next_action = 'Please check payment details.';
        $waiting_on = 'unknown';
        $is_payment_issue = false;

        /*
        |--------------------------------------------------------------------------
        | 1. Draft
        |--------------------------------------------------------------------------
        */
        if ($application_status === 'draft') {
            return [
                'payment_required' => false,
                'grn_required' => false,
                'is_zero_fee_application' => $is_zero_fee_application,
                'is_payment_issue' => false,
                'waiting_on' => 'applicant',
                'current_state' => 'draft_not_submitted',
                'payment_status' => $payment_status,
                'payment_meaning' => 'Application is still in draft. Payment stage has not started yet.',
                'next_action' => 'Applicant should complete and submit the application.',
                'total_fee' => $total_fee,
                'effective_fee' => $effective_fee,
                'paid_amount' => $paid_amount,
                'grn_number' => $application->GRN_number,
                'latest_payment' => $latest_payment,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Zero-fee application
        |--------------------------------------------------------------------------
        */
        if ($is_zero_fee_application) {
            if ($payment_status === 'paid') {
                $current_state = 'zero_fee_paid';
                $payment_meaning = 'This is a zero-fee application. Payment is marked paid because no online payment is required.';
                $next_action = 'No payment action is needed.';
                $waiting_on = 'none';
                $is_payment_issue = false;
            } elseif ($payment_status === 'pending') {
                $current_state = 'zero_fee_payment_status_mismatch';
                $payment_meaning = 'This application appears to have zero fee, but payment status is still pending.';
                $next_action = 'Admin should check fee calculation and payment status update.';
                $waiting_on = 'system';
                $is_payment_issue = true;
            }

            return [
                'payment_required' => false,
                'grn_required' => false,
                'is_zero_fee_application' => true,
                'is_payment_issue' => $is_payment_issue,
                'waiting_on' => $waiting_on,
                'current_state' => $current_state,
                'payment_status' => $payment_status,
                'payment_meaning' => $payment_meaning,
                'next_action' => $next_action,
                'total_fee' => $total_fee,
                'effective_fee' => $effective_fee,
                'paid_amount' => $paid_amount,
                'grn_number' => $application->GRN_number,
                'latest_payment' => $latest_payment,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Extra payment pending
        |--------------------------------------------------------------------------
        */
        if ($extra_payment > 0 && $payment_status === 'pending') {
            return [
                'payment_required' => true,
                'grn_required' => true,
                'is_zero_fee_application' => false,
                'is_payment_issue' => false,
                'waiting_on' => 'applicant',
                'current_state' => 'extra_payment_pending',
                'payment_status' => $payment_status,
                'payment_meaning' => 'Extra payment has been raised and is pending.',
                'next_action' => 'Applicant should complete the extra payment.',
                'total_fee' => $total_fee,
                'effective_fee' => $effective_fee,
                'extra_payment' => $extra_payment,
                'paid_amount' => $paid_amount,
                'grn_number' => $application->GRN_number,
                'latest_payment' => $latest_payment,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Normal payment pending
        |--------------------------------------------------------------------------
        */
        if ($payment_status === 'pending') {
            return [
                'payment_required' => true,
                'grn_required' => true,
                'is_zero_fee_application' => false,
                'is_payment_issue' => false,
                'waiting_on' => 'applicant',
                'current_state' => 'payment_pending',
                'payment_status' => $payment_status,
                'payment_meaning' => 'Application is waiting for payment.',
                'next_action' => 'Applicant should complete the payment.',
                'total_fee' => $total_fee,
                'effective_fee' => $effective_fee,
                'paid_amount' => $paid_amount,
                'grn_number' => $application->GRN_number,
                'latest_payment' => $latest_payment,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Paid but status still saved
        |--------------------------------------------------------------------------
        */
        if ($application_status === 'saved' && $payment_status === 'paid') {
            return [
                'payment_required' => true,
                'grn_required' => true,
                'is_zero_fee_application' => false,
                'is_payment_issue' => true,
                'waiting_on' => 'system',
                'current_state' => 'payment_paid_but_status_not_advanced',
                'payment_status' => $payment_status,
                'payment_meaning' => 'Payment is paid, but application status is still saved.',
                'next_action' => 'Admin should check payment success callback or PaymentSuccessService.',
                'total_fee' => $total_fee,
                'effective_fee' => $effective_fee,
                'paid_amount' => $paid_amount,
                'grn_number' => $application->GRN_number,
                'latest_payment' => $latest_payment,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Paid but GRN missing for payable application
        |--------------------------------------------------------------------------
        */
        if ($payment_status === 'paid' && !$has_grn && $grn_required) {
            return [
                'payment_required' => true,
                'grn_required' => true,
                'is_zero_fee_application' => false,
                'is_payment_issue' => true,
                'waiting_on' => 'system',
                'current_state' => 'grn_missing',
                'payment_status' => $payment_status,
                'payment_meaning' => 'Payment is marked paid, but GRN number is missing.',
                'next_action' => 'Admin should verify payment writeback or GRN generation.',
                'total_fee' => $total_fee,
                'effective_fee' => $effective_fee,
                'paid_amount' => $paid_amount,
                'grn_number' => $application->GRN_number,
                'latest_payment' => $latest_payment,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Paid and verified
        |--------------------------------------------------------------------------
        */
        if ($payment_status === 'paid') {
            return [
                'payment_required' => true,
                'grn_required' => true,
                'is_zero_fee_application' => false,
                'is_payment_issue' => false,
                'waiting_on' => 'none',
                'current_state' => 'payment_completed',
                'payment_status' => $payment_status,
                'payment_meaning' => 'Payment is completed.',
                'next_action' => 'No payment action is needed.',
                'total_fee' => $total_fee,
                'effective_fee' => $effective_fee,
                'paid_amount' => $paid_amount,
                'grn_number' => $application->GRN_number,
                'latest_payment' => $latest_payment,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Failed payment
        |--------------------------------------------------------------------------
        */
        if ($payment_status === 'failed') {
            return [
                'payment_required' => true,
                'grn_required' => true,
                'is_zero_fee_application' => false,
                'is_payment_issue' => true,
                'waiting_on' => 'applicant',
                'current_state' => 'payment_failed',
                'payment_status' => $payment_status,
                'payment_meaning' => 'Payment failed.',
                'next_action' => 'Applicant should retry payment or contact support.',
                'total_fee' => $total_fee,
                'effective_fee' => $effective_fee,
                'paid_amount' => $paid_amount,
                'grn_number' => $application->GRN_number,
                'latest_payment' => $latest_payment,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 9. Fallback
        |--------------------------------------------------------------------------
        */
        return [
            'payment_required' => $payment_required,
            'grn_required' => $grn_required,
            'is_zero_fee_application' => $is_zero_fee_application,
            'is_payment_issue' => $is_payment_issue,
            'waiting_on' => $waiting_on,
            'current_state' => $current_state,
            'payment_status' => $payment_status,
            'payment_meaning' => $payment_meaning,
            'next_action' => $next_action,
            'total_fee' => $total_fee,
            'effective_fee' => $effective_fee,
            'paid_amount' => $paid_amount,
            'grn_number' => $application->GRN_number,
            'latest_payment' => $latest_payment,
        ];
    }

    private function build_stuck_context($application, $latest_assignment, $latest_payment, $approval_flow): array
    {
        $waiting_on = 'unknown';
        $current_state = 'unknown';
        $is_stuck = true;
        $summary = 'Could not determine where the application is stuck.';
        $reason = 'Application data is not enough to determine the current waiting point.';
        $next_action = 'Please check application, payment, and workflow details.';
        $payment_note = null;

        $application_status = strtolower(trim((string) ($application->status ?? '')));
        $payment_status = strtolower(trim((string) ($application->payment_status ?? '')));

        $total_fee = (float) ($application->total_fee ?? 0);
        $effective_fee = (float) ($application->effective_fee ?? 0);
        $paid_amount = (float) ($application->paid_amount ?? 0);

        $is_zero_fee_application = (
            $total_fee <= 0
            && $effective_fee <= 0
            && $paid_amount <= 0
        );

        $has_payable_amount = (
            $total_fee > 0
            || $effective_fee > 0
            || $paid_amount > 0
        );

        if ($is_zero_fee_application && $payment_status === 'paid') {
            $payment_note = 'This is a zero-fee application. Payment is marked paid because no online payment is required.';
        }

        $has_approval_flow = $approval_flow->isNotEmpty();

        $assignment_status = null;

        if ($latest_assignment) {
            $assignment_status = $latest_assignment->status ?? '';
        }

        /*
        |--------------------------------------------------------------------------
        | 1. Final statuses 
        |--------------------------------------------------------------------------
        */

        if (in_array($application_status, ['approved', 'noc_issued', 'completed', 'certificate_issued'])) {
            $is_stuck = false;
            $waiting_on = 'none';
            $current_state = $application_status;
            $summary = 'Application does not look stuck.';
            $reason = 'Application has reached a final or completed status.';
            $next_action = 'No action required unless certificate/NOC download is not available.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Draft / saved means user has not finally submitted
        |--------------------------------------------------------------------------
        */

        if ($application_status === 'draft') {
            $is_stuck = false;
            $waiting_on = 'applicant';
            $current_state = 'draft_not_submitted';
            $summary = 'Application is saved as draft.';
            $reason = 'Applicant has saved the form as draft and has not submitted it yet.';
            $next_action = 'Applicant should complete and submit the application.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | 3. Send back should be understood from application status also
    | This handles old/no-assignment applications.
    |--------------------------------------------------------------------------
    */

        if ($application_status === 'send_back') {
            $waiting_on = 'applicant';
            $current_state = 'clarification_pending_from_user';
            $summary = 'Application was sent back.';
            $reason = 'Application status is send_back, so applicant action is pending.';
            $next_action = 'Applicant should check remarks, upload required clarification/documents, and resubmit.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Payment may complete but status may remain saved. That should be treated as system issue.
        |--------------------------------------------------------------------------
        */
        if ($application_status === 'saved' && $payment_status === 'paid') {
            $waiting_on = 'system';
            $current_state = 'payment_paid_but_status_not_advanced';
            $summary = 'Payment is paid but application status is still saved.';
            $reason = 'After successful payment, application status should move from saved to submitted or approved, but it did not.';
            $next_action = 'Admin should check PaymentSuccessService/payment callback and application status update.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }
        /*
        |--------------------------------------------------------------------------
        | 5. Payment pending
        |--------------------------------------------------------------------------
        */

        if ($payment_status === 'pending') {
            if ($is_zero_fee_application) {
                $waiting_on = 'system';
                $current_state = 'zero_fee_payment_status_mismatch';
                $summary = 'Zero-fee application has pending payment status.';
                $reason = 'This application appears to have no payable amount, but payment status is still pending.';
                $next_action = 'Admin should verify fee calculation and payment status update.';
            } else {
                $waiting_on = 'applicant';
                $current_state = 'payment_pending';
                $summary = 'Application is waiting for payment.';
                $reason = 'Application has been saved for payment. Payment is still pending.';
                $next_action = 'Applicant should complete the payment to submit the application.';
            }

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Paid but GRN missing only for payable applications
        |--------------------------------------------------------------------------
        */

        if (
            $payment_status === 'paid'
            && empty($application->GRN_number)
            && $has_payable_amount
            && !$is_zero_fee_application
        ) {
            $waiting_on = 'system';
            $current_state = 'grn_missing';
            $summary = 'Payment is paid but GRN number is missing.';
            $reason = 'Payment is marked paid for a payable application, but GRN number is not available.';
            $next_action = 'Admin should verify payment writeback or GRN generation.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 7. If no approval flow exists
        |--------------------------------------------------------------------------
        */

        if (!$has_approval_flow) {
            if ($application_status === 'approved') {
                $is_stuck = false;
                $waiting_on = 'none';
                $current_state = 'approved';
                $summary = 'Application does not look stuck.';
                $reason = 'This service does not have approval flow and the application is approved.';
                $next_action = 'No action required unless certificate/NOC download is not available.';
            } elseif ($application_status === 'saved' && $payment_status === 'pending') {
                $waiting_on = 'applicant';
                $current_state = 'payment_pending';
                $summary = 'Application is waiting for payment.';
                $reason = 'This service has no approval flow, but payment is still pending.';
                $next_action = 'Applicant should complete payment. After successful payment, application should become approved.';
            } elseif ($application_status === 'submitted') {
                $waiting_on = 'system';
                $current_state = 'final_status_update_pending';
                $summary = 'Application is submitted but service has no approval flow.';
                $reason = 'For no-approval-flow service, application should usually become approved after payment/no-fee handling.';
                $next_action = 'Admin should check final status update or certificate generation logic.';
            } else {
                $waiting_on = 'system';
                $current_state = 'approval_flow_missing_or_not_required';
                $summary = 'No approval flow found for this application.';
                $reason = 'Service approval flow is missing or this service may not require approval flow.';
                $next_action = 'Admin should verify service approval flow configuration.';
            }

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Approval flow exists but no assignment
        |--------------------------------------------------------------------------
        */

        if (!$latest_assignment) {
            $waiting_on = 'system';
            $current_state = 'assignment_missing';
            $summary = 'No workflow assignment found for this application.';
            $reason = 'Service has approval flow, but application has no latest assignment row.';
            $next_action = 'Admin should check approval flow assignment creation.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 9. Latest assignment pending
        |--------------------------------------------------------------------------
        */

        if ($assignment_status === 'pending' && empty($latest_assignment->action_taken_at)) {
            $department_name = $latest_assignment->department_name ?? 'assigned department';

            $waiting_on = 'department';
            $current_state = 'department_action_pending';
            $summary = "Application is pending with {$department_name}.";
            $reason = 'Latest assignment status is pending and no department/officer action has been taken yet.';
            $next_action = 'Department/officer should review and take action on the application.';

            return compact(
                'is_stuck',
                'waiting_on',
                'current_state',
                'summary',
                'reason',
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 10. Latest assignment send_back
        |--------------------------------------------------------------------------
        */

        if ($assignment_status === 'send_back' && !empty($latest_assignment->action_taken_at)) {
            if ($application_status === 're_submitted') {
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
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 11. Latest assignment approved but application not final
        |--------------------------------------------------------------------------
        */

        if ($assignment_status === 'approved' && !empty($latest_assignment->action_taken_at)) {
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
                'next_action',
                'payment_note'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 12. Fallback
        |--------------------------------------------------------------------------
        */

        return compact(
            'is_stuck',
            'waiting_on',
            'current_state',
            'summary',
            'reason',
            'next_action',
            'payment_note'
        );
    }
}
