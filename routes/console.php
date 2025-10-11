<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled tasks for shift and task management

// Process recurring tasks (daily, weekly, monthly) - runs every hour
Schedule::job(new \App\Jobs\ProcessRecurringTasksJob())->hourly();

// Send scheduled tasks based on appear_date - runs every 15 minutes
Schedule::job(new \App\Jobs\SendScheduledTasksJob())->everyFifteenMinutes();

// Check for overdue tasks and notify managers - runs every 30 minutes
Schedule::job(new \App\Jobs\CheckOverdueTasksJob())->everyThirtyMinutes();

// Archive old completed tasks - runs daily at 2:00 AM
Schedule::job(new \App\Jobs\ArchiveOldTasksJob())->dailyAt('02:00');

// Send daily summary to managers - runs daily at end of business day (20:00)
Schedule::job(new \App\Jobs\SendDailySummaryJob())->dailyAt('20:00');

// Send weekly reports - runs every Monday at 9:00 AM
Schedule::job(new \App\Jobs\SendWeeklyReportJob())->weekly()->mondays()->at('09:00');
