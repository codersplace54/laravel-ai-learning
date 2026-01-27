<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\PaymentOrder;
use App\Models\UserServiceApplication;
use Carbon\Carbon;

class PaymentStatusCron extends Command
{
    protected $signature = 'payment:check-status';
    protected $description = 'Check payment status from eGrass server and update applications';

    public function handle()
    {
        Log::info('Payment status cron started');
        
        $orders = PaymentOrder::where('payment_status', 'initiated')
            ->where('payment_amount', '>', 0)
            ->where('created_at', '>=', Carbon::now()->subDays(3)) 
            ->orderBy('id', 'desc')
            ->limit(200)
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            $count++;
            
            $response = $this->call_soap_api($order->id, 'FIN');
            
            if (!$response) {
                continue;
            }
            
            $xml = simplexml_load_string($response);
            $namespace = 'http://tempuri.org/';
            $xml->registerXPathNamespace('ns', $namespace);
            $result = $xml->xpath('//ns:GetGrnDetails_identityResult')[0];
            $readable_response = json_decode((string) $result, true);
            
            if (!$readable_response || !isset($readable_response[0])) {
                continue;
            }
            
            $grn = $readable_response[0]['GRN'];
            $status = $readable_response[0]['Status'];
            
            if ($status == "Success") {
                DB::beginTransaction();
                
                try {
                    $order->update([
                        'payment_status' => 'success',
                        'GRN_number' => $grn,
                        'payment_datetime' => now(),
                        'updated_by_cron' => true
                    ]);
                    
                    $application_ids = json_decode($order->application_id, true);
                    
                    UserServiceApplication::whereIn('id', $application_ids)
                        ->where('payment_status', 'pending')
                        ->update([
                            'payment_status' => 'paid',
                            'status' => 'submitted',
                            'GRN_number' => $grn,
                            'payment_time' => now()
                        ]);
                    
                    DB::commit();
                    
                    Log::info("Payment marked success for order: {$order->id}");
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("Error updating payment status: " . $e->getMessage());
                }
            }
        }
        
        Log::info("Payment status cron completed. Processed {$count} orders");
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