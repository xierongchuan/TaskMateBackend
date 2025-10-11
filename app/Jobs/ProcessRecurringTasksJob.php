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
use Illuminate\Support\Facades\Log;

/**
 * Job to process recurring tasks (daily, weekly, monthly)
 */
class ProcessRecurringTasksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $now = Carbon::now();

            // Get all active recurring tasks
            $recurringTasks = Task::where('is_active', true)
                ->whereNotNull('recurrence')
                ->whereIn('recurrence', ['daily', 'weekly', 'monthly'])
                ->get();

            foreach ($recurringTasks as $task) {
                if ($this->shouldCreateNewInstance($task, $now)) {
                    $this->createTaskInstance($task, $now);
                }
            }

            Log::info('ProcessRecurringTasksJob completed', [
                'tasks_processed' => $recurringTasks->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessRecurringTasksJob failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Check if a new instance of the task should be created
     */
    private function shouldCreateNewInstance(Task $task, Carbon $now): bool
    {
        // Check if there's already an active instance for today
        $existingInstance = Task::where('title', $task->title)
            ->where('dealership_id', $task->dealership_id)
            ->where('appear_date', '>=', $now->copy()->startOfDay())
            ->where('appear_date', '<=', $now->copy()->endOfDay())
            ->where('id', '!=', $task->id)
            ->exists();

        if ($existingInstance) {
            return false;
        }

        // Check recurrence pattern
        return match ($task->recurrence) {
            'daily' => true, // Always create for daily tasks
            'weekly' => $now->dayOfWeek === $task->created_at->dayOfWeek,
            'monthly' => $now->day === $task->created_at->day,
            default => false,
        };
    }

    /**
     * Create a new instance of the recurring task
     */
    private function createTaskInstance(Task $originalTask, Carbon $now): void
    {
        try {
            // Create new task instance
            $newTask = Task::create([
                'title' => $originalTask->title,
                'description' => $originalTask->description,
                'comment' => $originalTask->comment,
                'creator_id' => $originalTask->creator_id,
                'dealership_id' => $originalTask->dealership_id,
                'appear_date' => $now,
                'deadline' => $originalTask->deadline ? $now->copy()->setTimeFrom($originalTask->deadline) : null,
                'recurrence' => null, // Instance tasks are not recurring
                'task_type' => $originalTask->task_type,
                'response_type' => $originalTask->response_type,
                'tags' => $originalTask->tags,
                'is_active' => true,
            ]);

            // Copy assignments from original task
            $assignments = TaskAssignment::where('task_id', $originalTask->id)->get();
            foreach ($assignments as $assignment) {
                TaskAssignment::create([
                    'task_id' => $newTask->id,
                    'user_id' => $assignment->user_id,
                ]);
            }

            Log::info('Created recurring task instance', [
                'original_task_id' => $originalTask->id,
                'new_task_id' => $newTask->id,
                'recurrence' => $originalTask->recurrence,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create recurring task instance: ' . $e->getMessage(), [
                'task_id' => $originalTask->id,
                'exception' => $e,
            ]);
        }
    }
}
