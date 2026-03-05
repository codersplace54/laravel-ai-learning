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
use App\Traits\LogsActivity;
use Illuminate\Support\Facades\Http;

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

            $applications = UserServiceApplication::whereIn('id', $application_ids)
                ->where('user_id', $user_id)
                ->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No applications found for the given IDs.',
                ], 404);
            }

            $scheme_names = [];
            $fee_amounts  = [];

            foreach ($applications as $application) {
                if ($application->extra_payment !== null && $application->payment_status === 'pending') {
                    $amount = $application->extra_payment;
                } else {
                    $amount = $application->effective_fee ?? $application->total_fee ?? 0;
                }

                if ($amount <= 0) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 0,
                        'message' => 'Fee amount cannot be zero for application ID: ' . $application->id,
                    ], 400);
                }

                $scheme_names[] = $application->service->egras_scheme_code ?? 'NA';
                $fee_amounts[]  = $amount;
            }

            $scheme_count = count($scheme_names);
            $total_amount = array_sum($fee_amounts);

            $payment_order = PaymentOrder::create([
                'user_id'            => $user_id,
                'application_id'     => json_encode($application_ids),
                'payment_amount'     => $total_amount,
                'payment_created_on' => now(),
                'payment_updated_on' => now(),
                'payment_status'     => 'initiated',
                'transaction_id'     => null,
            ]);

            $payment_order->update([
                'order_id' => 'SW' . $payment_order->id
            ]);

            DB::commit();

            $order_id   = $payment_order->order_id;
            $dept_code  = 'FIN';
            $dto_code   = '99';
            $ddo_code   = '99001';
            $sto_code   = '99';
            $egrasUserId = 'finswgt';
            $valid_upto = Carbon::today()->format('d/m/Y');

            $return_url = url('/user/payment-callback');

            $secret_key = config('egras.secret_key');

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
            ];

            for ($i = 0; $i < $scheme_count; $i++) {
                $hash_parts[] = $scheme_names[$i];
                $hash_parts[] = $fee_amounts[$i];
            }

            $hash_parts[] = $return_url;

            $hash       = base64_encode(hash_hmac('sha256', implode('|', $hash_parts), $secret_key, true));

            $form_html  = '<html><body>';
            $form_html .= '<p>Redirecting to e-GRAS. Please wait...</p>';

            $form_html .= '<form id="egrasForm" name="process_payment" method="POST" action="https://swaagatbackend.tripura.gov.in/test_payment.php">';

            $form_html .= '<input type="hidden" name="DTO" value="' . $dto_code . '"/>';
            $form_html .= '<input type="hidden" name="STO" value="' . $sto_code . '"/>';
            $form_html .= '<input type="hidden" name="DDO" value="' . $ddo_code . '"/>';
            $form_html .= '<input type="hidden" name="Deptcode" value="' . $dept_code . '"/>';
            $form_html .= '<input type="hidden" name="UserID" value="' . $egrasUserId . '"/>';
            $form_html .= '<input type="hidden" name="Applicationnumber" value="' . $order_id . '"/>';
            $form_html .= '<input type="hidden" name="Fullname" value="' . $user->authorized_person_name . '"/>';
            $form_html .= '<input type="hidden" name="Cityname" value="' . $user->registered_enterprise_city . '"/>';
            $form_html .= '<input type="hidden" name="Address" value="' . $user->registered_enterprise_address . '"/>';
            $form_html .= '<input type="hidden" name="Officename" value="' . $user->name_of_enterprise . '"/>';
            $form_html .= '<input type="hidden" name="ChallanYear" value="2526"/>';
            $form_html .= '<input type="hidden" name="PINCODE" value="799001"/>';
            $form_html .= '<input type="hidden" name="Bank" value="0001509"/>';
            $form_html .= '<input type="hidden" name="Remarks" value="Swaagat Payment"/>';
            $form_html .= '<input type="hidden" name="Securityemail" value="' . $user->email_id . '"/>';
            $form_html .= '<input type="hidden" name="Securityphone" value="' . $user->mobile_no . '"/>';
            $form_html .= '<input type="hidden" name="VALID_UPTO" value="' . $valid_upto . '"/>';
            $form_html .= '<input type="hidden" name="ptype" value="N"/>';
            $form_html .= '<input type="hidden" name="paymentmode" value=""/>';
            $form_html .= '<input type="hidden" name="TotalAmount" value="' . $total_amount . '"/>';
            $form_html .= '<input type="hidden" name="hash" value="' . $hash . '"/>';
            $form_html .= '<input type="hidden" name="UURL" value="' . $return_url . '"/>';
            $form_html .= '<input type="hidden" name="SCHEMECOUNT" value="' . $scheme_count . '"/>';

            for ($i = 0; $i < $scheme_count; $i++) {
                $idx        = $i + 1;
                $schemeName = htmlspecialchars($scheme_names[$i], ENT_QUOTES, 'UTF-8');

                $form_html .= '<input type="hidden" name="SCHEMENAME' . $idx . '" value="' . $schemeName . '"/>';
                $form_html .= '<input type="hidden" name="FEEAMOUNT' . $idx . '" value="' . $fee_amounts[$i] . '"/>';
            }

            $form_html .= '<input type="submit" value="Submit"/>';
            $form_html .= '</form>';
            // $form_html .= '<script>document.getElementById("egrasForm").submit();</script>';
            $form_html .= '</body></html>';
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

            if ($status && stripos($status, 'One process is already running') !== false) {
                $frontendurl = config('payment.frontendurl');
                return redirect()->away(
                    $frontendurl . '?status=failed&message=' . urlencode('Please try again after some time.')
                );
            }

            DB::beginTransaction();

            $status_lower = strtolower($request->input('status'));
            $secret = config('egras.secret_key');
            $frontendurl = config('payment.frontendurl');

            $dt = $trandatetime ?: ($tdate ? ($tdate . ' 00:00:00') : null);
            $dt = $dt ? str_replace('-', '/', $dt) : null;
            $payment_datetime = $dt ? Carbon::createFromFormat('d/m/Y H:i:s', $dt) : null;

            if (!$order_id) {

                $msg = $status && stripos($status, 'These schemes are not') !== false ? $status : 'Order ID not found';
                Log::channel('payment')->error('Order ID not found in callback');
                return redirect()->away(
                    $frontendurl . '?status=failed&message=' . urlencode($msg)
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

                $applications = UserServiceApplication::whereIn('id', $ids)->get();

                foreach ($applications as $application) {

                    if ($application->extra_payment != null && $application->payment_status == "pending") {
                        $amount = $application->extra_payment;
                        $status = 're_submitted';
                    } else {
                        $amount = $application->effective_fee ?? $application->total_fee ?? 0;
                        $status = 'submitted';
                    }

                    if ($application->current_step_number == 0) {
                        $status = 'approved';
                    }


                    $user_service_application =  UserServiceApplication::where('id', $application->id)->update([
                        'payment_status'   => 'paid',
                        'paid_amount'      => $amount,
                        'status'           => $status,
                        'GRN_number'       => $grn,
                        'payment_transId'  => $CIN,
                        'payment_time' => $payment_datetime,
                        'updated_at'       => now(),
                    ]);


                    if ($application->is_third_party == 1) {

                        $url = 'https://pwdwrtripura.in/api/third-party/payment-update';

                        $payload = [
                            "swaagat_user_id"  => $user_service_application->user_id,
                            "amount"           => $amount,
                            "status"           => "success",
                            "transaction_id"   => $grn,
                            "application_id"   => $user_service_application->applicationId,
                        ];

                        $response = Http::acceptJson()
                            ->asJson()
                            ->timeout(20)
                            ->post($url, $payload);

                        if ($response->successful()) {

                            $result = $response->json();

                            Log::channel('payment')->info('Third-party pwdwrtripura success response', [
                                'status_code' => $response->status(),
                                'response'    => $result,
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
                        'GRN' => $application->GRN_number,
                    ]);

                    SmsService::send(
                        $user->mobile_no,
                        $sms['message'],
                        $sms['template_id']
                    );

                    SendWhatsAppNotification::dispatch(
                        $user->mobile_no,
                        'payment_success_v2',
                        [
                            $application->service->service_title_or_description,
                            $application->applicationId,
                            $application->paid_amount ?? 'NA',
                            $application->GRN_number ?? 'NA',
                            Carbon::parse($payment_datetime)->format('d M Y')
                        ],
                        'application_id=' . $application->id
                    );
                }
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

            $response_data = [];
            foreach ($service_user_applications as $application) {
                $amount = null;
                $payment_type = null;

                if ($application->extra_payment != null && $application->payment_status == "pending") {
                    $amount = $application->extra_payment;
                    $payment_type = 'Extra Payment Raised';
                } else {
                    $amount = $application->effective_fee > 0 ? $application->effective_fee : ($application->total_fee ?? 0);
                    $payment_type = 'Application Fee Payment';
                }

                $payment_orders_grns = PaymentOrder::whereJsonContains('application_id', $application->id)
                    ->whereNotNull('GRN_number')
                    ->pluck('GRN_number')
                    ->toArray();

                $response_data[] = [
                    'user_service_application_id' => $application->id,
                    'application_id' => $application->applicationId,
                    'service_title_or_description' => $application->service->service_title_or_description ?? null,
                    'application_date' => $application->application_date ?? null,
                    'payment_type' => $payment_type,
                    'amount' => $amount,
                    'payment_status'  => $application->payment_status ?? null,
                    'grn_number'  => $payment_orders_grns ?? null,
                    'payment_date'  => $application->payment_datetime ?? null,
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service user application fetched successfully.',
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
