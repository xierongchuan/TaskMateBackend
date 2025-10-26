<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use App\Services\TaskNotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckUpcomingDeadlinesJob implements ShouldQueue
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
    public function handle(TaskNotificationService $taskNotificationService): void
    {
        $now = Carbon::now();

        Log::info('Checking for upcoming deadlines', ['time' => $now->format('Y-m-d H:i:s')]);

        // Check for deadlines in 1 hour
        $this->checkDeadlinesInRange($taskNotificationService, $now, 60, '1 час');

        // Check for deadlines in 2 hours
        $this->checkDeadlinesInRange($taskNotificationService, $now, 120, '2 часа');

        // Check for deadlines in 4 hours
        $this->checkDeadlinesInRange($taskNotificationService, $now, 240, '4 часа');

        Log::info('Upcoming deadlines check completed');
    }

    /**
     * Check deadlines in specific time range and send notifications
     */
    private function checkDeadlinesInRange(
        TaskNotificationService $taskNotificationService,
        Carbon $now,
        int $minutesFromNow,
        string $timeText
    ): void {
        $deadlineTime = $now->copy()->addMinutes($minutesFromNow);

        // Find tasks with deadlines approaching
        $upcomingTasks = Task::with(['assignments.user', 'responses'])
            ->where('is_active', true)
            ->whereNotNull('deadline')
            ->where('deadline', '>=', $deadlineTime->copy()->subMinutes(2)) // 2 minute window
            ->where('deadline', '<=', $deadlineTime->copy()->addMinutes(2))
            ->whereDoesntHave('responses', function ($query) {
                $query->whereIn('status', ['completed', 'acknowledged']);
            })
            ->get();

        foreach ($upcomingTasks as $task) {
            Log::info("Found upcoming deadline task", [
                'task_id' => $task->id,
                'title' => $task->title,
                'deadline' => $task->deadline->format('Y-m-d H:i:s'),
                'time_until' => $timeText
            ]);

            // Send notifications to assigned users
            foreach ($task->assignments as $assignment) {
                $user = $assignment->user;
                if ($user && $user->telegram_id) {
                    $this->sendUpcomingDeadlineNotification($taskNotificationService, $task, $user, $timeText);
                }
            }

            // Also notify managers about important upcoming deadlines
            if (in_array($minutesFromNow, [60, 120])) { // Only for 1h and 2h warnings
                $this->notifyManagersAboutUpcomingDeadline($task, $timeText);
            }
        }
    }

    /**
     * Send notification to user about upcoming deadline
     */
    private function sendUpcomingDeadlineNotification(
        TaskNotificationService $taskNotificationService,
        Task $task,
        User $user,
        string $timeText
    ): void {
        try {
            $message = "⏰ *НАПОМИНАНИЕ О ДЕДЛАЙНЕ*\n\n";
            $message .= "📌 {$task->title}\n";

            if ($task->description) {
                $message .= "📝 {$task->description}\n";
            }

            $message .= "⏰ Дедлайн через {$timeText}: " . $task->deadline_for_bot . "\n";
            $message .= "👤 Пожалуйста, выполните задачу вовремя!";

            $taskNotificationService->getBot()->sendMessage(
                chat_id: $user->telegram_id,
                text: $message,
                parse_mode: 'markdown'
            );

            Log::info("Upcoming deadline notification sent", [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'time_until' => $timeText
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to send upcoming deadline notification", [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify managers about upcoming important deadlines
     */
    private function notifyManagersAboutUpcomingDeadline(Task $task, string $timeText): void {
        try {
            // Get managers from the same dealership
            $managers = User::where('dealership_id', $task->dealership_id)
                ->whereIn('role', ['OWNER', 'MANAGER'])
                ->whereNotNull('telegram_id')
                ->get();

            if ($managers->isEmpty()) {
                return;
            }

            $message = "🔔 *НАПОМИНАНИЕ О ДЕДЛАЙНЕ ДЛЯ МЕНЕДЖЕРА*\n\n";
            $message .= "📌 Задача: {$task->title}\n";

            if ($task->description) {
                $message .= "📝 {$task->description}\n";
            }

            $message .= "⏰ Дедлайн через {$timeText}: " . $task->deadline_for_bot . "\n";

            // Show assigned users
            $assignedUsers = $task->assignments->map(function ($assignment) {
                return $assignment->user ? $assignment->user->full_name : 'Неизвестный';
            })->filter()->implode(', ');

            if ($assignedUsers) {
                $message .= "👤 Исполнители: {$assignedUsers}\n";
            }

            // Check if anyone has already responded
            $hasResponses = $task->responses->isNotEmpty();
            if ($hasResponses) {
                $message .= "📊 Есть ответы от исполнителей\n";
            } else {
                $message .= "⚠️ Пока нет ответов от исполнителей\n";
            }

            foreach ($managers as $manager) {
                try {
                    app(TaskNotificationService::class)->getBot()->sendMessage(
                        chat_id: $manager->telegram_id,
                        text: $message,
                        parse_mode: 'markdown'
                    );
                } catch (\Exception $e) {
                    Log::error("Failed to send manager upcoming deadline notification", [
                        'task_id' => $task->id,
                        'manager_id' => $manager->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error("Failed to process managers upcoming deadline notifications", [
                'task_id' => $task->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}