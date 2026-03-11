<?php

use App\Jobs\CheckSlaBreaches;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check for SLA breaches every 15 minutes
Schedule::job(new CheckSlaBreaches)->everyFifteenMinutes();

// Expire ETA applications daily at 2:00 AM
Schedule::command('eta:expire')->dailyAt('02:00');

// Process alert rules every 5 minutes
Schedule::command('alerts:process')->everyFiveMinutes();
