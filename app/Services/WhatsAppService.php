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
            Log::channel('whatsapp')->error('whatsapp_config_missing');
            return ['success' => false, 'status_code' => 500, 'response' => 'WhatsApp configuration missing'];
        }
        
        $to = $phone;

        $components = [];
        $body_params = [];
        $header_param = null;

        foreach ($parameters as $key => $value) {
            if ($key === 'document_url') {
                $header_param = [
                    'type' => 'header',
                    'parameters' => [
                        [
                            'type' => 'document',
                            'document' => [
                                'link' => (string) $value,
                                'filename' => $parameters['filename'] ?? 'certificate.pdf'
                            ]
                        ]
                    ]
                ];
            } elseif ($key !== 'filename') {
                $body_params[] = [
                    'type' => 'text',
                    'text' => (string) $value,
                ];
            }
        }

        if ($header_param) {
            $components[] = $header_param;
        }

        if (!empty($body_params)) {
            $components[] = [
                'type' => 'body',
                'parameters' => $body_params,
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template_name,
                'language' => ['code' => 'en'],
                'components' => $components,
            ],
        ];

        if ($opaque) {
            $payload['biz_opaque_callback_data'] = $opaque;
        }

        // Log::info('WHATSAPP_REQUEST', [
        //     'to' => $to,
        //     'template' => $template_name,
        //     'param_count' => count($parameters),
        //     'payload' => $payload,
        // ]);

        try {
            $res = Http::withToken($token)
                ->acceptJson()
                ->contentType('application/json')
                ->timeout(20)
                ->retry(2, 300, throw: false) // retry 2 times
                ->post($url, $payload);

            $body = $res->json() ?? $res->body();

            Log::channel('whatsapp')->info('WHATSAPP_RESPONSE', [
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
            Log::channel('whatsapp')->error('whatsapp_error', [
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
