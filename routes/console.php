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
