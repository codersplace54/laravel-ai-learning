<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{

    public static function send(string $mobile_no, string $message, string $dlt_template_id): array
    {
            $gateway_url   = config('sms.gateway_url');
            $user_name     = config('sms.username');
            $pin           = config('sms.pin');
            $signature     = config('sms.signature');
            $dlt_entity_id = config('sms.dlt_entity_id');

        if (! $gateway_url || ! $user_name || ! $pin || ! $signature || ! $dlt_entity_id) {
            Log::channel('sms')->error('sms_config_missing');
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

        // Log::info('SMS_REQUEST', [
        //     'mobile' => $mobile_no,
        //     'message' => $message,
        //     'template_id' => $dlt_template_id,
        //     'gateway_url' => $gateway_url,
        // ]);

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

        // Log::info('SMS_GATEWAY_RESPONSE', [
        //     'status_code' => $status,
        //     'response' => $response,
        // ]);

        if ($error) {
            Log::channel('sms')->error('sms_error', ['error' => $error, 'mobile' => $mobile_no]);
        }

        return [
            'status_code' => $status,
            'response'    => $response,
        ];
    }

    public static function buildSmsMessage(string $key, array $data): array
    {
        $template = config("sms_templates.$key");

        $message = $template['message'];
        foreach ($data as $k => $v) {
            $message = str_replace('{' . $k . '}', $v, $message);
        }

        return [
            'message' => $message,
            'template_id' => $template['template_id'],
        ];
    }
}
