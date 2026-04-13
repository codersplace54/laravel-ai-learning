<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotification;
use App\Models\User;
use App\Models\WhatsappLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendHelplineUpdatedWhatsApp extends Command
{
    protected $signature = 'whatsapp:helpline-updated';
    protected $description = 'Send helpline updated WhatsApp message to all users (250/day)';

    const TEMPLATE = 'helpline_updated_v1';
    const HELPLINE = '7085534092'; 

    public function handle()
    {
        $users = User::whereDoesntHave('whatsappLogs', fn($q) => $q->where('template_name', self::TEMPLATE))
            ->limit(250)
            ->get(['id', 'mobile_no']);

        $count = $users->count();
        Log::channel('whatsapp')->info('helpline_updated_cron_started', ['count' => $count]);

        foreach ($users as $user) {
            SendWhatsAppNotification::dispatch(
                $user->mobile_no,
                self::TEMPLATE,
                ['helpline' => self::HELPLINE],
                'helpline_updated_' . $user->id
            );

            WhatsappLog::create([
                'user_id'       => $user->id,
                'template_name' => self::TEMPLATE,
            ]);
        }

        Log::channel('whatsapp')->info('helpline_updated_cron_completed', ['dispatched' => $count]);
        $this->info("Dispatched helpline updated notifications for {$count} users.");

        return Command::SUCCESS;
    }
}
