<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use App\Traits\LogsActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsActivity;

    public $phone_number;
    public $template_name;
    public $parameters;
    public $opaque_data;

    public function __construct(string $phone_number, string $template_name, array $parameters = [], ?string $opaque_data = null)
    {
        $this->phone_number = $phone_number;
        $this->template_name = $template_name;
        $this->parameters = $parameters;
        $this->opaque_data = $opaque_data;
    }

    public function handle(WhatsAppService $whatsapp_service): void
    {
        try {
            $result = $whatsapp_service->sendTemplate(
                $this->phone_number,
                $this->template_name,
                $this->parameters,
                $this->opaque_data
            );

            if (!$result['success']) {
                $this->logActivity('WhatsApp notification failed', null, null, [
                    'phone' => $this->phone_number,
                    'template' => $this->template_name,
                    'status_code' => $result['status_code'],
                    'error' => $result['response']
                ], 'WhatsApp Failed');
            }
        } catch (\Exception $e) {
            Log::error('whatsapp_job_failed', [
                'phone' => $this->phone_number,
                'template' => $this->template_name,
                'error' => $e->getMessage()
            ]);

            $this->logActivity('WhatsApp notification exception', null, null, [
                'phone' => $this->phone_number,
                'template' => $this->template_name,
                'error' => $e->getMessage()
            ], 'WhatsApp Exception');
        }
    }
}
