<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Service for sending notifications to managers about issues
 */
class ManagerNotificationService
{
    public function __construct(
        private Nutgram $bot
    ) {}

    /**
     * Notify managers about late shift opening
     */
    public function notifyAboutLateShift(Shift $shift): void
    {
        try {
            $user = $shift->user;
            $managers = $this->getManagersForDealership($shift->dealership_id);

            $message = "⚠️ *Опоздание на смену*\n\n";
            $message .= "👤 Сотрудник: {$user->full_name}\n";
            $message .= "⏰ Плановое начало: " . $shift->scheduled_start->format('H:i d.m.Y') . "\n";
            $message .= "⏱️ Фактическое начало: " . $shift->shift_start->format('H:i d.m.Y') . "\n";
            $message .= "⏳ Опоздание: {$shift->late_minutes} мин\n";

            if ($shift->dealership) {
                $message .= "🏢 Салон: {$shift->dealership->name}\n";
            }

            $this->sendToManagers($managers, $message);

            Log::info("Managers notified about late shift #{$shift->id}");
        } catch (\Throwable $e) {
            Log::error('Error notifying about late shift: ' . $e->getMessage());
        }
    }

    /**
     * Notify managers about task postponement
     */
    public function notifyAboutTaskPostponement(Task $task, User $employee, string $reason): void
    {
        try {
            $managers = $this->getManagersForDealership($employee->dealership_id);

            $message = "⚠️ *Задача перенесена*\n\n";
            $message .= "👤 Сотрудник: {$employee->full_name}\n";
            $message .= "📋 Задача: {$task->title}\n";
            $message .= "💬 Причина: {$reason}\n";
            $message .= "🔢 Количество переносов: {$task->postpone_count}\n";

            if ($task->postpone_count > 1) {
                $message .= "\n⚠️ *Внимание: задача переносилась более 1 раза!*";
            }

            $this->sendToManagers($managers, $message);

            Log::info("Managers notified about task #{$task->id} postponement");
        } catch (\Throwable $e) {
            Log::error('Error notifying about task postponement: ' . $e->getMessage());
        }
    }

    /**
     * Notify managers about overdue task
     */
    public function notifyAboutOverdueTask(Task $task, User $employee): void
    {
        try {
            $managers = $this->getManagersForDealership($employee->dealership_id);

            $message = "🚨 *ПРОСРОЧЕННАЯ ЗАДАЧА*\n\n";
            $message .= "👤 Сотрудник: {$employee->full_name}\n";
            $message .= "📋 Задача: {$task->title}\n";

            if ($task->deadline) {
                $message .= "⏰ Дедлайн: " . $task->deadline->format('d.m.Y H:i') . "\n";
                $message .= "⏱️ Просрочено на: " . $this->getOverdueTime($task->deadline) . "\n";
            }

            if ($task->postpone_count > 0) {
                $message .= "🔢 Было переносов: {$task->postpone_count}\n";
            }

            $this->sendToManagers($managers, $message);

            Log::info("Managers notified about overdue task #{$task->id}");
        } catch (\Throwable $e) {
            Log::error('Error notifying about overdue task: ' . $e->getMessage());
        }
    }

    /**
     * Notify managers about shift replacement
     */
    public function notifyAboutReplacement(Shift $shift, User $replacingUser, User $replacedUser, string $reason): void
    {
        try {
            $managers = $this->getManagersForDealership($shift->dealership_id);

            $message = "🔄 *Замещение сотрудника*\n\n";
            $message .= "👤 Выходит: {$replacingUser->full_name}\n";
            $message .= "👤 Заменяет: {$replacedUser->full_name}\n";
            $message .= "💬 Причина: {$reason}\n";
            $message .= "⏰ Время: " . $shift->shift_start->format('H:i d.m.Y') . "\n";

            if ($shift->dealership) {
                $message .= "🏢 Салон: {$shift->dealership->name}\n";
            }

            $this->sendToManagers($managers, $message);

            Log::info("Managers notified about replacement in shift #{$shift->id}");
        } catch (\Throwable $e) {
            Log::error('Error notifying about replacement: ' . $e->getMessage());
        }
    }

    /**
     * Daily summary for managers
     */
    public function sendDailySummary(int $dealershipId): void
    {
        try {
            $managers = $this->getManagersForDealership($dealershipId);

            $today = Carbon::today();

            // Get today's statistics
            $shifts = Shift::where('dealership_id', $dealershipId)
                ->whereDate('shift_start', $today)
                ->get();

            $lateShifts = $shifts->where('late_minutes', '>', 0)->count();
            $replacements = $shifts->filter(fn($s) => $s->replacement !== null)->count();

            $tasks = Task::where('dealership_id', $dealershipId)
                ->where('is_active', true)
                ->get();

            $completedTasks = $tasks->filter(function ($task) use ($today) {
                return $task->responses()
                    ->where('status', 'completed')
                    ->whereDate('responded_at', $today)
                    ->exists();
            })->count();

            $postponedTasks = $tasks->filter(function ($task) use ($today) {
                return $task->responses()
                    ->where('status', 'postponed')
                    ->whereDate('responded_at', $today)
                    ->exists();
            })->count();

            $overdueTasks = $tasks->filter(function ($task) {
                return $task->deadline && $task->deadline->lt(Carbon::now()) &&
                    !$task->responses()->where('status', 'completed')->exists();
            })->count();

            $message = "📊 *Сводка за " . $today->format('d.m.Y') . "*\n\n";
            $message .= "📈 *Смены:*\n";
            $message .= "• Всего: {$shifts->count()}\n";
            if ($lateShifts > 0) {
                $message .= "• ⚠️ Опозданий: {$lateShifts}\n";
            }
            if ($replacements > 0) {
                $message .= "• 🔄 Замещений: {$replacements}\n";
            }

            $message .= "\n📋 *Задачи:*\n";
            $message .= "• ✅ Выполнено: {$completedTasks}\n";
            if ($postponedTasks > 0) {
                $message .= "• ⏭️ Перенесено: {$postponedTasks}\n";
            }
            if ($overdueTasks > 0) {
                $message .= "• 🚨 Просрочено: {$overdueTasks}\n";
            }

            $this->sendToManagers($managers, $message);

            Log::info("Daily summary sent for dealership #{$dealershipId}");
        } catch (\Throwable $e) {
            Log::error('Error sending daily summary: ' . $e->getMessage());
        }
    }

    /**
     * Get managers for specific dealership
     */
    private function getManagersForDealership(int $dealershipId): \Illuminate\Database\Eloquent\Collection
    {
        return User::where('dealership_id', $dealershipId)
            ->whereIn('role', ['manager', 'owner'])
            ->whereNotNull('telegram_id')
            ->get();
    }

    /**
     * Send message to multiple managers
     */
    private function sendToManagers(\Illuminate\Database\Eloquent\Collection $managers, string $message): void
    {
        foreach ($managers as $manager) {
            try {
                $this->bot->sendMessage(
                    text: $message,
                    chat_id: $manager->telegram_id,
                    parse_mode: 'Markdown'
                );
            } catch (\Throwable $e) {
                Log::warning("Failed to notify manager #{$manager->id}: " . $e->getMessage());
            }
        }
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
