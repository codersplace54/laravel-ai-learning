<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotification;
use App\Models\User;
use App\Models\WhatsappLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendMigrationCompleteProfileWhatsApp extends Command
{
    protected $signature = 'whatsapp:migration-complete-profile';
    protected $description = 'Send WhatsApp message to migrated users with incomplete profiles (250/day)';

    const TEMPLATE = 'swaagat2_migration_complete_profile_v1';

    public function handle()
    {
        $users = User::whereNotNull('old_id')
            ->where('password_reset_required', 1)
            ->whereDoesntHave('whatsappLogs', function ($q) {
                $q->where('template_name', self::TEMPLATE);
            })
            ->limit(250)
            ->get(['id', 'mobile_no']);

        $count = $users->count();
        Log::channel('whatsapp')->info('migration_complete_profile_cron_started', ['count' => $count]);

        foreach ($users as $user) {
            SendWhatsAppNotification::dispatch(
                $user->mobile_no,
                self::TEMPLATE,
                [],
                'migration_complete_profile_' . $user->id
            );

            WhatsappLog::create([
                'user_id'       => $user->id,
                'template_name' => self::TEMPLATE,
            ]);
        }

        Log::channel('whatsapp')->info('migration_complete_profile_cron_completed', ['dispatched' => $count]);
        $this->info("Dispatched WhatsApp notifications for {$count} users.");

        return Command::SUCCESS;
    }
}
