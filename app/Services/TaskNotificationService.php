<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotificationSetting;
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
            if (!$this->shouldNotifyUser($user, NotificationSetting::CHANNEL_TASK_ASSIGNED, $task)) {
                return false;
            }

            if (!$user->telegram_id) {
                // Use debug level to avoid log spam
                Log::debug("User has no telegram_id, skipping task notification", [
                    'user_id' => $user->id,
                    'task_id' => $task->id
                ]);
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

            Log::info('Task notification sent successfully', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'task_title' => $task->title
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send task notification', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ]);
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
    public function sendUpcomingDeadlineNotification(Task $task, User $user, int $offset = 30): bool
    {
        try {
            if (!$this->shouldNotifyUser($user, NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN, $task)) {
                return false;
            }

            if (!$user->telegram_id) {
                Log::warning("User #{$user->id} has no telegram_id for upcoming deadline notification");
                return false;
            }

            // Check if this notification was already sent to prevent duplicates
            if (TaskNotification::wasAlreadySent($task->id, $user->id, TaskNotification::TYPE_UPCOMING_DEADLINE)) {
                Log::info("Upcoming deadline notification already sent for task #{$task->id} to user #{$user->id}, skipping");
                return false;
            }

            $message = $this->formatUpcomingDeadlineMessage($task, $offset);
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
            if (!$this->shouldNotifyUser($user, NotificationSetting::CHANNEL_TASK_OVERDUE, $task)) {
                return false;
            }

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
    public function sendHourOverdueNotification(Task $task, User $user, int $offset = 60): bool
    {
        try {
            if (!$this->shouldNotifyUser($user, NotificationSetting::CHANNEL_TASK_HOUR_LATE, $task)) {
                return false;
            }

            if (!$user->telegram_id) {
                Log::warning("User #{$user->id} has no telegram_id for hour overdue notification");
                return false;
            }

            // Check if this notification was already sent to prevent duplicates
            if (TaskNotification::wasAlreadySent($task->id, $user->id, TaskNotification::TYPE_HOUR_OVERDUE)) {
                Log::info("Hour overdue notification already sent for task #{$task->id} to user #{$user->id}, skipping");
                return false;
            }

            $message = $this->formatHourOverdueMessage($task, $offset);
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
            ->with(['assignedUsers', 'dealership'])
            ->get();

        $results = [
            'tasks_processed' => $overdueTasks->count(),
            'notifications_sent' => 0,
        ];

        foreach ($overdueTasks as $task) {
            // Check if this notification channel is enabled for the dealership
            if ($task->dealership_id && !NotificationSetting::isChannelEnabled(
                $task->dealership_id,
                NotificationSetting::CHANNEL_TASK_OVERDUE
            )) {
                // Check if enabled in task overrides
                $taskSettings = $task->notification_settings ?? [];
                $isEnabled = $taskSettings[NotificationSetting::CHANNEL_TASK_OVERDUE]['enabled'] ?? false;

                if (!$isEnabled) {
                    Log::info('Overdue task notification skipped (channel disabled)', [
                        'task_id' => $task->id,
                        'dealership_id' => $task->dealership_id
                    ]);
                    continue;
                }
            }

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

        // Get all active tasks with deadlines
        $upcomingTasks = Task::where('is_active', true)
            ->whereNotNull('deadline')
            ->whereDoesntHave('responses', function ($query) {
                $query->where('status', 'completed');
            })
            ->with(['assignedUsers', 'dealership'])
            ->get();

        $results = [
            'tasks_processed' => 0,
            'notifications_sent' => 0,
        ];

        foreach ($upcomingTasks as $task) {
            // Get configurable offset (default 30 minutes)
            $offset = $this->getNotificationOffset($task, NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN) ?? 30;

            // Check if deadline is in the configured time window (offset ± 5 minutes)
            $minutesUntilDeadline = $nowUTC->diffInMinutes($task->deadline, false);

            if ($minutesUntilDeadline <= ($offset + 5) && $minutesUntilDeadline >= ($offset - 5)) {
                // Check if this notification channel is enabled
                $isEnabled = true;
                if ($task->dealership_id) {
                    $isEnabled = NotificationSetting::isChannelEnabled(
                        $task->dealership_id,
                        NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN
                    );
                }

                // Check task override
                if (isset($task->notification_settings[NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN]['enabled'])) {
                    $isEnabled = $task->notification_settings[NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN]['enabled'];
                }

                if (!$isEnabled) {
                    continue;
                }

                $results['tasks_processed']++;

                Log::info("Upcoming deadline detected", [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'deadline' => $task->deadline_for_bot,
                    'offset_minutes' => $offset,
                    'minutes_until_deadline' => round($minutesUntilDeadline),
                    'current_time' => $nowUTC->format('Y-m-d H:i:s')
                ]);

                foreach ($task->assignedUsers as $user) {
                    if (!$task->responses()->where('user_id', $user->id)->where('status', 'completed')->exists() && $user->telegram_id) {
                        try {
                            $sent = $this->sendUpcomingDeadlineNotification($task, $user, $offset);
                            if ($sent) {
                                $results['notifications_sent']++;
                            }
                        } catch (\Throwable $e) {
                            Log::error("Failed to send upcoming deadline notification to user #{$user->id}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check and notify about tasks overdue by X minutes (default 60)
     */
    public function notifyAboutHourlyOverdueTasks(): array
    {
        // Use UTC for consistent time comparisons
        $nowUTC = Carbon::now('UTC');

        // We need to iterate all active overdue tasks because the offset might differ per task
        // Optimization: Filter roughly by tasks overdue > 30 mins to reduce set
        $overdueTasks = Task::where('is_active', true)
            ->whereNotNull('deadline')
            ->where('deadline', '<', $nowUTC->copy()->subMinutes(30))
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
            // Get offset for this task (default 60)
            $offset = $this->getNotificationOffset($task, NotificationSetting::CHANNEL_TASK_HOUR_LATE) ?? 60;

            // Check if deadline was exactly offset minutes ago (± 5 mins)
            // e.g. if offset=60, deadline should be between 55 and 65 mins ago
            $minutesOverdue = $task->deadline->diffInMinutes($nowUTC, false); // Positive if past

            if ($minutesOverdue >= ($offset - 5) && $minutesOverdue <= ($offset + 5)) {
                // Check if enabled
                $isEnabled = true;
                if ($task->dealership_id) {
                    $isEnabled = NotificationSetting::isChannelEnabled(
                        $task->dealership_id,
                        NotificationSetting::CHANNEL_TASK_HOUR_LATE
                    );
                }

                // Check task override
                if (isset($task->notification_settings[NotificationSetting::CHANNEL_TASK_HOUR_LATE]['enabled'])) {
                    $isEnabled = $task->notification_settings[NotificationSetting::CHANNEL_TASK_HOUR_LATE]['enabled'];
                }

                if (!$isEnabled) {
                    continue;
                }

                $results['tasks_processed']++;

                Log::info("Hourly overdue task detected", [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'deadline' => $task->deadline_for_bot,
                    'offset' => $offset,
                    'minutes_overdue' => $minutesOverdue,
                    'current_time' => $nowUTC->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d H:i:s')
                ]);

                foreach ($task->assignedUsers as $user) {
                    if (!$task->responses()->where('user_id', $user->id)->where('status', 'completed')->exists() && $user->telegram_id) {
                        try {
                            $sent = $this->sendHourOverdueNotification($task, $user, $offset);
                            if ($sent) {
                                $results['notifications_sent']++;
                            }
                        } catch (\Throwable $e) {
                            Log::error("Failed to send hourly overdue notification to user #{$user->id}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Helper to check if user should be notified based on role settings
     */
    private function shouldNotifyUser(User $user, string $channelType, ?Task $task = null): bool
    {
        // If task has specific settings for this channel, check if they override roles
        // Note: Currently we only support role filtering at dealership level

        if ($task && $task->dealership_id) {
            $allowedRoles = NotificationSetting::getRecipientRoles($task->dealership_id, $channelType);

            // If roles are defined, check if user has one of them
            if ($allowedRoles && !empty($allowedRoles)) {
                // User role is stored as string (e.g. 'employee', 'manager')
                // We should normalize case
                $userRole = strtolower($user->role);
                $allowedRoles = array_map('strtolower', $allowedRoles);

                if (!in_array($userRole, $allowedRoles)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Helper to get notification offset for a task/channel
     */
    private function getNotificationOffset(Task $task, string $channelType): ?int
    {
        // Check task override first
        if (isset($task->notification_settings[$channelType]['offset'])) {
            return (int) $task->notification_settings[$channelType]['offset'];
        }

        // Fallback to dealership setting
        if ($task->dealership_id) {
            return NotificationSetting::getNotificationOffset($task->dealership_id, $channelType);
        }

        return null;
    }

    // ... existing format methods ...

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

    private function formatUpcomingDeadlineMessage(Task $task, int $offset = 30): string
    {
        $message = "⏰ *НАПОМИНАНИЕ О ДЕДЛАЙНЕ*\n\n📌 *{$task->title}*\n\n";

        if ($task->description) {
            $message .= "{$task->description}\n\n";
        }

        if ($task->comment) {
            $message .= "💬 Комментарий: {$task->comment}\n\n";
        }

        $message .= "🚨 Дедлайн через {$offset} минут!\n";
        $message .= "⏰ Время дедлайна: " . $task->deadline_for_bot . "\n";

        if ($task->tags && is_array($task->tags) && !empty($task->tags)) {
            $message .= "🏷️ Теги: " . implode(', ', $task->tags) . "\n";
        }

        return $message;
    }

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

    private function formatHourOverdueMessage(Task $task, int $offset = 60): string
    {
        $message = "🚨 *ЗАДАЧА ПРОСРОЧЕНА НА " . ($offset >= 60 ? round($offset/60, 1) . " ЧАС(А)" : "{$offset} МИНУТ") . "*\n\n📌 *{$task->title}*\n\n";

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

    // ... existing helper methods ...
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

    // Missing method from original file
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
}
