<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function sendTemplate(string $phone, string $template_name, array $parameters = [], ?string $opaque = null): array
    {
        $token = config('whatsapp.bearer_token');

        $base = rtrim(config('whatsapp.base_url'), '/');
        $ver  = trim(config('whatsapp.version'));
        $pid  = config('whatsapp.phone_number_id');
        $url  = config('whatsapp.api_url') ?: "{$base}/{$ver}/{$pid}/messages";

        if (!$url || !$token) {
            Log::error('whatsapp_config_missing');
            return ['success' => false, 'status_code' => 500, 'response' => 'WhatsApp configuration missing'];
        }
        
        $to = $phone;

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template_name,
                'language' => ['code' => 'en'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => array_map(fn($text) => [
                            'type' => 'text',
                            'text' => (string) $text,
                        ], $parameters),
                    ],
                ],
            ],
        ];

        if ($opaque) {
            $payload['biz_opaque_callback_data'] = $opaque; // e.g. application_id=123
        }

        Log::info('WHATSAPP_REQUEST', [
            'to' => $to,
            'template' => $template_name,
            'param_count' => count($parameters),
        ]);

        try {
            $res = Http::withToken($token)
                ->acceptJson()
                ->contentType('application/json')
                ->timeout(20)
                ->retry(2, 300, throw: false) // retry 2 times
                ->post($url, $payload);

            $body = $res->json() ?? $res->body();

            Log::info('WHATSAPP_RESPONSE', [
                'to' => $to,
                'status_code' => $res->status(),
                'body' => $res->body(),
            ]);


            return [
                'success' => $res->successful(),
                'status_code' => $res->status(),
                'response' => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('whatsapp_error', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status_code' => 500,
                'response' => $e->getMessage(),
            ];
        }
    }
}
