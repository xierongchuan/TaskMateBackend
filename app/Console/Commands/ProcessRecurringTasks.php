<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Services\TaskNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process recurring tasks and create new instances based on recurrence settings
 */
class ProcessRecurringTasks extends Command
{
    protected $signature = 'tasks:process-recurring';
    protected $description = 'Process recurring tasks (daily, weekly, monthly) and create new instances';

    public function __construct(
        private readonly TaskNotificationService $notificationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Processing recurring tasks...');

        $now = Carbon::now('Asia/Yekaterinburg');
        $processedCount = 0;

        // Get all active tasks with recurrence
        $recurringTasks = Task::where('is_active', true)
            ->whereIn('recurrence', ['daily', 'weekly', 'monthly'])
            ->get();

        foreach ($recurringTasks as $task) {
            try {
                if ($this->shouldCreateNewInstance($task, $now)) {
                    $this->createTaskInstance($task, $now);
                    $processedCount++;
                    $this->info("Created new instance for task #{$task->id}: {$task->title}");
                }
            } catch (\Throwable $e) {
                $this->error("Error processing task #{$task->id}: " . $e->getMessage());
                Log::error("Error processing recurring task #{$task->id}: " . $e->getMessage(), [
                    'exception' => $e,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->info("Processed {$processedCount} recurring tasks.");
        return self::SUCCESS;
    }

    /**
     * Determine if a new instance should be created based on recurrence pattern
     */
    private function shouldCreateNewInstance(Task $task, Carbon $now): bool
    {
        // Check if this is a weekend for the dealership
        if ($this->isWeekendForDealership($task->dealership_id, $now)) {
            return false;
        }

        // Check if we already processed this task recently
        if ($task->last_recurrence_at) {
            $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');

            // Don't process same task twice on the same day
            if ($lastProcessed->isSameDay($now)) {
                return false;
            }
        }

        return match ($task->recurrence) {
            'daily' => $this->shouldCreateDailyInstance($task, $now),
            'weekly' => $this->shouldCreateWeeklyInstance($task, $now),
            'monthly' => $this->shouldCreateMonthlyInstance($task, $now),
            default => false,
        };
    }

    /**
     * Check if a daily recurring task should be created
     */
    private function shouldCreateDailyInstance(Task $task, Carbon $now): bool
    {
        // If recurrence_time is not set, skip
        if (!$task->recurrence_time) {
            return false;
        }

        // Parse the recurrence time (stored as HH:MM)
        $recurrenceTime = Carbon::createFromFormat('H:i:s', $task->recurrence_time, 'Asia/Yekaterinburg');
        $targetTime = $now->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);

        // Check if current time has passed the target time
        if ($now->lessThan($targetTime)) {
            return false;
        }

        // Check if we already created instance for today
        if ($task->last_recurrence_at) {
            $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
            if ($lastProcessed->isSameDay($now) && $lastProcessed->greaterThanOrEqualTo($targetTime)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a weekly recurring task should be created
     */
    private function shouldCreateWeeklyInstance(Task $task, Carbon $now): bool
    {
        // If recurrence_day_of_week is not set, skip
        if (!$task->recurrence_day_of_week) {
            return false;
        }

        // Check if today is the target day of week (1=Monday, 7=Sunday)
        if ($now->dayOfWeekIso !== $task->recurrence_day_of_week) {
            return false;
        }

        // If recurrence_time is set, check if time has arrived
        if ($task->recurrence_time) {
            $recurrenceTime = Carbon::createFromFormat('H:i:s', $task->recurrence_time, 'Asia/Yekaterinburg');
            $targetTime = $now->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);

            if ($now->lessThan($targetTime)) {
                return false;
            }
        }

        // Check if we already created instance this week
        if ($task->last_recurrence_at) {
            $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
            if ($lastProcessed->isSameWeek($now)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a monthly recurring task should be created
     */
    private function shouldCreateMonthlyInstance(Task $task, Carbon $now): bool
    {
        // If recurrence_day_of_month is not set, skip
        if (!$task->recurrence_day_of_month) {
            return false;
        }

        $targetDay = $task->recurrence_day_of_month;

        // Handle special values
        if ($targetDay === -1) {
            // First day of month
            $targetDay = 1;
        } elseif ($targetDay === -2) {
            // Last day of month
            $targetDay = $now->copy()->endOfMonth()->day;
        }

        // Check if today is the target day
        if ($now->day !== $targetDay) {
            return false;
        }

        // If recurrence_time is set, check if time has arrived
        if ($task->recurrence_time) {
            $recurrenceTime = Carbon::createFromFormat('H:i:s', $task->recurrence_time, 'Asia/Yekaterinburg');
            $targetTime = $now->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);

            if ($now->lessThan($targetTime)) {
                return false;
            }
        }

        // Check if we already created instance this month
        if ($task->last_recurrence_at) {
            $lastProcessed = $task->last_recurrence_at->copy()->setTimezone('Asia/Yekaterinburg');
            if ($lastProcessed->month === $now->month && $lastProcessed->year === $now->year) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the given date is a weekend for the dealership
     */
    private function isWeekendForDealership(?int $dealershipId, Carbon $date): bool
    {
        if (!$dealershipId) {
            return false;
        }

        // Try to get weekend settings from dealership settings
        $setting = \App\Models\Setting::where('dealership_id', $dealershipId)
            ->where('key', 'weekend_days')
            ->first();

        if (!$setting) {
            // Default: Saturday (6) and Sunday (7)
            $weekendDays = [6, 7];
        } else {
            $weekendDays = $setting->getTypedValue();
        }

        return in_array($date->dayOfWeekIso, $weekendDays, true);
    }

    /**
     * Create a new task instance from recurring template
     */
    private function createTaskInstance(Task $task, Carbon $now): void
    {
        // Get assigned users from original task
        $assignedUsers = $task->assignedUsers;

        if ($assignedUsers->isEmpty()) {
            Log::warning("Recurring task #{$task->id} has no assigned users, skipping notification.");
            return;
        }

        // Update the task's last_recurrence_at to track when we processed it
        $task->last_recurrence_at = $now->copy()->setTimezone('UTC');
        $task->save();

        // Send notifications to all assigned users
        foreach ($assignedUsers as $user) {
            $this->notificationService->notifyUser($user, $task);
        }

        Log::info("Created recurring task instance for task #{$task->id}", [
            'task_id' => $task->id,
            'recurrence' => $task->recurrence,
            'recurrence_time' => $task->recurrence_time,
            'recurrence_day_of_week' => $task->recurrence_day_of_week,
            'recurrence_day_of_month' => $task->recurrence_day_of_month,
            'assigned_users' => $assignedUsers->pluck('id')->toArray(),
            'processed_at' => $now->toDateTimeString(),
        ]);
    }
}
