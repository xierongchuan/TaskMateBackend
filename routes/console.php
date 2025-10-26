<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Command to test workers manually
Artisan::command('workers:test {type=all}', function ($type) {
    $this->info("Testing worker: {$type}");
    $this->info("Current time: " . now()->format('Y-m-d H:i:s T'));
    $this->info('Use "php artisan queue:work --queue=notifications" to process the jobs');
})->purpose('Test notification workers manually');

// Scheduled tasks for shift and task management

// Process recurring tasks functionality removed - tasks should only be created via API

// Send scheduled tasks based on appear_date - runs every 5 minutes for immediate delivery
Schedule::job(new \App\Jobs\SendScheduledTasksJob())->everyFiveMinutes();

// Check for overdue tasks and notify managers - runs every 10 minutes for timely notifications
Schedule::job(new \App\Jobs\CheckOverdueTasksJob())->everyTenMinutes();

// Check for upcoming deadlines (1 hour, 2 hours before) - runs every 15 minutes
Schedule::job(new \App\Jobs\CheckUpcomingDeadlinesJob())->everyFifteenMinutes();

// Check for tasks without response - runs every 30 minutes
Schedule::job(new \App\Jobs\CheckUnrespondedTasksJob())->everyThirtyMinutes();

// Archive old completed tasks - runs daily at 2:00 AM
Schedule::job(new \App\Jobs\ArchiveOldTasksJob())->dailyAt('02:00');

// Send daily summary to managers - runs daily at end of business day (20:00)
Schedule::job(new \App\Jobs\SendDailySummaryJob())->dailyAt('20:00');

// Send weekly reports - runs every Monday at 9:00 AM
Schedule::job(new \App\Jobs\SendWeeklyReportJob())->weekly()->mondays()->at('09:00');
