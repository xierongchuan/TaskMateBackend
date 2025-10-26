<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskNotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckUnrespondedTasksJob implements ShouldQueue
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
        $now = Carbon::now('Asia/Yekaterinburg');

        Log::info('Checking for unresponded tasks', ['time' => $now->format('Y-m-d H:i:s')]);

        // Check for tasks that were sent more than 2 hours ago but have no response
        $this->checkTasksWithoutResponse($taskNotificationService, $now, 120, '2 часа');

        // Check for tasks that were sent more than 6 hours ago but have no response
        $this->checkTasksWithoutResponse($taskNotificationService, $now, 360, '6 часов');

        // Check for tasks that were sent more than 24 hours ago but have no response
        $this->checkTasksWithoutResponse($taskNotificationService, $now, 1440, '24 часа');

        Log::info('Unresponded tasks check completed');
    }

    /**
     * Check tasks without response in specific time range
     */
    private function checkTasksWithoutResponse(
        TaskNotificationService $taskNotificationService,
        Carbon $now,
        int $minutesAgo,
        string $timeText
    ): void {
        $checkTime = $now->copy()->subMinutes($minutesAgo);

        // Find tasks that should have appeared before this time and have no completed responses
        $unrespondedTasks = Task::with(['assignments.user', 'responses'])
            ->where('is_active', true)
            ->where('appear_date', '<=', $checkTime)
            ->whereDoesntHave('responses', function ($query) {
                $query->where('status', 'completed');
            })
            ->where(function ($query) {
                // Only tasks that have been assigned to users
                $query->whereHas('assignments');
            })
            ->get();

        foreach ($unrespondedTasks as $task) {
            Log::info("Found unresponded task", [
                'task_id' => $task->id,
                'title' => $task->title,
                'appear_date' => $task->appear_date?->format('Y-m-d H:i:s'),
                'time_elapsed' => $timeText
            ]);

            // Send reminders to assigned users
            foreach ($task->assignments as $assignment) {
                $user = $assignment->user;
                if ($user && $user->telegram_id) {
                    $this->sendUnrespondedTaskReminder($taskNotificationService, $task, $user, $timeText);
                }
            }

            // Notify managers about tasks without response for too long
            if (in_array($minutesAgo, [360, 1440])) { // 6 hours and 24 hours
                $this->notifyManagersAboutUnrespondedTask($task, $timeText);
            }
        }
    }

    /**
     * Send reminder to user about unresponded task
     */
    private function sendUnrespondedTaskReminder(
        TaskNotificationService $taskNotificationService,
        Task $task,
        User $user,
        string $timeText
    ): void {
        try {
            $message = "📋 *НАПОМИНАНИЕ О ЗАДАЧЕ*\n\n";
            $message .= "📌 {$task->title}\n";

            if ($task->description) {
                $message .= "📝 {$task->description}\n";
            }

            if ($task->deadline) {
                $message .= "⏰ Дедлайн: " . $task->deadline_for_bot . "\n";
            }

            $message .= "⚠️ Задача отправлена {$timeText} назад, но нет ответа\n";
            $message .= "👤 Пожалуйста, ознакомьтесь с задачей и примите решение!";

            // Add appropriate keyboard based on response type
            $keyboard = null;
            if ($task->response_type === 'notification') {
                $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                    ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '✅ OK',
                        callback_data: 'task_ok_' . $task->id
                    ));
            } elseif ($task->response_type === 'execution') {
                $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                    ->addRow(
                        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                            text: '✅ Выполнено',
                            callback_data: 'task_done_' . $task->id
                        ),
                        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                            text: '⏭️ Перенести',
                            callback_data: 'task_postpone_' . $task->id
                        )
                    );
            }

            $taskNotificationService->getBot()->sendMessage(
                chat_id: $user->telegram_id,
                text: $message,
                parse_mode: 'markdown',
                reply_markup: $keyboard
            );

            Log::info("Unresponded task reminder sent", [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'time_elapsed' => $timeText
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to send unresponded task reminder", [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify managers about tasks without response for too long
     */
    private function notifyManagersAboutUnrespondedTask(Task $task, string $timeText): void {
        try {
            // Get managers from the same dealership
            $managers = User::where('dealership_id', $task->dealership_id)
                ->whereIn('role', ['OWNER', 'MANAGER'])
                ->whereNotNull('telegram_id')
                ->get();

            if ($managers->isEmpty()) {
                return;
            }

            $message = "⚠️ *ЗАДАЧА БЕЗ ОТВЕТА СЛИШКОМ ДОЛГО*\n\n";
            $message .= "📌 Задача: {$task->title}\n";

            if ($task->description) {
                $message .= "📝 {$task->description}\n";
            }

            $message .= "📅 Отправлена: " . ($task->appear_date?->format('d.m.Y H:i') ?? 'неизвестно') . "\n";
            $message .= "⏱️ Без ответа уже: {$timeText}\n";

            if ($task->deadline) {
                $message .= "⏰ Дедлайн: " . $task->deadline_for_bot . "\n";
            }

            // Show assigned users
            $assignedUsers = $task->assignments->map(function ($assignment) {
                return $assignment->user ? $assignment->user->full_name : 'Неизвестный';
            })->filter()->implode(', ');

            if ($assignedUsers) {
                $message .= "👤 Исполнители: {$assignedUsers}\n";
                $message .= "🔴 Никто из исполнителей не ответил!\n";
            }

            foreach ($managers as $manager) {
                try {
                    app(TaskNotificationService::class)->getBot()->sendMessage(
                        chat_id: $manager->telegram_id,
                        text: $message,
                        parse_mode: 'markdown'
                    );
                } catch (\Exception $e) {
                    Log::error("Failed to send manager unresponded task notification", [
                        'task_id' => $task->id,
                        'manager_id' => $manager->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error("Failed to process managers unresponded task notifications", [
                'task_id' => $task->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}