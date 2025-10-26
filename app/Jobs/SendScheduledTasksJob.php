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
            // Get tasks that should appear today but haven't been sent yet
            $tasks = Task::where('is_active', true)
                ->whereNotNull('appear_date')
                ->whereDate('appear_date', Carbon::today())
                ->where(function ($query) {
                    // Only tasks that haven't been sent or completed
                    $query->whereDoesntHave('responses')
                        ->orWhereHas('responses', function ($q) {
                            $q->whereIn('status', ['postponed']);
                        });
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
