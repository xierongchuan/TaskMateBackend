<?php

/**
 * Test script for recurring tasks logic
 * Run with: php experiments/test-recurring-logic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;

// Simulate the logic from ProcessRecurringTasks

function testDailyRecurrence()
{
    echo "\n=== Testing Daily Recurrence Logic ===\n";

    $now = Carbon::now('Asia/Yekaterinburg');
    echo "Current time: {$now->toDateTimeString()}\n";

    // Test case 1: Daily task at 09:00, current time is 10:00
    $task = (object)[
        'recurrence' => 'daily',
        'recurrence_time' => '09:00:00',
        'last_recurrence_at' => null,
    ];

    $recurrenceTime = Carbon::createFromFormat('H:i:s', $task->recurrence_time, 'Asia/Yekaterinburg');
    $targetTime = $now->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);

    echo "\nTest 1: Daily task at 09:00\n";
    echo "  Target time: {$targetTime->toDateTimeString()}\n";
    echo "  Should create: " . ($now->greaterThanOrEqualTo($targetTime) ? "YES" : "NO") . "\n";

    // Test case 2: Already processed today
    $task->last_recurrence_at = $now->copy()->subHours(2)->setTimezone('UTC');

    $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
    echo "\nTest 2: Already processed today\n";
    echo "  Last processed: {$lastProcessed->toDateTimeString()}\n";
    echo "  Is same day: " . ($lastProcessed->isSameDay($now) ? "YES" : "NO") . "\n";
    echo "  Should create: NO\n";

    // Test case 3: Processed yesterday
    $task->last_recurrence_at = $now->copy()->subDay()->setTimezone('UTC');

    $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
    echo "\nTest 3: Processed yesterday\n";
    echo "  Last processed: {$lastProcessed->toDateTimeString()}\n";
    echo "  Is same day: " . ($lastProcessed->isSameDay($now) ? "YES" : "NO") . "\n";
    echo "  Should create: YES\n";
}

function testWeeklyRecurrence()
{
    echo "\n\n=== Testing Weekly Recurrence Logic ===\n";

    $now = Carbon::now('Asia/Yekaterinburg');
    echo "Current time: {$now->toDateTimeString()}\n";
    echo "Current day of week: {$now->dayOfWeekIso} ({$now->format('l')})\n";

    // Test case 1: Weekly task on current day at 10:00
    $task = (object)[
        'recurrence' => 'weekly',
        'recurrence_day_of_week' => $now->dayOfWeekIso,
        'recurrence_time' => '10:00:00',
        'last_recurrence_at' => null,
    ];

    echo "\nTest 1: Weekly task on {$now->format('l')} at 10:00\n";
    echo "  Target day: {$task->recurrence_day_of_week}\n";
    echo "  Today is target day: " . ($now->dayOfWeekIso === $task->recurrence_day_of_week ? "YES" : "NO") . "\n";

    $recurrenceTime = Carbon::createFromFormat('H:i:s', $task->recurrence_time, 'Asia/Yekaterinburg');
    $targetTime = $now->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);
    echo "  Current time >= target time: " . ($now->greaterThanOrEqualTo($targetTime) ? "YES" : "NO") . "\n";

    // Test case 2: Weekly task on different day
    $differentDay = ($now->dayOfWeekIso % 7) + 1;
    $task->recurrence_day_of_week = $differentDay;

    echo "\nTest 2: Weekly task on different day (day {$differentDay})\n";
    echo "  Today is target day: " . ($now->dayOfWeekIso === $task->recurrence_day_of_week ? "YES" : "NO") . "\n";
    echo "  Should create: NO\n";

    // Test case 3: Already processed this week
    $task->recurrence_day_of_week = $now->dayOfWeekIso;
    $task->last_recurrence_at = $now->copy()->subDays(2)->setTimezone('UTC');

    $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
    echo "\nTest 3: Already processed this week\n";
    echo "  Last processed: {$lastProcessed->toDateTimeString()}\n";
    echo "  Is same week: " . ($lastProcessed->isSameWeek($now) ? "YES" : "NO") . "\n";
    echo "  Should create: NO\n";

    // Test case 4: Processed last week
    $task->last_recurrence_at = $now->copy()->subWeek()->setTimezone('UTC');

    $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
    echo "\nTest 4: Processed last week\n";
    echo "  Last processed: {$lastProcessed->toDateTimeString()}\n";
    echo "  Is same week: " . ($lastProcessed->isSameWeek($now) ? "YES" : "NO") . "\n";
    echo "  Should create: YES\n";
}

function testMonthlyRecurrence()
{
    echo "\n\n=== Testing Monthly Recurrence Logic ===\n";

    $now = Carbon::now('Asia/Yekaterinburg');
    echo "Current time: {$now->toDateTimeString()}\n";
    echo "Current day of month: {$now->day}\n";

    // Test case 1: Monthly task on current day
    $task = (object)[
        'recurrence' => 'monthly',
        'recurrence_day_of_month' => $now->day,
        'recurrence_time' => '14:00:00',
        'last_recurrence_at' => null,
    ];

    echo "\nTest 1: Monthly task on day {$now->day} at 14:00\n";
    echo "  Today is target day: " . ($now->day === $task->recurrence_day_of_month ? "YES" : "NO") . "\n";

    // Test case 2: First day of month (-1)
    $task->recurrence_day_of_month = -1;
    $targetDay = 1;

    echo "\nTest 2: Monthly task on first day of month (value: -1)\n";
    echo "  Interpreted as day: {$targetDay}\n";
    echo "  Today is target day: " . ($now->day === $targetDay ? "YES" : "NO") . "\n";

    // Test case 3: Last day of month (-2)
    $task->recurrence_day_of_month = -2;
    $lastDay = $now->copy()->endOfMonth()->day;

    echo "\nTest 3: Monthly task on last day of month (value: -2)\n";
    echo "  Last day of this month: {$lastDay}\n";
    echo "  Today is target day: " . ($now->day === $lastDay ? "YES" : "NO") . "\n";

    // Test case 4: Already processed this month
    $task->recurrence_day_of_month = $now->day;
    $task->last_recurrence_at = $now->copy()->subDays(5)->setTimezone('UTC');

    $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
    echo "\nTest 4: Already processed this month\n";
    echo "  Last processed: {$lastProcessed->toDateTimeString()}\n";
    echo "  Same month and year: " . (($lastProcessed->month === $now->month && $lastProcessed->year === $now->year) ? "YES" : "NO") . "\n";
    echo "  Should create: NO\n";

    // Test case 5: Processed last month
    $task->last_recurrence_at = $now->copy()->subMonth()->setTimezone('UTC');

    $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
    echo "\nTest 5: Processed last month\n";
    echo "  Last processed: {$lastProcessed->toDateTimeString()}\n";
    echo "  Same month and year: " . (($lastProcessed->month === $now->month && $lastProcessed->year === $now->year) ? "YES" : "NO") . "\n";
    echo "  Should create: YES\n";
}

function testWeekendCheck()
{
    echo "\n\n=== Testing Weekend Logic ===\n";

    $now = Carbon::now('Asia/Yekaterinburg');
    echo "Current time: {$now->toDateTimeString()}\n";
    echo "Current day of week: {$now->dayOfWeekIso} ({$now->format('l')})\n";

    // Default weekend: Saturday (6) and Sunday (7)
    $defaultWeekends = [6, 7];
    $isWeekend = in_array($now->dayOfWeekIso, $defaultWeekends, true);

    echo "\nDefault weekends: Saturday (6), Sunday (7)\n";
    echo "  Today is weekend: " . ($isWeekend ? "YES" : "NO") . "\n";
    echo "  Should process recurring task: " . ($isWeekend ? "NO" : "YES") . "\n";

    // Custom weekend: Sunday (7) and Monday (1)
    $customWeekends = [7, 1];
    $isWeekend = in_array($now->dayOfWeekIso, $customWeekends, true);

    echo "\nCustom weekends: Sunday (7), Monday (1)\n";
    echo "  Today is weekend: " . ($isWeekend ? "YES" : "NO") . "\n";
    echo "  Should process recurring task: " . ($isWeekend ? "NO" : "YES") . "\n";
}

function testTimezoneHandling()
{
    echo "\n\n=== Testing Timezone Handling ===\n";

    // Simulate storing time in UTC
    $userTime = Carbon::create(2025, 10, 28, 14, 30, 0, 'Asia/Yekaterinburg');
    echo "User input time (Asia/Yekaterinburg): {$userTime->toDateTimeString()}\n";

    $utcTime = $userTime->copy()->setTimezone('UTC');
    echo "Stored in database (UTC): {$utcTime->toDateTimeString()}\n";

    // Simulate retrieving and converting back
    $retrievedTime = $utcTime->copy()->setTimezone('Asia/Yekaterinburg');
    echo "Retrieved for display (Asia/Yekaterinburg): {$retrievedTime->toDateTimeString()}\n";

    echo "\nRoundtrip successful: " . ($userTime->eq($retrievedTime) ? "YES" : "NO") . "\n";
}

// Run all tests
testDailyRecurrence();
testWeeklyRecurrence();
testMonthlyRecurrence();
testWeekendCheck();
testTimezoneHandling();

echo "\n\n=== All Tests Complete ===\n";
