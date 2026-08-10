<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('summary:daily')->dailyAt('21:00')->timezone('Asia/Kolkata');
Schedule::command('summary:weekly')->weeklyOn(0, '21:30')->timezone('Asia/Kolkata'); // Sunday