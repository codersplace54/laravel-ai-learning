<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotification;
use App\Models\User;
use App\Models\WhatsappLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendMaintenanceWhatsApp extends Command
{
    protected $signature = 'whatsapp:maintenance {start} {end}';
    protected $description = 'Send portal maintenance WhatsApp message to all users (250/day). Args: start="10 Jan 2025 10:00 PM" end="11 Jan 2025 6:00 AM"';

    const TEMPLATE = 'portal_maintenance_update_v1';

    public function handle()
    {
        $start = $this->argument('start');
        $end   = $this->argument('end');

        // $users = User::whereDoesntHave('whatsappLogs', fn($q) => $q->where('template_name', self::TEMPLATE))
        //     ->limit(250)
        //     ->get(['id', 'mobile_no']);

        $users = User::where('user_name',"Mandeep")->get();

        $count = $users->count();
        Log::channel('whatsapp')->info('maintenance_cron_started', ['count' => $count, 'start' => $start, 'end' => $end]);

        foreach ($users as $user) {
            SendWhatsAppNotification::dispatch(
                $user->mobile_no,
                self::TEMPLATE,
                ['start' => $start, 'end' => $end],
                'maintenance_' . $user->id
            );

            WhatsappLog::create([
                'user_id'       => $user->id,
                'template_name' => self::TEMPLATE,
            ]);
        }

        Log::channel('whatsapp')->info('maintenance_cron_completed', ['dispatched' => $count]);
        $this->info("Dispatched maintenance notifications for {$count} users.");

        return Command::SUCCESS;
    }
}
