<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ExternalSmsService
{
    public static function send(string $mobile_no, string $message, string $dlt_template_id, array $gateway_config): array
    {
        $gateway_url   = $gateway_config['gateway_url'];
        $user_name     = $gateway_config['username'];
        $pin           = $gateway_config['pin'];
        $signature     = $gateway_config['signature'];
        $dlt_entity_id = $gateway_config['dlt_entity_id'];

        if (! $gateway_url || ! $user_name || ! $pin || ! $signature || ! $dlt_entity_id) {
            Log::error('external_sms_config_missing');
            return [
                'status_code' => 500,
                'response' => 'SMS configuration missing',
            ];
        }

        $params = http_build_query([
            'username'        => $user_name,
            'pin'             => $pin,
            'message'         => $message,
            'mnumber'         => $mobile_no,
            'signature'       => $signature,
            'dlt_entity_id'   => $dlt_entity_id,
            'dlt_template_id' => $dlt_template_id,
        ]);


        $ch = curl_init($gateway_url . '?' . $params);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return [
                'status_code' => 500,
                'response' => 'cURL Error: ' . $error,
                'success' => false,
            ];
        }

        $decoded_response = json_decode($response, true);
        $final_response = $decoded_response ?: $response;

        return [
            'status_code' => $status,
            'response' => $final_response,
            'success' => $status >= 200 && $status < 300,
        ];
    }
}