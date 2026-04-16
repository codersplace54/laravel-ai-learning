<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        Commands\PaymentStatusCron::class,
        Commands\SingleWindowReportCron::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('report:single-window-native')->hourly();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}