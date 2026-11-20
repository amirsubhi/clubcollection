<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run daily at 08:00 in the app time zone (APP_TIMEZONE; 8 am Malaysia time with
// Asia/Kuala_Lumpur): mark pending payments as overdue and send reminder emails
Schedule::command('payments:send-overdue-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// G1: settle FPX payments whose gateway callback was lost. Asks ToyyibPay
// about open bills opened 10 minutes to 3 days ago (Billplz: callback only).
// Needs the cron line from docs/INSTALL.md (schedule:run every minute).
Schedule::command('payments:reconcile-open-bills')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// G2: proof for `php artisan app:doctor` that the cron job runs the scheduler
// (it fails when this timestamp is over 10 minutes old).
Schedule::call(fn () => \App\Support\SchedulerHeartbeat::beat())
    ->everyMinute()
    ->name('scheduler-heartbeat');

// G2: daily backup of the database and uploaded files to storage/app/backups
// (keeps the last 14). Download them off the server; .env / APP_KEY are not in them.
Schedule::command('backup:run')
    ->dailyAt('02:15')
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping(120);
