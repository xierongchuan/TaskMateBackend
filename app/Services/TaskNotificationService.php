<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Models\TaskNotification;
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
    ) {
        //
    }

    /**
     * Get the bot instance
     */
    public function getBot(): Nutgram
    {
        return $this->bot;
    }

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

            $message = $this->formatTaskMessage($task, 'regular');
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
     * Send upcoming deadline notification (30 minutes before)
     */
    public function sendUpcomingDeadlineNotification(Task $task, User $user): bool
    {
        try {
            if (!$user->telegram_id) {
                Log::warning("User #{$user->id} has no telegram_id for upcoming deadline notification");
                return false;
            }

            // Check if this notification was already sent to prevent duplicates
            if (TaskNotification::wasAlreadySent($task->id, $user->id, TaskNotification::TYPE_UPCOMING_DEADLINE)) {
                Log::info("Upcoming deadline notification already sent for task #{$task->id} to user #{$user->id}, skipping");
                return false;
            }

            $message = $this->formatUpcomingDeadlineMessage($task);
            $keyboard = $this->getTaskKeyboard($task);

            $this->bot->sendMessage(
                text: $message,
                chat_id: $user->telegram_id,
                parse_mode: 'Markdown',
                reply_markup: $keyboard
            );

            // Record that this notification was sent
            TaskNotification::recordSent($task->id, $user->id, TaskNotification::TYPE_UPCOMING_DEADLINE);

            Log::info("Upcoming deadline notification sent for task #{$task->id} to user #{$user->id}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send upcoming deadline notification for task #{$task->id} to user #{$user->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send overdue notification (at deadline time)
     */
    public function sendOverdueNotification(Task $task, User $user): bool
    {
        try {
            if (!$user->telegram_id) {
                Log::warning("User #{$user->id} has no telegram_id for overdue notification");
                return false;
            }

            // Check if this notification was already sent to prevent duplicates
            if (TaskNotification::wasAlreadySent($task->id, $user->id, TaskNotification::TYPE_OVERDUE)) {
                Log::info("Overdue notification already sent for task #{$task->id} to user #{$user->id}, skipping");
                return false;
            }

            $message = $this->formatOverdueMessage($task);
            $keyboard = $this->getTaskKeyboard($task);

            $this->bot->sendMessage(
                text: $message,
                chat_id: $user->telegram_id,
                parse_mode: 'Markdown',
                reply_markup: $keyboard
            );

            // Record that this notification was sent
            TaskNotification::recordSent($task->id, $user->id, TaskNotification::TYPE_OVERDUE);

            Log::info("Overdue notification sent for task #{$task->id} to user #{$user->id}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send overdue notification for task #{$task->id} to user #{$user->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send hour overdue notification (1 hour after deadline)
     */
    public function sendHourOverdueNotification(Task $task, User $user): bool
    {
        try {
            if (!$user->telegram_id) {
                Log::warning("User #{$user->id} has no telegram_id for hour overdue notification");
                return false;
            }

            // Check if this notification was already sent to prevent duplicates
            if (TaskNotification::wasAlreadySent($task->id, $user->id, TaskNotification::TYPE_HOUR_OVERDUE)) {
                Log::info("Hour overdue notification already sent for task #{$task->id} to user #{$user->id}, skipping");
                return false;
            }

            $message = $this->formatHourOverdueMessage($task);
            $keyboard = $this->getTaskKeyboard($task);

            $this->bot->sendMessage(
                text: $message,
                chat_id: $user->telegram_id,
                parse_mode: 'Markdown',
                reply_markup: $keyboard
            );

            // Record that this notification was sent
            TaskNotification::recordSent($task->id, $user->id, TaskNotification::TYPE_HOUR_OVERDUE);

            Log::info("Hour overdue notification sent for task #{$task->id} to user #{$user->id}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send hour overdue notification for task #{$task->id} to user #{$user->id}: " . $e->getMessage());
            return false;
        }
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
    private function formatTaskMessage(Task $task, string $type = 'regular'): string
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
     * Format upcoming deadline message (30 minutes before)
     */
    private function formatUpcomingDeadlineMessage(Task $task): string
    {
        $message = "⏰ *НАПОМИНАНИЕ О ДЕДЛАЙНЕ*\n\n📌 *{$task->title}*\n\n";

        if ($task->description) {
            $message .= "{$task->description}\n\n";
        }

        if ($task->comment) {
            $message .= "💬 Комментарий: {$task->comment}\n\n";
        }

        $message .= "🚨 Дедлайн через 30 минут!\n";
        $message .= "⏰ Время дедлайна: " . $task->deadline_for_bot . "\n";

        if ($task->tags && is_array($task->tags) && !empty($task->tags)) {
            $message .= "🏷️ Теги: " . implode(', ', $task->tags) . "\n";
        }

        return $message;
    }

    /**
     * Format overdue message (at deadline time)
     */
    private function formatOverdueMessage(Task $task): string
    {
        $message = "⚠️ *СРОК ВЫПОЛНЕНИЯ ИСТЁК*\n\n📌 *{$task->title}*\n\n";

        if ($task->description) {
            $message .= "{$task->description}\n\n";
        }

        if ($task->comment) {
            $message .= "💬 Комментарий: {$task->comment}\n\n";
        }

        $message .= "🚨 Дедлайн был: " . $task->deadline_for_bot . "\n";
        $message .= "⏱️ Просрочено на: " . $this->getOverdueTime($task->deadline) . "\n";

        if ($task->tags && is_array($task->tags) && !empty($task->tags)) {
            $message .= "🏷️ Теги: " . implode(', ', $task->tags) . "\n";
        }

        return $message;
    }

    /**
     * Format hour overdue message (1 hour after deadline)
     */
    private function formatHourOverdueMessage(Task $task): string
    {
        $message = "🚨 *ЗАДАЧА ПРОСРОЧЕНА НА ЧАС*\n\n📌 *{$task->title}*\n\n";

        if ($task->description) {
            $message .= "{$task->description}\n\n";
        }

        if ($task->comment) {
            $message .= "💬 Комментарий: {$task->comment}\n\n";
        }

        $message .= "🚨 Дедлайн был: " . $task->deadline_for_bot . "\n";
        $message .= "⏱️ Просрочено на: " . $this->getOverdueTime($task->deadline) . "\n";
        $message .= "❗️ Срочно выполните задачу!\n";

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
     * Check and notify about overdue tasks (at deadline time)
     */
    public function notifyAboutOverdueTasks(): array
    {
        // Use UTC for consistent time comparisons
        $nowUTC = Carbon::now('UTC');

        // Find tasks that became overdue in the last 5 minutes (5-min window)
        $overdueTasks = Task::where('is_active', true)
            ->whereNotNull('deadline')
            ->where('deadline', '<', $nowUTC)
            ->where('deadline', '>=', $nowUTC->copy()->subMinutes(5)) // Only if overdue within last 5 minutes
            ->whereDoesntHave('responses', function ($query) {
                $query->where('status', 'completed');
            })
            ->with(['assignedUsers'])
            ->get();

        $results = [
            'tasks_processed' => $overdueTasks->count(),
            'notifications_sent' => 0,
        ];

        foreach ($overdueTasks as $task) {
            Log::info("Overdue task detected", [
                'task_id' => $task->id,
                'title' => $task->title,
                'deadline' => $task->deadline_for_bot,
                'deadline_utc' => $task->deadline->format('Y-m-d H:i:s'),
                'current_time_utc' => $nowUTC->format('Y-m-d H:i:s'),
                'current_time_user_tz' => $nowUTC->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d H:i:s')
            ]);

            // Send overdue notification to each assigned user
            foreach ($task->assignedUsers as $user) {
                if (!$task->responses()->where('user_id', $user->id)->where('status', 'completed')->exists() && $user->telegram_id) {
                    try {
                        $sent = $this->sendOverdueNotification($task, $user);
                        if ($sent) {
                            $results['notifications_sent']++;
                        }
                    } catch (\Throwable $e) {
                        Log::error("Failed to send overdue notification to user #{$user->id}: " . $e->getMessage());
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check and notify about upcoming deadlines (30 minutes before)
     */
    public function notifyAboutUpcomingDeadlines(): array
    {
        // Use UTC for consistent time comparisons
        $nowUTC = Carbon::now('UTC');

        // Find tasks with deadlines in 25-35 minutes from now (30-min window)
        $upcomingTasks = Task::where('is_active', true)
            ->whereNotNull('deadline')
            ->where('deadline', '>', $nowUTC->copy()->addMinutes(25))
            ->where('deadline', '<', $nowUTC->copy()->addMinutes(35))
            ->whereDoesntHave('responses', function ($query) {
                $query->where('status', 'completed');
            })
            ->with(['assignedUsers'])
            ->get();

        $results = [
            'tasks_processed' => $upcomingTasks->count(),
            'notifications_sent' => 0,
        ];

        foreach ($upcomingTasks as $task) {
            Log::info("Upcoming deadline detected", [
                'task_id' => $task->id,
                'title' => $task->title,
                'deadline' => $task->deadline_for_bot,
                'current_time' => $nowUTC->format('Y-m-d H:i:s')
            ]);

            foreach ($task->assignedUsers as $user) {
                if (!$task->responses()->where('user_id', $user->id)->where('status', 'completed')->exists() && $user->telegram_id) {
                    try {
                        $sent = $this->sendUpcomingDeadlineNotification($task, $user);
                        if ($sent) {
                            $results['notifications_sent']++;
                        }
                    } catch (\Throwable $e) {
                        Log::error("Failed to send upcoming deadline notification to user #{$user->id}: " . $e->getMessage());
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check and notify about tasks overdue by 1 hour
     */
    public function notifyAboutHourlyOverdueTasks(): array
    {
        // Use UTC for consistent time comparisons
        $nowUTC = Carbon::now('UTC');

        // Find tasks overdue by 55-65 minutes (10-min window around 1 hour)
        $hourlyOverdueTasks = Task::where('is_active', true)
            ->whereNotNull('deadline')
            ->where('deadline', '<', $nowUTC->copy()->subMinutes(55))
            ->where('deadline', '>', $nowUTC->copy()->subMinutes(65))
            ->whereDoesntHave('responses', function ($query) {
                $query->where('status', 'completed');
            })
            ->with(['assignedUsers'])
            ->get();

        $results = [
            'tasks_processed' => $hourlyOverdueTasks->count(),
            'notifications_sent' => 0,
        ];

        foreach ($hourlyOverdueTasks as $task) {
            Log::info("Hourly overdue task detected", [
                'task_id' => $task->id,
                'title' => $task->title,
                'deadline' => $task->deadline_for_bot,
                'current_time' => $nowUTC->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d H:i:s')
            ]);

            foreach ($task->assignedUsers as $user) {
                if (!$task->responses()->where('user_id', $user->id)->where('status', 'completed')->exists() && $user->telegram_id) {
                    try {
                        $sent = $this->sendHourOverdueNotification($task, $user);
                        if ($sent) {
                            $results['notifications_sent']++;
                        }
                    } catch (\Throwable $e) {
                        Log::error("Failed to send hourly overdue notification to user #{$user->id}: " . $e->getMessage());
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check if a task is overdue
     */
    private function isTaskOverdue(Task $task): bool
    {
        if (!$task->deadline) {
            return false;
        }

        // Check if task has completed response
        $hasCompletedResponse = $task->responses()
            ->where('status', 'completed')
            ->exists();

        if ($hasCompletedResponse) {
            return false;
        }

        // Use Asia/Yekaterinburg timezone for consistent time comparison
        $nowInUserTimezone = Carbon::now('Asia/Yekaterinburg');
        $deadlineInUserTimezone = $task->deadline->copy()->setTimezone('Asia/Yekaterinburg');

        return $deadlineInUserTimezone->isPast();
    }

    /**
     * Get human-readable overdue time
     */
    private function getOverdueTime(Carbon $deadline): string
    {
        // Use Asia/Yekaterinburg timezone for consistent time calculation
        $nowInUserTimezone = Carbon::now('Asia/Yekaterinburg');
        $deadlineInUserTimezone = $deadline->copy()->setTimezone('Asia/Yekaterinburg');
        $diff = $nowInUserTimezone->diff($deadlineInUserTimezone);

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
