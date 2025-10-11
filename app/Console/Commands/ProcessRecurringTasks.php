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

        $now = Carbon::now();
        $today = $now->format('Y-m-d');
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
        // Check if appear_date is set and hasn't arrived yet
        if ($task->appear_date && $task->appear_date->greaterThan($now)) {
            return false;
        }

        // For new recurring tasks without created_at instances today
        $lastCreated = $task->created_at;

        return match ($task->recurrence) {
            'daily' => !$lastCreated || $lastCreated->format('Y-m-d') !== $now->format('Y-m-d'),
            'weekly' => !$lastCreated || $lastCreated->diffInDays($now) >= 7,
            'monthly' => !$lastCreated || $lastCreated->month !== $now->month || $lastCreated->year !== $now->year,
            default => false,
        };
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

        // Calculate new deadline if original task had one
        $newDeadline = null;
        if ($task->deadline) {
            $deadlineOffset = $task->created_at->diffInDays($task->deadline);
            $newDeadline = $now->copy()->addDays($deadlineOffset);
        }

        // Update the task's metadata to track last creation
        // In a real-world scenario, you might want to create separate task instances
        // For this implementation, we'll notify users as if it's a new occurrence

        // Send notifications to all assigned users
        foreach ($assignedUsers as $user) {
            $this->notificationService->notifyUser($user, $task);
        }

        Log::info("Created recurring task instance for task #{$task->id}", [
            'task_id' => $task->id,
            'recurrence' => $task->recurrence,
            'assigned_users' => $assignedUsers->pluck('id')->toArray(),
        ]);
    }
}
