<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Services\TaskNotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to send scheduled tasks to employees
 * Should be run periodically (e.g., every hour or daily)
 */
class SendScheduledTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job
     */
    public function handle(TaskNotificationService $taskService): void
    {
        try {
            // Get tasks that appeared in the last 5 minutes (time window approach)
            $now = Carbon::now('UTC');
            $tasks = Task::where('is_active', true)
                ->whereNotNull('appear_date')
                ->where('appear_date', '>=', $now->copy()->subMinutes(5))
                ->where('appear_date', '<=', $now)
                ->whereDoesntHave('responses', function ($query) {
                    // Only tasks without completed responses
                    $query->where('status', 'completed');
                })
                ->with('assignedUsers')
                ->get();

            $sentCount = 0;
            $failedCount = 0;

            foreach ($tasks as $task) {
                $results = $taskService->sendTaskToAssignedUsers($task);
                $sentCount += $results['success'];
                $failedCount += $results['failed'];

                Log::info("Task #{$task->id} sent: {$results['success']} success, {$results['failed']} failed");
            }

            Log::info("SendScheduledTasksJob completed: {$sentCount} sent, {$failedCount} failed");
        } catch (\Throwable $e) {
            Log::error('SendScheduledTasksJob failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
