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

            $application_ids = $request->input('application_id');
            $applications = UserServiceApplication::whereIn('id', $application_ids)
                ->where('user_id', $user_id)
                ->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No unpaid applications found for the given IDs.',
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

            $host = $request->getSchemeAndHttpHost();
            $redirect_url = $host . '/process-payment';

            return  response()->json([
                'status' => 1,
                'message' => 'Your payment is redirected',
                'result' => [
                    'order_id' => $payment_order->id,
                    'redirect_url' => $redirect_url,
                    'total_amount' => $total_amount,
                    'applications' => $application_ids,
                ],
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'status_message' => $e->getMessage(),
            ], 500);
        }
    }

    public function process_payment(Request $request)
    {


        try {

            $request->validate([
                'order_id' => 'required|integer',
            ]);

            DB::beginTransaction();

            $order_id = $request->input('order_id');
            $payment_order = PaymentOrder::where('id', $order_id)->first();

            if (!$payment_order || $payment_order->payment_status !== 'initiated') {
                return response()->json([
                    'status' => 0,
                    'status_message' => 'Invalid or already processed payment order.',
                ], 404);
            }

            $application_ids = json_decode($payment_order->application_id, true);
            if (!is_array($application_ids) || empty($application_ids)) {
                return response()->json([
                    'status' => 0,
                    'status_message' => 'Invalid application list.',
                ], 400);
            }

            $applications = UserServiceApplication::whereIn('id', $application_ids)->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'status_message' => 'Applications not found.',
                ], 404);
            }

            $user = Auth::user();
            $total_amount = $applications->sum('total_fee');
            $dept_code = 'FIN';
            $dto_code = '99';
            $ddo_code = '99001';
            $sto_code = '99';
            $user_id = $user->id;
            $scheme_count = '1';
            $scheme_name1 = '1475-00-106-21-06';
            $fee_amount1 = '500';


            $return_url = 'https://swaagatbackend.tripura.gov.in/process-payment';
            // $return_url = 'http://127.0.0.1:8000/api/user/payment-callback';
            $secret_key = config('egras.secret_key');
            //   $return_url = url('/payment-callback');

            $hash_string =
                $dto_code . "|" .
                $sto_code . "|" .
                $ddo_code . "|" .
                $dept_code . "|" .
                $user_id . "|" .
                $order_id . "|" .
                $user->authorized_person_name . "|" .
                $user->mobile_no . "|" .
                $total_amount . "|" .
                $scheme_count . "|" .
                $scheme_name1 . "|" .
                $fee_amount1 . "|" .
                $return_url;

            $hash = base64_encode(hash_hmac('sha256', $hash_string, $secret_key, true));

            $form_html  = '<html><body>';
            $form_html .= '<p>Redirecting to e-GRAS. Please wait...</p>';
            $form_html .= '<form id="egrasForm" name="process_payment" method="POST" action="https://www.egras.tripura.gov.in/DeptPrePaymentReqHandler.aspx">';

            $form_html .= '<input type="hidden" name="DTO" value="' . $dto_code . '"/>';
            $form_html .= '<input type="hidden" name="STO" value="' . $sto_code . '"/>';
            $form_html .= '<input type="hidden" name="DDO" value="' . $ddo_code . '"/>';
            $form_html .= '<input type="hidden" name="Deptcode" value="' . $dept_code . '"/>';
            $form_html .= '<input type="hidden" name="UserID" value="' . $user_id . '"/>';
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
            $form_html .= '<input type="hidden" name="VALID_UPTO" value="20/11/2025"/>';
            $form_html .= '<input type="hidden" name="ptype" value="N"/>';
            $form_html .= '<input type="hidden" name="paymentmode" value=""/>';
            $form_html .= '<input type="hidden" name="TotalAmount" value="' . $total_amount . '"/>';
            $form_html .= '<input type="hidden" name="hash" value="' . $hash . '"/>';
            $form_html .= '<input type="hidden" name="UURL" value="' . $return_url . '"/>';
            $form_html .= '<input type="hidden" name="SCHEMECOUNT" value="1"/>';
            $form_html .= '<input type="hidden" name="SCHEMENAME1" value="1475-00-106-21-06"/>';
            $form_html .= '<input type="hidden" name="FEEAMOUNT1" value="' . $fee_amount1 . '"/>';

            $form_html .= '</form>';
            $form_html .= '<script>document.getElementById("egrasForm").submit();</script>';
            $form_html .= '</body></html>';

            return $form_html;


            DB::commit();

            return response($form_html);
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

        try {


            $order_id = $request->input('Applicationnumber');
            $total = $request->input('amount');
            $grn = $request->input('GRN');
            $status = strtolower($request->input('status'));
            $CIN = $request->input('CIN');
            $tdate = $request->input('tdate');
            $payment_type = $request->input('payment_type');
            $bankcode = $request->input('bankcode');
            $hash = $request->input('hash');

            DB::beginTransaction();

            if (!$order_id) {
                return response()->json(['status' => 0, 'message' => 'Order ID not found'], 400);
            }

            $secret = config('egras.secret_key');
            $success_url = config('payment.frontendsuccessurl');

            $hash_str = $order_id . "|" . $total . "|" . $grn . "|" . $status . "|" . $CIN . "|" . $tdate . "|" . $payment_type . "|" . $bankcode;
            $generated_hash = base64_encode(hash_hmac('sha256', $hash_str, $secret, true));


            // if ($generated_hash !== $hash) {
            //     return response()->json([
            //         'status' => 0,
            //         'status_message' => 'Hash verification failed',
            //         'order_id' => $order_id,
            //     ], 400);
            // }

            $payment = PaymentOrder::where('id', $order_id)
                ->where('payment_status', 'initiated')
                ->first();

            if (!$payment) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Already processed or invalid order'
                ], 400);
            }

            $payment->update([
                //  'payment_status'    => $status,
                'payment_amount'    => $total,
                'gateway'           => 'egras',
                'gateway_order_id'  => $order_id,
                'transaction_id'    => $CIN,
                'gateway_response'  => json_encode($request->all()),
                'updated_at' => now()
            ]);

            if ($status == "success") {

                $ids = json_decode($payment->application_id, true);

                if (!is_array($ids) || count($ids) === 0) {
                    return response()->json(['status' => 0, 'message' => 'Invalid application IDs'], 400);
                }

                $applications = UserServiceApplication::whereIn('id', $ids)->get();

                foreach ($applications as $app) {

                    $amount_to_pay = $app->total_fee ?? $app->approved_fee ?? $app->applied_fee;

                    UserServiceApplication::where('id', $app->id)->update([
                        'payment_status'   => 'paid',
                        'paid_amount'      => $amount_to_pay,
                        'status'           => 'submitted',
                        'GRN_number'       => $grn,
                        'payment_transId'  => $CIN,
                        'payment_time'     => now(),
                        'updated_at'       => now(),
                    ]);
                }

                //$this->payment_common_process($payment);
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'order_id' => $order_id,
                'message' => 'Payment processed successfully',
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
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
}
