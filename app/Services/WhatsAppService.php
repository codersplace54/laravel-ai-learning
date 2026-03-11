<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function sendTemplate(string $phone, string $template_name, array $parameters = [], ?string $opaque = null): array
    {
        $token = config('whatsapp.bearer_token');
        $url = config('whatsapp.api_url');

        if (!$url || !$token) {
            Log::channel('whatsapp')->error('whatsapp_config_missing');
            return ['success' => false, 'status_code' => 500, 'response' => 'WhatsApp configuration missing'];
        }

        $to = $phone;

        $components = $this->build_components($parameters);

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

        // Log::channel('whatsapp')->info('WHATSAPP_REQUEST', [
        //     'to' => $to,
        //     'template' => $template_name,
        //     'payload' => $payload,
        // ]);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->contentType('application/json')
                ->timeout(20)
                ->retry(2, 300, throw: false)
                ->post($url, $payload);

            Log::channel('whatsapp')->info('WHATSAPP_RESPONSE', [
                'to' => $to,
                'status_code' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'response' => $response->json() ?? $response->body(),
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

    private function build_components(array $parameters): array
    {
        $components = [];
        $body_params = [];
        $header_param = null;

        foreach ($parameters as $key => $value) {
            if ($key === 'document_url') {
                $filename = $parameters['filename'] ;
                $media_id = $this->upload_media_from_url((string) $value, $filename);

                $header_param = [
                    'type' => 'header',
                    'parameters' => [[
                        'type' => 'document',
                        'document' => $media_id
                            ? ['id' => $media_id, 'filename' => $filename]
                            : ['link' => (string) $value, 'filename' => $filename],
                    ]]
                ];
            } elseif ($key === 'url') {
                $header_param = [
                    'type' => 'button',
                    'parameters' => [['type' => 'text', 'text' => (string) $value]],
                    'sub_type' => 'url',
                    'index' => '0'
                ];
            } elseif ($key !== 'filename') {
                $body_params[] = ['type' => 'text', 'text' => (string) $value];
            }
        }

        if ($header_param) {
            $components[] = $header_param;
        }

        if (!empty($body_params)) {
            $components[] = ['type' => 'body', 'parameters' => $body_params];
        }

        return $components;
    }

    private function upload_media_from_url(string $file_url, string $filename = 'certificate.pdf'): ?string
    {
        $token = config('whatsapp.bearer_token');
        $messages_url = config('whatsapp.api_url');
        $media_url = rtrim(preg_replace('#/messages$#', '', $messages_url), '/') . '/media';

        $tmp = tempnam(sys_get_temp_dir(), 'wa_') . '.pdf';

        try {
            $download = Http::timeout(60)
                ->withOptions(['sink' => $tmp, 'allow_redirects' => true])
                ->get($file_url);

            if (!$download->successful() || !file_exists($tmp) || filesize($tmp) === 0) {
                Log::channel('whatsapp')->error('WA_REMOTE_PDF_DOWNLOAD_FAILED', [
                    'url' => $file_url,
                    'status' => $download->status(),
                ]);
                return null;
            }

            $upload = Http::withToken($token)
                ->timeout(60)
                ->attach('file', fopen($tmp, 'r'), $filename)
                ->post($media_url, [
                    'messaging_product' => 'whatsapp',
                    'type' => 'application/pdf',
                ]);

            // Log::channel('whatsapp')->info('WA_MEDIA_UPLOAD', [
            //     'status' => $upload->status(),
            //     'body' => $upload->body(),
            // ]);

            return $upload->successful() ? ($upload->json('id') ?? null) : null;
        } finally {
            @unlink($tmp);
        }
    }
}
