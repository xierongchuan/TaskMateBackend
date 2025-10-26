<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Service for sending task notifications to employees
 */
class TaskNotificationService
{
    public function __construct(
        private Nutgram $bot
    ) {}

    /**
     * Send task notification to a specific user (alias for sendTaskToUser)
     */
    public function notifyUser(User $user, Task $task): bool
    {
        return $this->sendTaskToUser($task, $user);
    }

    /**
     * Send task notification to a specific user
     */
    public function sendTaskToUser(Task $task, User $user): bool
    {
        try {
            if (!$user->telegram_id) {
                Log::warning("User #{$user->id} has no telegram_id");
                return false;
            }

            $message = $this->formatTaskMessage($task);
            $keyboard = $this->getTaskKeyboard($task);

            $this->bot->sendMessage(
                text: $message,
                chat_id: $user->telegram_id,
                parse_mode: 'Markdown',
                reply_markup: $keyboard
            );

            Log::info("Task #{$task->id} sent to user #{$user->id}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send task #{$task->id} to user #{$user->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send task to all assigned users
     */
    public function sendTaskToAssignedUsers(Task $task): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
        ];

        $assignedUsers = $task->assignedUsers;

        foreach ($assignedUsers as $user) {
            if ($this->sendTaskToUser($task, $user)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Send pending tasks to user (e.g., on shift open)
     */
    public function sendPendingTasksToUser(User $user): int
    {
        $tasks = Task::whereHas('assignments', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->where('is_active', true)
        ->where(function ($query) {
            $query->whereNull('appear_date')
                ->orWhere('appear_date', '<=', Carbon::now());
        })
        ->whereDoesntHave('responses', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('status', 'completed');
        })
        ->get();

        $sent = 0;
        foreach ($tasks as $task) {
            if ($this->sendTaskToUser($task, $user)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Format task message
     */
    private function formatTaskMessage(Task $task): string
    {
        $message = "📌 *{$task->title}*\n\n";

        if ($task->description) {
            $message .= "{$task->description}\n\n";
        }

        if ($task->comment) {
            $message .= "💬 Комментарий: {$task->comment}\n\n";
        }

        if ($task->deadline) {
            $message .= "⏰ Дедлайн: " . $task->deadline_for_bot . "\n";
        }

        if ($task->tags && is_array($task->tags) && !empty($task->tags)) {
            $message .= "🏷️ Теги: " . implode(', ', $task->tags) . "\n";
        }

        return $message;
    }

    /**
     * Get keyboard for task based on response type
     */
    private function getTaskKeyboard(Task $task): ?\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup
    {
        return match ($task->response_type) {
            'notification' => \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    text: '✅ OK',
                    callback_data: 'task_ok_' . $task->id
                )),
            'execution' => \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                ->addRow(
                    \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '✅ Выполнено',
                        callback_data: 'task_done_' . $task->id
                    ),
                    \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                        text: '⏭️ Перенести',
                        callback_data: 'task_postpone_' . $task->id
                    )
                ),
            default => null,
        };
    }

    /**
     * Check and notify about overdue tasks
     */
    public function notifyAboutOverdueTasks(): array
    {
        $overdueTasks = Task::where('is_active', true)
            ->whereNotNull('deadline')
            ->where('deadline', '<', Carbon::now())
            ->whereDoesntHave('responses', function ($query) {
                $query->where('status', 'completed');
            })
            ->with(['assignedUsers', 'dealership'])
            ->get();

        $results = [
            'tasks_processed' => 0,
            'notifications_sent' => 0,
        ];

        foreach ($overdueTasks as $task) {
            $results['tasks_processed']++;

            foreach ($task->assignedUsers as $user) {
                // Check if user hasn't completed the task
                $hasCompleted = $task->responses()
                    ->where('user_id', $user->id)
                    ->where('status', 'completed')
                    ->exists();

                if (!$hasCompleted && $user->telegram_id) {
                    try {
                        $message = "⚠️ *ПРОСРОЧЕНА ЗАДАЧА*\n\n";
                        $message .= "📌 {$task->title}\n";
                        $message .= "⏰ Дедлайн был: " . $task->deadline_for_bot . "\n";
                        $message .= "⏱️ Просрочено на: " . $this->getOverdueTime($task->deadline);

                        $this->bot->sendMessage(
                            text: $message,
                            chat_id: $user->telegram_id,
                            parse_mode: 'Markdown'
                        );

                        $results['notifications_sent']++;
                    } catch (\Throwable $e) {
                        Log::error("Failed to send overdue notification to user #{$user->id}: " . $e->getMessage());
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Get human-readable overdue time
     */
    private function getOverdueTime(Carbon $deadline): string
    {
        $diff = Carbon::now()->diff($deadline);

        if ($diff->days > 0) {
            return $diff->days . ' ' . $this->pluralize($diff->days, 'день', 'дня', 'дней');
        }

        if ($diff->h > 0) {
            return $diff->h . ' ' . $this->pluralize($diff->h, 'час', 'часа', 'часов');
        }

        return $diff->i . ' ' . $this->pluralize($diff->i, 'минута', 'минуты', 'минут');
    }

    /**
     * Russian pluralization
     */
    private function pluralize(int $number, string $one, string $few, string $many): string
    {
        $mod10 = $number % 10;
        $mod100 = $number % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }
}
