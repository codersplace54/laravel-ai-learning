<?php

namespace App\Http\Controllers\service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use App\Models\PaymentOrder;
use App\Models\UserServiceApplication;
use App\Models\User;
use App\Models\ApplicationWorkflowHistory;
use App\Services\SmsService;
use App\Jobs\SendWhatsAppNotification;
use App\Models\ServiceApprovalFlow;
use App\Traits\LogsActivity;
use App\Models\ThirdPartyStatusLog;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Service\CertificateController;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\UnitDetail;
use App\Models\ServiceFeeRule;
use App\Models\LabourDeposit;
use App\Services\PaymentSuccessService;

class PaymentController extends Controller
{
    use LogsActivity;

    public function update_payment(Request $request)
    {
        try {
            $request->validate([
                'application_id' => 'required|array',
                'application_id.*' => 'integer|exists:user_service_applications,id',
            ]);

            DB::beginTransaction();

            $user = Auth::user();
            $user_id = $user->id;

            $application_ids = array_map('intval', $request->input('application_id'));

            $applications = UserServiceApplication::with('service')
                ->whereIn('id', $application_ids)
                ->where('user_id', $user_id)
                ->get();

            if ($applications->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'status' => 0,
                    'message' => 'No applications found for the given IDs.',
                ], 404);
            }

            $scheme_names = [];
            $fee_amounts  = [];

