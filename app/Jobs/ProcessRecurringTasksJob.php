<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskAssignment;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRecurringTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $now = Carbon::now('Asia/Yekaterinburg');
        Log::info('ProcessRecurringTasksJob started', ['time' => $now->format('Y-m-d H:i:s')]);

        $tasks = Task::where('is_active', true)
            ->where('recurrence', '!=', 'none')
            ->whereNull('archived_at')
            ->get();

        foreach ($tasks as $task) {
            try {
                if ($this->shouldRunTask($task, $now)) {
                    $this->createRecurringInstance($task, $now);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to process recurring task', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        Log::info('ProcessRecurringTasksJob completed');
    }

    private function shouldRunTask(Task $task, Carbon $now): bool
    {
        // Check if already run today
        if ($task->last_recurrence_at) {
            $lastRun = $task->last_recurrence_at->setTimezone('Asia/Yekaterinburg');
            if ($lastRun->isSameDay($now)) {
                return false;
            }
        }

        // Parse recurrence time
        if (!$task->recurrence_time) {
            return false;
        }

        $recurrenceTime = Carbon::createFromFormat('H:i', $task->recurrence_time, 'Asia/Yekaterinburg');
        // Set date to today
        $scheduledTime = $now->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);

        // If current time is before scheduled time, don't run yet
        if ($now->lessThan($scheduledTime)) {
            return false;
        }

        return match ($task->recurrence) {
            'daily' => true,
            'weekly' => $now->dayOfWeekIso === $task->recurrence_day_of_week,
            'monthly' => $this->isMonthlyRunDay($task, $now),
            default => false,
        };
    }

    private function isMonthlyRunDay(Task $task, Carbon $now): bool
    {
        $targetDay = $task->recurrence_day_of_month;

        if ($targetDay > 0) {
            return $now->day === $targetDay;
        }

        // Handle negative days (e.g. -1 is last day of month)
        $daysInMonth = $now->daysInMonth;
        $calculatedDay = $daysInMonth + $targetDay + 1;

        return $now->day === $calculatedDay;
    }

    private function createRecurringInstance(Task $task, Carbon $now): void
    {
        DB::transaction(function () use ($task, $now) {
            // Calculate new times
            $recurrenceTime = Carbon::createFromFormat('H:i', $task->recurrence_time, 'Asia/Yekaterinburg');
            $appearDate = $now->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);

            // Calculate duration to set deadline
            $deadline = null;
            if ($task->appear_date && $task->deadline) {
                $originalAppear = $task->appear_date->setTimezone('Asia/Yekaterinburg');
                $originalDeadline = $task->deadline->setTimezone('Asia/Yekaterinburg');
                $durationMinutes = $originalAppear->diffInMinutes($originalDeadline, false);

                if ($durationMinutes > 0) {
                    $deadline = $appearDate->copy()->addMinutes($durationMinutes);
                }
            }

            // Create new task
            $newTask = Task::create([
                'title' => $task->title,
                'description' => $task->description,
                'comment' => $task->comment,
                'creator_id' => $task->creator_id,
                'dealership_id' => $task->dealership_id,
                'appear_date' => $appearDate->setTimezone('UTC'), // Model setter will handle this, but passing UTC just in case or string
                // Actually model setter expects string in Yekaterinburg or Carbon object.
                // Let's pass the Carbon object, the setter handles conversion if it's a string,
                // but if we pass Carbon, we should ensure it's handled correctly.
                // Looking at Model: setAppearDateAttribute checks if value exists.
                // If we pass Carbon, it might be treated as is?
                // The model casts 'appear_date' => 'datetime'.
                // Let's pass the formatted string to be safe and leverage the setter logic if possible,
                // OR just pass the UTC Carbon object if we bypass the setter logic (which we don't if we use create).
                // The setter logic: $userTime = Carbon::parse($value, 'Asia/Yekaterinburg');
                // So we should pass the Yekaterinburg time string.
                'deadline' => $deadline ? $deadline->format('Y-m-d H:i:s') : null,
                'recurrence' => 'none', // The instance itself is not recurring
                'task_type' => $task->task_type,
                'response_type' => $task->response_type,
                'tags' => $task->tags,
                'is_active' => true,
            ]);

            // We need to override the appear_date because the setter expects Yekaterinburg string
            // but we want to be precise.
            // Let's update it directly to be sure.
            $newTask->appear_date = $appearDate->setTimezone('UTC');
            if ($deadline) {
                $newTask->deadline = $deadline->setTimezone('UTC');
            }
            $newTask->save();

            // Copy assignments
            $assignments = $task->assignments;
            foreach ($assignments as $assignment) {
                TaskAssignment::create([
                    'task_id' => $newTask->id,
                    'user_id' => $assignment->user_id,
                ]);
            }

            // Update last recurrence on original task
            $task->update([
                'last_recurrence_at' => $now->setTimezone('UTC')
            ]);

            Log::info('Created recurring task instance', [
                'original_id' => $task->id,
                'new_id' => $newTask->id,
                'title' => $newTask->title
            ]);
        });
    }
}
