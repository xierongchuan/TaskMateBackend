<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TaskNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to check and notify about overdue tasks
 * Should be run periodically (e.g., every hour)
 */
class CheckOverdueTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    /**
     * Execute the job
     */
    public function handle(TaskNotificationService $taskService): void
    {
        try {
            $results = $taskService->notifyAboutOverdueTasks();

            Log::info("CheckOverdueTasksJob completed: {$results['tasks_processed']} tasks processed, {$results['notifications_sent']} notifications sent");
        } catch (\Throwable $e) {
            Log::error('CheckOverdueTasksJob failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