            foreach ($applications as $application) {

                if ($application->is_third_party == 1) {
                    if (!$application->egras_scheme_code) {
                        DB::rollBack();
                        return response()->json([
                            'status' => 0,
                            'message' => 'Payment details are not yet available. Please contact support for assistance.',
                        ], 400);
                    }
                }

                if ($application->service_id == 37) {

                    $deposit = LabourDeposit::where('application_id', $application->id)->first();

                    $contract_deposit = (float) ($deposit->contract_labour_deposit ?? 0);

                    $ismw_deposit     = (float) ($deposit->ismw_labour_deposit ?? 0);

                    $contract_fee = (float) ($deposit->contract_labour_fee ?? 0);
                    $ismw_fee     = (float) ($deposit->ismw_labour_fee ?? 0);

                    $items = [
                        ['scheme' => '8443-00-103-37-01', 'amount' => $contract_deposit],
                        ['scheme' => '8443-00-103-37-02',     'amount' => $ismw_deposit],
                        ['scheme' => '0230-00-106-37-02',     'amount' => $contract_fee],
                        ['scheme' => '0230-00-101-37-06',         'amount' => $ismw_fee],
                    ];
                    foreach ($items as $item) {
                        if ($item['amount'] > 0) {
                            $scheme_names[] = $item['scheme'];
                            $fee_amounts[]  = (int) $item['amount'];
                        }
                    }
                    continue;
                }

                if ($application->extra_payment !== null && $application->payment_status === 'pending') {
                    $amount = (float) $application->extra_payment;
                } else {
                    $amount = (float) (
                        ($application->effective_fee !== null && $application->effective_fee > 0)
                        ? $application->effective_fee
                        : ($application->total_fee ?? 0)
                    );
                }

                if ($amount <= 0) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 0,
                        'message' => 'Fee amount cannot be zero for application ID: ' . $application->id,
                    ], 400);
                }

                if ($application->is_third_party == 1 && $application->egras_scheme_code) {
                    $scheme_names[] = trim($application->egras_scheme_code);
                } elseif ($application->service_id == 107) {
                    // Jal Board
                    $scheme_names[] = '8229-00-200-35-51';
                } else {
                    $scheme_names[] = trim($application->service->egras_scheme_code ?? 'NA');
                }

                $fee_amounts[] = (int) $amount;
            }

            $scheme_count = count($scheme_names);
            $total_amount = (int) array_sum(array_map('intval', $fee_amounts));

            // Calculate service fee (apply to the first application only)
            $establishment_fee = null;
            $operational_fee   = null;

            foreach ($applications as $app) {
                $service_fee_data = $this->resolve_service_fee($app);

                if ($service_fee_data) {
                    if ($service_fee_data['fee_type'] === 'establishment') {
                        $establishment_fee = (float) $service_fee_data['amount'];
                    } else {
                        $operational_fee = (float) $service_fee_data['amount'];
                    }

                    $service_fee_amount = (int) $service_fee_data['amount'];

                    $scheme_names[] = '8443-00-117-45-01';
                    $fee_amounts[]  = $service_fee_amount;
                    $total_amount   = (int) ($total_amount + $service_fee_amount);

                    break;
                }
            }

            $scheme_count = count($scheme_names);

            $payment_order = PaymentOrder::create([
                'user_id'                => $user_id,
                'application_id'         => json_encode($application_ids),
                'payment_amount'         => (string) $total_amount,
                'payment_created_on'     => now(),
                'payment_updated_on'     => now(),
                'payment_status'         => 'initiated',
                'transaction_id'         => null,
                'establishment_fee_paid' => $establishment_fee,
                'operational_fee_paid'   => $operational_fee,
            ]);

            $payment_order->update([
                'order_id' => 'SW' . $payment_order->id
            ]);

            DB::commit();

            $order_id    = $payment_order->order_id;
            $dept_code   = 'FIN';
            $dto_code    = '99';
            $ddo_code    = '99001';
            $sto_code    = '99';
            $egrasUserId = 'finswgt';
            $valid_upto  = Carbon::today()->format('d/m/Y');
            $return_url  = url('/user/payment-callback');
            $secret_key  = config('egras.secret_key');

            if (empty($user->registered_enterprise_address)) {
                $user->registered_enterprise_address = 'TRIPURA';
                $user->save();
            }

            $hash_parts = [
                $dto_code,
                $sto_code,
                $ddo_code,
                $dept_code,
                $egrasUserId,
                $order_id,
                $user->authorized_person_name,
                $user->mobile_no,
                $total_amount,
                $scheme_count,
                $scheme_names[0] ?? '',
                $fee_amounts[0] ?? '0.00',
                $return_url,
            ];

            $hash = base64_encode(
                hash_hmac('sha256', implode('|', $hash_parts), $secret_key, true)
            );

            $form_html  = '<html><body>';
            $form_html .= '<p>Redirecting to e-GRAS. Please wait...</p>';

            $form_html .= '<form id="egrasForm" name="process_payment" method="POST" action="https://swaagatbackend.tripura.gov.in/test_payment.php">';

            $form_html .= '<input type="hidden" name="DTO" value="' . e($dto_code) . '"/>';
            $form_html .= '<input type="hidden" name="STO" value="' . e($sto_code) . '"/>';
            $form_html .= '<input type="hidden" name="DDO" value="' . e($ddo_code) . '"/>';
            $form_html .= '<input type="hidden" name="Deptcode" value="' . e($dept_code) . '"/>';
            $form_html .= '<input type="hidden" name="UserID" value="' . e($egrasUserId) . '"/>';
            $form_html .= '<input type="hidden" name="Applicationnumber" value="' . e($order_id) . '"/>';
            $form_html .= '<input type="hidden" name="Fullname" value="' . e($user->authorized_person_name) . '"/>';
            $form_html .= '<input type="hidden" name="Cityname" value="' . e($user->registered_enterprise_city) . '"/>';
            $form_html .= '<input type="hidden" name="Address" value="' . e($user->registered_enterprise_address) . '"/>';
            $form_html .= '<input type="hidden" name="Officename" value="' . e($user->name_of_enterprise) . '"/>';
            $form_html .= '<input type="hidden" name="ChallanYear" value="2526"/>';
            $form_html .= '<input type="hidden" name="PINCODE" value="799001"/>';
            $form_html .= '<input type="hidden" name="Bank" value="0001509"/>';
            $form_html .= '<input type="hidden" name="Remarks" value="Swaagat Payment"/>';
            $form_html .= '<input type="hidden" name="Securityemail" value="' . e($user->email_id) . '"/>';
            $form_html .= '<input type="hidden" name="Securityphone" value="' . e($user->mobile_no) . '"/>';
            $form_html .= '<input type="hidden" name="VALID_UPTO" value="' . e($valid_upto) . '"/>';
            $form_html .= '<input type="hidden" name="ptype" value="N"/>';
            $form_html .= '<input type="hidden" name="paymentmode" value=""/>';
            $form_html .= '<input type="hidden" name="TotalAmount" value="' . e($total_amount) . '"/>';
            $form_html .= '<input type="hidden" name="hash" value="' . e($hash) . '"/>';
            $form_html .= '<input type="hidden" name="UURL" value="' . e($return_url) . '"/>';
            $form_html .= '<input type="hidden" name="SCHEMECOUNT" value="' . e($scheme_count) . '"/>';

            for ($i = 0; $i < $scheme_count; $i++) {
                $idx        = $i + 1;
                $schemeName = htmlspecialchars($scheme_names[$i], ENT_QUOTES, 'UTF-8');

                $form_html .= '<input type="hidden" name="SCHEMENAME' . $idx . '" value="' . $schemeName . '"/>';
                $form_html .= '<input type="hidden" name="FEEAMOUNT' . $idx . '" value="' . e($fee_amounts[$i]) . '"/>';
            }

            $form_html .= '<input type="submit" value="Submit"/>';
            $form_html .= '</form>';
            // $form_html .= '<script>document.getElementById("egrasForm").submit();</script>';
            $form_html .= '</body></html>';

            // Log::channel('payment')->info('EGRAS form HTML: ' . $form_html);

            return $form_html;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 0,
                'status_message' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function payment_callback(Request $request)
    {
        Log::channel('payment')->info("Payment callback received", ['request_data' => $request->all()]);

        try {

            $order_id = $request->input('Applicationnumber');
            $total = $request->input('amount');
            $grn = $request->input('GRN');
            $status = $request->input('status');
            $CIN = $request->input('CIN');
            $tdate = $request->input('tdate');
            $payment_type = $request->input('payment_type');
            $bankcode = $request->input('bankcode');
            $hash = $request->input('hash');
            $trandatetime = $request->input('trandatetime');

            $frontendurl = config('payment.frontendurl');

            $known_error_messages = [
                'One process is already running' => 'Please try again after some time.',
            ];

            foreach ($known_error_messages as $pattern => $friendly_msg) {
                if ($status && stripos($status, $pattern) !== false) {
                    return redirect()->away(
                        $frontendurl . '?status=failed&message=' . urlencode($friendly_msg)
                    );
                }
            }

            if (!$order_id && $status) {
                Log::channel('payment')->warning('No order ID in callback, status message received', ['status' => $status]);
                return redirect()->away(
                    $frontendurl . '?status=failed&message=' . urlencode($status)
                );
            }

            DB::beginTransaction();

            $status_lower = strtolower($request->input('status'));
            $secret = config('egras.secret_key');

            $dt = $trandatetime ?: ($tdate ? ($tdate . ' 00:00:00') : null);
            $dt = $dt ? str_replace('-', '/', $dt) : null;
            $payment_datetime = $dt ? Carbon::createFromFormat('d/m/Y H:i:s', $dt) : null;

            if (!$order_id) {
                Log::channel('payment')->error('Order ID not found in callback');
                return redirect()->away(
                    $frontendurl . '?status=failed&message=' . urlencode($status ?? 'Order ID not found')
                );
            }

            $hash_str = $order_id . "|" . $total . "|" . $grn . "|" . $status . "|" . $CIN . "|" . $tdate . "|" . $payment_type . "|" . $bankcode;
            $generated_hash = base64_encode(hash_hmac('sha256', $hash_str, $secret, true));


            if ($generated_hash !== $hash) {

                $msg = 'Hash verification failed';
                Log::channel('payment')->error('Hash verification failed', ['order_id' => $order_id]);
                return redirect()->away(
                    $frontendurl . '?status=failed&message=' . urlencode($msg)
                );
            }

            $payment = PaymentOrder::where('order_id', $order_id)
                ->where('payment_status', 'initiated')
                ->first();

            if (!$payment) {

                $msg = 'Already processed or invalid order';
                Log::channel('payment')->warning('Payment order not found or already processed', ['order_id' => $order_id]);
                return redirect()->away(
                    $frontendurl . '?status=failed&order_id=' . $order_id . '&message=' . urlencode($msg)
                );
            }

            $payment->update([
                'payment_status'    => strtolower($request->input('status')),
                'payment_amount'    => $total,
                'gateway'           => 'egras',
                'gateway_order_id'  => $order_id,
                'transaction_id'    => $CIN,
                'GRN_number'        => $grn,
                'payment_datetime' => $payment_datetime,
                'gateway_response'  => json_encode($request->all()),
                'hash'              => $hash,
                'updated_at' => now()
            ]);

            if ($status_lower == "success") {

                $ids = json_decode($payment->application_id, true);

                if (!is_array($ids) || count($ids) === 0) {

                    $msg = 'Invalid application IDs';
                    Log::channel('payment')->error('Invalid application IDs', ['order_id' => $order_id]);
                    return redirect()->away(
                        $frontendurl . '?status=failed&order_id=' . $order_id . '&message=' . urlencode($msg)
                    );
                }

                app(PaymentSuccessService::class)->handle($ids, $grn, $CIN, $payment_datetime);
            }

            DB::commit();

            if ($status_lower == 'success') {
                $msg = 'Payment processed successfully';
                Log::channel('payment')->info('Payment success', ['order_id' => $order_id, 'amount' => $total]);
                return redirect()->away(
                    $frontendurl
                        . '?status=success'
                        . '&order_id=' . $order_id
                        . '&amount=' . $total
                        . '&message=' . urlencode($msg)
                );
            } else {
                // Log payment failure
                $ids = json_decode($payment->application_id, true);
                $application = UserServiceApplication::whereIn('id', $ids)->first();

                $user = $application?->user;

                $msg = 'Payment failed with status: ' . $status;
                Log::channel('payment')->warning('Payment failed', ['order_id' => $order_id, 'status' => $status]);
                return redirect()->away(
                    $frontendurl
                        . '?status=failed'
                        . '&order_id=' . $order_id
                        . '&amount=' . $total
                        . '&message=' . urlencode($msg)
                );
            }
        } catch (\Exception $e) {

            DB::rollBack();

            $msg = 'Exception: ' . $e->getMessage();
            Log::channel('payment')->error('Payment callback exception', ['error' => $e->getMessage(), 'line' => $e->getLine()]);
            return redirect()->away(
                config('payment.frontendurl')
                    . '?status=failed'
                    . '&message=' . urlencode($msg)
            );
        }
    }


    private function calculate_service_fee(float $project_cost): float
    {
        if (Auth::user()->authorized_person_name === 'Mandeep') return 1.0;

        $slabs = [
            [0,           2500000,    500],
            [2500000,     10000000,   2000],
            [10000000,    50000000,   5000],
            [50000000,    100000000,  7500],
            [100000000,   250000000,  10000],
            [250000000,   500000000,  15000],
            [500000000,   1000000000, 20000],
            [1000000000,  PHP_INT_MAX, 25000],
        ];

        foreach ($slabs as [$min, $max, $fee]) {
            if ($project_cost >= $min && $project_cost < $max) {
                return (float) $fee;
            }
        }

        return 25000.0;
    }

    private function resolve_service_fee(UserServiceApplication $application): ?array
    {
        $service = $application->service;
        if (!$service) return null;

        $noc_type = strtoupper($service->noc_type ?? '');
        $fee_type = $noc_type === 'CFE' ? 'establishment' : 'operational';
        $fee_col  = $fee_type === 'establishment' ? 'establishment_fee_paid' : 'operational_fee_paid';

        $already_paid = UserServiceApplication::where('user_id', $application->user_id)
            ->where('id', '!=', $application->id)
            ->where('payment_status', 'paid')
            ->exists();

        if ($already_paid) return null;

        $unit_detail = UnitDetail::where('user_id', $application->user_id)->first();
        if (!$unit_detail) return null;

        $project_cost = (float) ($unit_detail->investment_details_total_project_cost ?? 0);

        return [
            'fee_type' => $fee_type,
            'amount'   => $this->calculate_service_fee($project_cost),
        ];
    }

    public function generate_encryption_key($grn)
    {
        $sum = 0;

        for ($i = 0; $i < strlen($grn); $i++) {
            $sum += ord($grn[$i]);
        }

        return $sum;
    }

    public function user_service_applications_by_payment_status(Request $request)
    {

        try {

            $request->validate([
                'payment_status' => 'required|string|in:pending,paid,success',
            ]);

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $user_id = Auth::id();

            $service_user_applications = UserServiceApplication::where('user_id', $user_id)
                ->where('payment_status', $request->payment_status)
                ->where(function ($query) {
                    $query->where(function ($q) {
                        $q->whereNotNull('extra_payment')
                            ->where('extra_payment', '>', 0);
                    })
                        ->orWhere(function ($q) {
                            $q->whereNull('extra_payment')
                                ->where(function ($subq) {
                                    $subq->where('effective_fee', '>', 0)
                                        ->orWhere('total_fee', '>', 0);
                                });
                        });
                })
                ->orderByDesc('created_at')
                ->paginate($request->per_page);

            if ($service_user_applications->total() == 0) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No applications found for the given status.',
                ], 404);
            }

            $service_fee = $service_user_applications->isNotEmpty()
                ? $this->resolve_service_fee($service_user_applications->first())
                : null;

            $application_ids = $service_user_applications->pluck('id')->toArray();

            $all_payment_orders = PaymentOrder::where('user_id', $user_id)
                ->whereNotNull('GRN_number')
                ->get();

            $establishment_fee_paid = $all_payment_orders->whereNotNull('establishment_fee_paid')->first()?->establishment_fee_paid;
            $operational_fee_paid   = $all_payment_orders->whereNotNull('operational_fee_paid')->first()?->operational_fee_paid;

            $response_data = [];
            foreach ($service_user_applications as $application) {
                $amount = null;
                $payment_type = null;

                if ($application->extra_payment != null && $application->payment_status == "pending") {
                    $amount = $application->extra_payment;
                    $payment_type = 'Extra Payment Raised';
                } else {
                    $amount = ($application->effective_fee !== null && $application->effective_fee > 0) ? $application->effective_fee : ($application->total_fee ?? 0);
                    $payment_type = 'Application Fee Payment';
                }

                $payment_orders_grns = $all_payment_orders->filter(function ($order) use ($application) {
                    $ids = is_array($order->application_id) ? $order->application_id : json_decode($order->application_id, true);
                    return in_array($application->id, $ids ?? []);
                })->pluck('GRN_number')->toArray();

                $response_data[] = [
                    'user_service_application_id' => $application->id,
                    'application_id' => $application->applicationId,
                    'service_title_or_description' => $application->service->service_title_or_description ?? null,
                    'application_date' => $application->application_date ?? null,
                    'payment_type' => $payment_type,
                    'amount' => $amount,
                    'payment_status'  => $application->payment_status ?? null,
                    'grn_number'  => $payment_orders_grns ?? null,
                    'payment_date'  => $application->payment_time ?? null,
                    'is_third_party' => $application->is_third_party ?? 0,
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service user application fetched successfully.',
                'service_fee' => $service_fee,
                'establishment_fee_paid' => $establishment_fee_paid,
                'operational_fee_paid'   => $operational_fee_paid,
                'data' => $response_data,
                'pagination' => [
                    'current_page' => $service_user_applications->currentPage(),
                    'last_page' => $service_user_applications->lastPage(),
                    'per_page' => $service_user_applications->perPage(),
                    'total' => $service_user_applications->total(),
                    'next_page_url' => $service_user_applications->nextPageUrl(),
                    'prev_page_url' => $service_user_applications->previousPageUrl(),
                ]
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function check_all_pending_payments(Request $request)
    {
        try {
            $orders = PaymentOrder::where('payment_status', 'initiated')
                ->where('payment_amount', '>', 0)
                ->orderBy('id', 'desc')
                ->limit(200)
                ->get();

            if ($orders->isEmpty()) {
                return response()->json(['status' => 1, 'message' => 'No pending payments found']);
            }

            $results = [];

            foreach ($orders as $order) {
                $response = $this->call_soap_api($order->order_id, 'FIN');

                if (!$response) {
                    $results[] = ['order_id' => $order->order_id, 'status' => 'api_error'];
                    continue;
                }

                $xml = simplexml_load_string($response);
                $namespace = 'http://tempuri.org/';
                $xml->registerXPathNamespace('ns', $namespace);
                $result = $xml->xpath('//ns:GetGrnDetails_identityResult')[0];
                $readable_response = json_decode((string) $result, true);

                if (!$readable_response || !isset($readable_response[0])) {
                    $results[] = ['order_id' => $order->order_id, 'status' => 'invalid_response'];
                    continue;
                }

                $grn = $readable_response[0]['GRN'];
                $status = $readable_response[0]['Status'];

                if ($status == "Success") {
                    $results[] = ['order_id' => $order->order_id, 'status' => 'success', 'grn' => $grn];
                } else {
                    $results[] = ['order_id' => $order->order_id, 'status' => 'pending', 'payment_status' => $status];
                }
            }

            $success_count = collect($results)->where('status', 'success')->count();

            return response()->json([
                'status' => 1,
                'message' => "Checked {$orders->count()} orders, {$success_count} are successful",
                'success_count' => $success_count,
                'total_checked' => $orders->count(),
                'results' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 500);
        }
    }

    public function check_payment_status(Request $request)
    {
        try {
            $order_id = $request->input('order_id');

            if (!$order_id) {
                return response()->json(['status' => 0, 'message' => 'Order ID required'], 400);
            }

            $payment = PaymentOrder::where('order_id', $order_id)
                ->where('payment_status', 'initiated')
                ->first();

            if (!$payment) {
                return response()->json(['status' => 0, 'message' => 'Order not found'], 404);
            }

            $response = $this->call_soap_api($order_id, 'FIN');

            if (!$response) {
                return response()->json(['status' => 0, 'message' => 'Unable to check payment status'], 500);
            }

            $xml = simplexml_load_string($response);
            $namespace = 'http://tempuri.org/';
            $xml->registerXPathNamespace('ns', $namespace);
            $result = $xml->xpath('//ns:GetGrnDetails_identityResult')[0];
            $readable_response = json_decode((string) $result, true);

            if (!$readable_response || !isset($readable_response[0])) {
                return response()->json(['status' => 0, 'message' => 'Invalid response from payment gateway'], 500);
            }

            $grn = $readable_response[0]['GRN'];
            $status = $readable_response[0]['Status'];

            return response()->json([
                'status' => 1,
                'order_id' => $order_id,
                'payment_status' => $status,
                'grn' => $grn,
                'message' => 'Payment status checked successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 500);
        }
    }

    private function call_soap_api($identity, $dept)
    {
        try {
            $soap_url = "https://www.egras.tripura.gov.in/Grn_status.asmx?op=GetGrnDetails_identity";

            $soap_request = '<?xml version="1.0" encoding="utf-8"?>
                <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                               xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
                    <soap:Body>
                        <GetGrnDetails_identity xmlns="http://tempuri.org/">
                            <identity>' . $identity . '</identity>
                            <dept>' . $dept . '</dept>
                        </GetGrnDetails_identity>
                    </soap:Body>
                </soap:Envelope>';

            $headers = [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: http://tempuri.org/GetGrnDetails_identity'
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $soap_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $soap_request);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);

            if (curl_error($ch)) {
                Log::channel('payment')->error('SOAP API Error', ['error' => curl_error($ch)]);
                return false;
            }

            curl_close($ch);
            return $response;
        } catch (\Exception $e) {
            Log::channel('payment')->error('SOAP API Exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function get_grn_status(Request $request): JsonResponse
    {
        $request->validate([
            'grn_no' => 'required|string'
        ]);

        $grn = trim($request->grn_no);
        $userId = config('egras.userid');
        $baseUrl = config('egras.grnstatus');
        $key = $this->generate_encryption_key($grn);
        $plainText = $grn . ',' . $userId;
        $keyBytes = array_values(unpack('C*', (string) $key));

        if (count($keyBytes) < 16) {
            $keyBytes = array_pad($keyBytes, 16, 0);
        }

        $aesKey = implode(array_map('chr', $keyBytes));
        $iv = $aesKey;
        $encrypted = openssl_encrypt($plainText, 'AES-128-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);
        $val = urlencode(base64_encode($encrypted));
        $url = $baseUrl . '?val=' . $val . '&key=' . $key;

        return response()->json([
            'status' => '1',
            'url' => $url
        ]);
    }
}
