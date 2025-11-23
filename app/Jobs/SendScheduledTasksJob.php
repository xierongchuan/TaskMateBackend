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
            $now = Carbon::now('UTC');

            Log::info('SendScheduledTasksJob started', [
                'current_time_utc' => $now->format('Y-m-d H:i:s'),
                'current_time_user_tz' => $now->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d H:i:s')
            ]);

            // Get tasks that appeared in the last 6 minutes (widened window to account for job delays)
            $tasks = Task::where('is_active', true)
                ->whereNotNull('appear_date')
                ->where('appear_date', '>=', $now->copy()->subMinutes(6))
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

                Log::info('Task processing result', [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'appear_date' => $task->appear_date_api,
                    'success' => $results['success'],
                    'failed' => $results['failed']
                ]);
            }

            Log::info('SendScheduledTasksJob completed', [
                'tasks_found' => $tasks->count(),
                'notifications_sent' => $sentCount,
                'notifications_failed' => $failedCount
            ]);
        } catch (\Throwable $e) {
            Log::error('SendScheduledTasksJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
