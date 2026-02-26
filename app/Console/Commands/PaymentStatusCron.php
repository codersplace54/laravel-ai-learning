<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\PaymentOrder;
use App\Models\UserServiceApplication;
use App\Models\User;
use App\Traits\LogsActivity;
use Carbon\Carbon;

class PaymentStatusCron extends Command
{
    use LogsActivity;
    protected $signature = 'payment:check-status';
    protected $description = 'Check payment status from eGrass server and update applications';

    public function handle()
    {
        $this->info('Payment status cron started');

        $orders = PaymentOrder::whereIn('payment_status', ['initiated', 'pending'])
            ->where('payment_amount', '>', 0)
            ->where('created_at', '>=', Carbon::now()->subDays(3))
            ->orderBy('id', 'desc')
            ->limit(200)
            ->get();

        $processed_order_ids = [];
        $updated_order_ids = [];
        $updated_applications = 0;
        $amount_mismatch_skipped = [];
        $not_found_skipped = [];
        $fail_skipped = [];
        $api_error_orders = [];
        $invalid_response_orders = [];
        $db_error_orders = [];

        foreach ($orders as $order) {
            $processed_order_ids[] = $order->order_id;

            $response = $this->call_soap_api($order->order_id, 'FIN');

            if (!$response) {
                $api_error_orders[] = $order->order_id;
                Log::error("API error for order {$order->order_id}: No response from SOAP API");
                continue;
            }

            try {
                libxml_use_internal_errors(true);

                $response_xml = trim($response);
                $response_xml = preg_replace('/^\xEF\xBB\xBF/', '', $response_xml); // remove BOM
                $response_xml = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $response_xml); // clean bad chars

                $xml = simplexml_load_string($response_xml, 'SimpleXMLElement', LIBXML_NOCDATA);

                if ($xml === false) {
                    $invalid_response_orders[] = $order->order_id;
                    libxml_clear_errors();
                    Log::error("Invalid XML response for order {$order->order_id}");
                    continue;
                }

                $namespace = 'http://tempuri.org/';
                $xml->registerXPathNamespace('ns', $namespace);
                $result = $xml->xpath('//ns:GetGrnDetails_identityResult');

                if (!$result || !isset($result[0])) {
                    $invalid_response_orders[] = $order->order_id;
                    Log::error("Missing GetGrnDetails_identityResult for order {$order->order_id}");
                    continue;
                }

                $json_data = json_decode((string) $result[0], true);

                if (!$json_data || !is_array($json_data)) {
                    $invalid_response_orders[] = $order->order_id;
                    Log::error("Invalid JSON data for order {$order->order_id}: " . (string) $result[0]);
                    continue;
                }

                $matched_record = null;
                foreach ($json_data as $record) {
                    if (!isset($record['Status']) || !isset($record['Amount'])) {
                        continue;
                    }

                    if ($record['Status'] === 'Success') {
                        $external_amount = (float) $record['Amount'];
                        $db_amount = (float) $order->payment_amount;

                        if (abs($external_amount - $db_amount) < 0.01) {
                            $matched_record = $record;
                            break;
                        }
                    }
                }

                if (!$matched_record) {
                    $has_success = collect($json_data)->contains('Status', 'Success');
                    if ($has_success) {
                        $amount_mismatch_skipped[] = $order->order_id;
                        Log::warning("Amount mismatch for order {$order->order_id}: DB={$order->payment_amount}, Response=" . json_encode($json_data));
                    } elseif (collect($json_data)->contains('Status', 'Fail')) {
                        $fail_skipped[] = $order->order_id;
                    } else {
                        $not_found_skipped[] = $order->order_id;
                    }
                    continue;
                }

                DB::beginTransaction();

                try {
                    $order->update([
                        'payment_status' => 'success',
                        'GRN_number' => $matched_record['GRN'] ?? null,
                        'payment_datetime' => now(),
                        'updated_by_cron' => true
                    ]);

                    $updated_order_ids[] = $order->order_id;

                    $application_ids = json_decode($order->application_id, true);

                    if (is_array($application_ids) && count($application_ids) > 0) {
                        $updated = UserServiceApplication::whereIn('id', $application_ids)
                            ->where('payment_status', 'pending')
                            ->update([
                                'payment_status' => 'paid',
                                'status' => 'submitted',
                                'GRN_number' => $matched_record['GRN'] ?? null,
                                'payment_time' => now()
                            ]);

                        $updated_applications += $updated;
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $db_error_orders[] = $order->order_id;
                    Log::error("DB error updating order {$order->order_id}: " . $e->getMessage());
                }
            } catch (\Exception $e) {
                $invalid_response_orders[] = $order->order_id;
                Log::error("Error processing order {$order->order_id}: " . $e->getMessage() . " | Line: " . $e->getLine());
            }
        }

        $this->info("\n=== Payment Status Cron Summary ===");
        $this->info("Processed orders: " . count($processed_order_ids) . " (" . implode(',', $processed_order_ids) . ")");
        $this->info("Updated orders: " . count($updated_order_ids) . (count($updated_order_ids) ? " (" . implode(',', $updated_order_ids) . ")" : ""));
        $this->info("Updated applications: {$updated_applications}");
        $this->info("Amount mismatch skipped: " . count($amount_mismatch_skipped) . (count($amount_mismatch_skipped) ? " (" . implode(',', $amount_mismatch_skipped) . ")" : ""));
        $this->info("Not found skipped: " . count($not_found_skipped) . (count($not_found_skipped) ? " (" . implode(',', $not_found_skipped) . ")" : ""));
        $this->info("Fail skipped: " . count($fail_skipped) . (count($fail_skipped) ? " (" . implode(',', $fail_skipped) . ")" : ""));
        $this->info("API errors: " . count($api_error_orders) . (count($api_error_orders) ? " (" . implode(',', $api_error_orders) . ")" : ""));
        $this->info("Invalid responses: " . count($invalid_response_orders) . (count($invalid_response_orders) ? " (" . implode(',', $invalid_response_orders) . ")" : ""));
        $this->info("DB errors: " . count($db_error_orders) . (count($db_error_orders) ? " (" . implode(',', $db_error_orders) . ")" : ""));

        $user = User::first();
        $this->logActivity('Payment status Cron runned', null, $user, [
            'processed_order_ids' => implode(',', $processed_order_ids),
            'updated_order_ids' => implode(',', $updated_order_ids),
            'updated_applications' => $updated_applications,
            'amount_mismatch_order_ids' => implode(',', $amount_mismatch_skipped),
            'not_found_order_ids' => implode(',', $not_found_skipped),
            'fail_order_ids' => implode(',', $fail_skipped),
            'api_error_order_ids' => implode(',', $api_error_orders),
            'invalid_response_order_ids' => implode(',', $invalid_response_orders),
            'db_error_order_ids' => implode(',', $db_error_orders)
        ], 'Payment status Cron');

        return Command::SUCCESS;
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
                Log::error('SOAP API Error: ' . curl_error($ch));
                return false;
            }

            curl_close($ch);
            return $response;
        } catch (\Exception $e) {
            Log::error('SOAP API Exception: ' . $e->getMessage());
            return false;
        }
    }
}
