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

class PaymentController extends Controller
{

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

            $total_amount = $applications->sum('total_fee');

            $payment_order = PaymentOrder::create([
                'user_id' => $user_id,
                'application_id' => json_encode($application_ids),
                'payment_amount' => $total_amount,
                'payment_created_on' => now(),
                'payment_updated_on' => now(),
                'payment_status' => 'initiated',
                'transaction_id' => null,
            ]);

            DB::commit();

            $total_amount = $applications->sum('total_fee');
            $scheme_count = $applications->count();

            $order_id = $payment_order->id;
            $dept_code = 'FIN';
            $dto_code = '99';
            $ddo_code = '99001';
            $sto_code = '99';
            $user_id = "finswgt";
            $valid_upto = Carbon::today()->format('d/m/Y');

            $scheme_count = $scheme_count;

            $scheme_names = [];
            $fee_amounts  = [];

            foreach ($applications as $application) {
                $scheme_names[] = $application->service->service_code ?? 'NA';
                $fee_amounts[]  = $application->total_fee ?? 0;
            }

            $scheme_count = count($scheme_names);
            $return_url = request()->getSchemeAndHttpHost() . '/api/user/payment-callback';

            $secret_key = config('egras.secret_key');

            $hash_parts = [
                $dto_code,
                $sto_code,
                $ddo_code,
                $dept_code,
                $user_id,
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

            $hash_string = implode('|', $hash_parts);

            $hash = base64_encode(hash_hmac('sha256', $hash_string, $secret_key, true));

            $form_html  = '<html><body>';
            $form_html .= '<p>Review and edit values before sending to e-GRAS.</p>';
            $form_html .= '<form id="egrasForm" name="process_payment" method="POST" action="https://www.egras.tripura.gov.in/DeptPrePaymentReqHandler.aspx">';
            $form_html .= '<table>';

            $form_html .= '<tr><td>DTO Code</td><td><input type="text" name="DTO" value="' . $dto_code . '"/></td></tr>';
            $form_html .= '<tr><td>STO Code</td><td><input type="text" name="STO" value="' . $sto_code . '"/></td></tr>';
            $form_html .= '<tr><td>DDO Code</td><td><input type="text" name="DDO" value="' . $ddo_code . '"/></td></tr>';
            $form_html .= '<tr><td>Department Code</td><td><input type="text" name="Deptcode" value="' . $dept_code . '"/></td></tr>';
            $form_html .= '<tr><td>User ID</td><td><input type="text" name="UserID" value="' . $user_id . '"/></td></tr>';
            $form_html .= '<tr><td>Order id</td><td><input type="text" name="Applicationnumber" value="' . $order_id . '"/></td></tr>';
            $form_html .= '<tr><td>Full Name</td><td><input type="text" name="Fullname" value="' . $user->authorized_person_name . '"/></td></tr>';
            $form_html .= '<tr><td>City</td><td><input type="text" name="Cityname" value="' . $user->registered_enterprise_city . '"/></td></tr>';
            $form_html .= '<tr><td>Address</td><td><input type="text" name="Address" value="' . $user->registered_enterprise_address . '"/></td></tr>';
            $form_html .= '<tr><td>Office Name</td><td><input type="text" name="Officename" value="' . $user->name_of_enterprise . '"/></td></tr>';
            $form_html .= '<tr><td>Challan Year</td><td><input type="text" name="ChallanYear" value="2526"/></td></tr>';
            $form_html .= '<tr><td>PIN Code</td><td><input type="text" name="PINCODE" value="799001"/></td></tr>';
            $form_html .= '<tr><td>Bank Code</td><td><input type="text" name="Bank" value="0001509"/></td></tr>';
            $form_html .= '<tr><td>Remarks</td><td><input type="text" name="Remarks" value="Swaagat Payment"/></td></tr>';
            $form_html .= '<tr><td>Email</td><td><input type="text" name="Securityemail" value="' . $user->email_id . '"/></td></tr>';
            $form_html .= '<tr><td>Phone</td><td><input type="text" name="Securityphone" value="' . $user->mobile_no . '"/></td></tr>';
            $form_html .= '<tr><td>Valid Upto</td><td><input type="text" name="VALID_UPTO" value="' . $valid_upto . '"/></td></tr>';
            $form_html .= '<tr><td>Payment Type</td><td><input type="text" name="ptype" value="N"/></td></tr>';
            $form_html .= '<tr><td>Payment Mode</td><td><input type="text" name="paymentmode" value=""/></td></tr>';
            $form_html .= '<tr><td>Total Amount</td><td><input type="text" name="TotalAmount" value="' . $total_amount . '"/></td></tr>';
            $form_html .= '<tr><td>Hash</td><td><input type="text" name="hash" value="' . $hash . '"/></td></tr>';
            $form_html .= '<tr><td>Return URL</td><td><input type="text" name="UURL" value="' . $return_url . '"/></td></tr>';
            $form_html .= '<tr><td>Scheme Count</td><td><input type="text" name="SCHEMECOUNT" value="1"/></td></tr>';
            for ($i = 0; $i < $scheme_count; $i++) {
                $idx = $i + 1;
                $schemeName = htmlspecialchars($scheme_names[$i], ENT_QUOTES, 'UTF-8');

                $form_html .= '<tr><td>Scheme Name ' . $idx . '</td><td><input type="text" name="SCHEMENAME' . $idx . '" value="' . $schemeName . '"/></td></tr>';
                $form_html .= '<tr><td>Fee Amount ' . $idx . '</td><td><input type="text" name="FEEAMOUNT' . $idx . '" value="' . $fee_amounts[$i] . '"/></td></tr>';
            }

            $form_html .= '</table>';

            $form_html .= '<button type="submit">Send to e-GRAS</button>';
            $form_html .= '</form>';
            $form_html .= '</body></html>';

            return $form_html;
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'status_message' => $e->getMessage(),
            ], 500);
        }
    }

    public function payment_callback(Request $request)
    {
        Log::info("Payment callback req: " . json_encode($request->all()));

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

            DB::beginTransaction();

            $status_lower = strtolower($request->input('status'));
            $secret = config('egras.secret_key');
            $frontendurl = config('payment.frontendurl');

            if (!$order_id) {
                $msg = 'Order ID not found';
                Log::info($msg);
                return redirect()->away(
                    $frontendurl . '?status=failed&message=' . urlencode($msg)
                );
            }

            $hash_str = $order_id . "|" . $total . "|" . $grn . "|" . $status . "|" . $CIN . "|" . $tdate . "|" . $payment_type . "|" . $bankcode;
            $generated_hash = base64_encode(hash_hmac('sha256', $hash_str, $secret, true));


            if ($generated_hash !== $hash) {
                if (!$order_id) {
                    $msg = 'Hash verification failed';
                    Log::info($msg);
                    return redirect()->away(
                        $frontendurl . '?status=failed&message=' . urlencode($msg)
                    );
                }
            }

            $payment = PaymentOrder::where('id', $order_id)
                ->where('payment_status', 'initiated')
                ->first();

            if (!$payment) {
                $msg = 'Already processed or invalid order';
                Log::info($msg);
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
                'payment_datetime'      => Carbon::createFromFormat('d/m/Y H:i:s', $trandatetime)->toIso8601String(),
                'gateway_response'  => json_encode($request->all()),
                'updated_at' => now()
            ]);

            if ($status_lower == "success") {

                $ids = json_decode($payment->application_id, true);

                if (!is_array($ids) || count($ids) === 0) {
                    $msg = 'Invalid application IDs';
                    Log::info($msg);
                    return redirect()->away(
                        $frontendurl . '?status=failed&order_id=' . $order_id . '&message=' . urlencode($msg)
                    );
                }

                $applications = UserServiceApplication::whereIn('id', $ids)->get();

                foreach ($applications as $app) {

                    if (!is_null($app->total_fee)) {

                        $amount_to_pay = $app->total_fee;
                        $status = 'submitted';
                    } elseif (!is_null($app->extra_payment)) {

                        $amount_to_pay = $app->extra_payment;
                        $status = 're_submitted';
                    }

                    UserServiceApplication::where('id', $app->id)->update([
                        'payment_status'   => 'paid',
                        'paid_amount'      => $amount_to_pay,
                        'status'           => $status,
                        'GRN_number'       => $grn,
                        'payment_transId'  => $CIN,
                        'payment_time'     => Carbon::createFromFormat('d/m/Y H:i:s', $trandatetime)->toIso8601String(),
                        'updated_at'       => now(),
                    ]);
                }
            }

            DB::commit();

            if ($status_lower == 'success') {
                $msg = 'Payment processed successfully';
                Log::info($msg);
                return redirect()->away(
                    $frontendurl
                        . '?status=success'
                        . '&order_id=' . $order_id
                        . '&amount=' . $total
                        . '&message=' . urlencode($msg)
                );
            } else {
                $msg = 'Payment failed with status: ' . $status;
                Log::info($msg);
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
            Log::info($msg);
            return redirect()->away(
                config('payment.frontendurl')
                    . '?status=failed'
                    . '&message=' . urlencode($msg)
            );
        }
    }

    // public function payment_common_process(PaymentOrder $payment)
    // {

    //     DB::beginTransaction();

    //     try {


    //         $application = UserServiceApplication::find($payment->application_id)->first();

    //         $user = $application->user_id;

    //         if ($application->renewal && $application->renewalYear) {
    //             $application->NOC_expiry_date = Carbon::parse($application->PreviousNOCexpiryDate)
    //                 ->addYears($application->renewalYear);
    //         }

    //         $application->NSW_license_status = 1;
    //         $application->NOC_application_date = now();
    //         $application->NOC_generationDate = now();

    //         // Generate PDF
    //         $pdfOptions = new Options();
    //         $pdfOptions->set('defaultFont', 'sans-serif');
    //         $pdfOptions->set('isRemoteEnabled', true);
    //         $dompdf = new Dompdf($pdfOptions);

    //         $pdfHtml = view('pdf.noc_receipt', ['application' => $application])->render();
    //         $dompdf->loadHtml($pdfHtml);
    //         $dompdf->setPaper('A4', 'portrait');
    //         $dompdf->render();

    //         $pdfPath = 'noc_' . $application->id . '_' . time() . '.pdf';
    //         Storage::put($pdfPath, $dompdf->output());
    //         $application->field_noc_certificate = $pdfPath;

    //         UserServiceApplication::where('id', $payment->application_id)
    //             ->update([
    //                 'NSW_license_status' => 1,
    //                 'NOC_application_date' => now(),
    //                 'NOC_generationDate' => now(),
    //                 'NOC_certificate' => $pdfPath
    //             ]);

    //         // Insert workflow history
    //         // ApplicationWorkflowHistory::create([
    //         //     'application_id' => $application->id,
    //         //     'service_id' => $application->service_id ?? null,
    //         //     'step_number' => 1, // set dynamically if needed
    //         //     'step_type' => null, // type column not needed
    //         //     'department_id' => $application->department_id ?? null,
    //         //     'hierarchy_level' => 1, // adjust if needed
    //         //     'action_taken_by' => $user->id ?? null,
    //         //     'action_taken_at' => Carbon::now(),
    //         //     'status' => $application->status,
    //         //     'remarks' => 'Payment processed successfully',
    //         //     'status_file' => $application->field_noc_certificate ?? null,
    //         //     'source' => 'payment',
    //         // ]);


    //         DB::commit();
    //     } catch (\Exception $e) {

    //         DB::rollBack();

    //         return response()->json([
    //             'status' => 0,
    //             'message' => 'Something went wrong.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function generateEncryptionKey($grn)
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
                'payment_status' => 'required|string|in:pending,paid',
            ]);

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $user_id = Auth::id();

            $service_user_applications = UserServiceApplication::where('user_id', $user_id)->where('payment_status', $request->payment_status)->orderByDesc('created_at')->paginate(10);

            if ($service_user_applications->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No applications found for the given status.',
                ], 404);
            }

            foreach ($service_user_applications as $application) {
                $amount = null;
                $payment_type = null;

                if (!is_null($application->total_fee)) {

                    $amount = $application->total_fee;
                    $payment_type = 'Application Fee Payment';
                } elseif (!is_null($application->extra_payment)) {

                    $amount = $application->extra_payment;
                    $payment_type = 'Extra Payment Raised';
                }
                $response_data[] = [
                    'user_service_application_id' => $application->id,
                    'application_id' => $application->applicationId,
                    'service_title_or_description' => $application->service->service_title_or_description ?? null,
                    'application_date' => $application->application_date ?? null,
                    'payment_type' => $payment_type,
                    'amount' => $amount,
                    'payment_status'  => $application->payment_status ?? null,
                    'grn_number'  => $application->GRN_number ?? null,
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
}
