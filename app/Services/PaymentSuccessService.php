<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use App\Services\SmsService;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\LabourDeposit;
use App\Models\ServiceApprovalFlow;
use App\Models\User;
use App\Models\UserServiceApplication;
use App\Http\Controllers\Service\CertificateController;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentSuccessService
{
    // Handle all post payment success logic for a given payment order.
    public function handle(array $ids, ?string $grn, ?string $cin, ?Carbon $payment_datetime): void
    {
        $applications = UserServiceApplication::whereIn('id', $ids)->get();

        foreach ($applications as $application) {

            $is_extra_payment = $application->status === 'extra_payment';
            $previous_paid    = $application->paid_amount ?? 0;

            if ($is_extra_payment) {
                $amount = $application->extra_payment;
                $status = 're_submitted';
            } elseif ($previous_paid > 0) {
                $amount = ($application->effective_fee !== null && $application->effective_fee > 0)
                    ? $application->effective_fee
                    : ($application->total_fee ?? 0);
                $status = 're_submitted';
            } elseif ($application->previous_application_id != null) {

                $old_application = UserServiceApplication::find($application->previous_application_id);

                $old_data = json_decode($old_application->application_data, true);
                $new_data = json_decode($application->application_data, true) ?? [];

                $data_changed = empty($old_data)
                    ? true
                    : ($old_data != $new_data);

                $status = $data_changed ? 're_submitted' : 'approved';
                $amount =  $application->total_fee;
            } else {
                $amount = ($application->effective_fee !== null && $application->effective_fee > 0)
                    ? $application->effective_fee
                    : ($application->total_fee ?? 0);
                $status = 'submitted';
            }

            $final_paid_amount = $previous_paid + $amount;

            $has_approval_flow = ServiceApprovalFlow::where('service_id', $application->service_id)->exists();

            if (!$has_approval_flow) {
                $status = 'approved';
            }

            UserServiceApplication::where('id', $application->id)->update([
                'payment_status'  => 'paid',
                'paid_amount'     => $final_paid_amount,
                'status'          => $status,
                'GRN_number'      => $grn,
                'payment_transId' => $cin,
                'payment_time'    => $payment_datetime,
                'updated_at'      => now(),
            ]);

            if ($application->service_id == 37) {
                LabourDeposit::where('application_id', $application->id)->update([
                    'grn_number'     => $grn,
                    'payment_status' => 'paid',
                    'payment_time'   => now(),
                ]);
            }

            $application->refresh();

            if ($is_extra_payment) {
                $current_step = ApplicationWorkflowAssignment::where('application_id', $application->id)
                    ->where('step_number', $application->current_step_number)
                    ->latest('id')
                    ->first();

                if ($current_step) {
                    $current_step->update([
                        'status'          => 'pending',
                        'action_taken_by' => null,
                        'action_taken_at' => null,
                        'remarks'         => null,
                    ]);
                }
            }

            $is_auto_approved_renewal = $application->previous_application_id != null && $status === 'approved';

            if (!$has_approval_flow && $status === 'approved' || $is_auto_approved_renewal) {
                $application->refresh();
                app(CertificateController::class)->auto_generate_certificate($application);
            }

            // Tripura Jal Board
            if ($application->service_id == 107) {
                $jal_board_payload = [
                    'swaagat_user_id' => $application->user_id,
                    'application_id'  => $application->applicationId,
                    'transaction_id'  => $cin,
                ];

                $jal_board_response = Http::acceptJson()->asJson()->timeout(20)
                    ->post('https://tjbbilling.tripura.gov.in/newconnection/tjbupdateconnectionfee', $jal_board_payload);

                if ($jal_board_response->successful()) {
                    Log::channel('payment')->info('Jal Board payment update success', [
                        'status_code' => $jal_board_response->status(),
                        'response'    => $jal_board_response->json(),
                    ]);
                } else {
                    Log::channel('payment')->error('Jal Board payment update failed', [
                        'status_code' => $jal_board_response->status(),
                        'response'    => $jal_board_response->body(),
                    ]);
                }
            }

            if ($application->is_third_party == 1) {
                $url     = 'https://pwdwrtripura.in/api/third-party/payment-update';
                $payload = [
                    'swaagat_user_id' => $application->user_id,
                    'amount'          => $amount,
                    'status'          => 'success',
                    'transaction_id'  => $grn,
                    'application_id'  => $application->applicationId,
                ];

                $response = Http::acceptJson()->asJson()->timeout(20)->post($url, $payload);

                if ($response->successful()) {
                    Log::channel('payment')->info('Third-party pwdwrtripura success response', [
                        'status_code' => $response->status(),
                        'response'    => $response->json(),
                    ]);
                } else {
                    Log::channel('payment')->error('Third-party API pwdwrtripura failed response', [
                        'status_code' => $response->status(),
                        'response'    => $response->body(),
                    ]);
                }
            }

            $user = User::find($application->user_id);

            $sms = SmsService::buildSmsMessage('application_payment', [
                'AMOUNT' => $application->paid_amount,
                'GRN'    => $application->GRN_number,
            ]);

            SmsService::send($user->mobile_no, $sms['message'], $sms['template_id']);

            SendWhatsAppNotification::dispatch(
                $user->mobile_no,
                'payment_success_v2',
                [
                    $application->service->service_title_or_description,
                    $application->applicationId,
                    $application->paid_amount ?? 'NA',
                    $application->GRN_number ?? 'NA',
                    Carbon::parse($payment_datetime)->format('d M Y'),
                ],
                'application_id=' . $application->id
            );
        }
    }
}
