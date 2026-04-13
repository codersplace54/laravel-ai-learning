<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:run --only-db')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('saral:sync')
    ->dailyAt('23:00');

Schedule::command('deemed:approve')
    ->dailyAt('00:30');

Schedule::command('whatsapp:migration-complete-profile')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Schedule::command('whatsapp:helpline-updated')
//     ->dailyAt('08:30')
//     ->withoutOverlapping();
