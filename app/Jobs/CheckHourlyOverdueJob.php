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
 * Job to check for tasks overdue by 1 hour and send urgent notifications
 */
class CheckHourlyOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(TaskNotificationService $notificationService): void
    {
        Log::info("Checking for hourly overdue tasks", ['time' => now()->format('Y-m-d H:i:s')]);

        $result = $notificationService->notifyAboutHourlyOverdueTasks();

        Log::info("Hourly overdue tasks check completed", [
            'tasks_processed' => $result['tasks_processed'],
            'notifications_sent' => $result['notifications_sent']
        ]);
    }
}