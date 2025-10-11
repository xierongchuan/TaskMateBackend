<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\ShiftReplacement;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Job to send weekly reports to managers
 */
class SendWeeklyReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(Nutgram $bot): void
    {
        try {
            $now = Carbon::now();
            $weekStart = $now->copy()->startOfWeek();
            $weekEnd = $now->copy()->endOfWeek();

            // Get all dealerships
            $dealerships = AutoDealership::where('is_active', true)->get();

            foreach ($dealerships as $dealership) {
                $this->sendDealershipReport($bot, $dealership, $weekStart, $weekEnd);
            }

            Log::info('SendWeeklyReportJob completed', [
                'dealerships_count' => $dealerships->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('SendWeeklyReportJob failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Send report for a specific dealership
     */
    private function sendDealershipReport(
        Nutgram $bot,
        AutoDealership $dealership,
        Carbon $weekStart,
        Carbon $weekEnd
    ): void {
        try {
            // Get managers for this dealership
            $managers = User::where('dealership_id', $dealership->id)
                ->whereIn('role', ['owner', 'manager'])
                ->whereNotNull('telegram_id')
                ->get();

            if ($managers->isEmpty()) {
                return;
            }

            // Collect statistics
            $stats = $this->collectWeeklyStatistics($dealership->id, $weekStart, $weekEnd);

            // Format report message
            $message = $this->formatWeeklyReport($dealership, $stats, $weekStart, $weekEnd);

            // Send to all managers
            foreach ($managers as $manager) {
                try {
                    $bot->sendMessage(
                        text: $message,
                        chat_id: $manager->telegram_id,
                        parse_mode: 'Markdown'
                    );
                } catch (\Throwable $e) {
                    Log::error('Failed to send weekly report to manager', [
                        'manager_id' => $manager->id,
                        'telegram_id' => $manager->telegram_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Weekly report sent for dealership', [
                'dealership_id' => $dealership->id,
                'managers_count' => $managers->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send dealership report', [
                'dealership_id' => $dealership->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Collect weekly statistics
     */
    private function collectWeeklyStatistics(int $dealershipId, Carbon $weekStart, Carbon $weekEnd): array
    {
        // Shifts statistics
        $totalShifts = Shift::where('dealership_id', $dealershipId)
            ->whereBetween('shift_start', [$weekStart, $weekEnd])
            ->count();

        $lateShifts = Shift::where('dealership_id', $dealershipId)
            ->whereBetween('shift_start', [$weekStart, $weekEnd])
            ->where('status', 'late')
            ->count();

        $totalLateMinutes = Shift::where('dealership_id', $dealershipId)
            ->whereBetween('shift_start', [$weekStart, $weekEnd])
            ->sum('late_minutes');

        // Replacements
        $replacements = ShiftReplacement::whereHas('shift', function ($query) use ($dealershipId, $weekStart, $weekEnd) {
            $query->where('dealership_id', $dealershipId)
                ->whereBetween('shift_start', [$weekStart, $weekEnd]);
        })->get();

        // Tasks statistics
        $completedTasks = TaskResponse::whereHas('task', function ($query) use ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        })
        ->where('status', 'completed')
        ->whereBetween('created_at', [$weekStart, $weekEnd])
        ->count();

        $postponedTasks = TaskResponse::whereHas('task', function ($query) use ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        })
        ->where('status', 'postponed')
        ->whereBetween('created_at', [$weekStart, $weekEnd])
        ->count();

        $overdueTasks = Task::where('dealership_id', $dealershipId)
            ->where('is_active', true)
            ->whereNotNull('deadline')
            ->where('deadline', '<', Carbon::now())
            ->whereDoesntHave('responses', function ($query) {
                $query->where('status', 'completed');
            })
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->count();

        // Employee performance
        $employeeStats = $this->getEmployeePerformance($dealershipId, $weekStart, $weekEnd);

        return [
            'total_shifts' => $totalShifts,
            'late_shifts' => $lateShifts,
            'total_late_minutes' => $totalLateMinutes,
            'replacements_count' => $replacements->count(),
            'replacements' => $replacements,
            'completed_tasks' => $completedTasks,
            'postponed_tasks' => $postponedTasks,
            'overdue_tasks' => $overdueTasks,
            'employee_stats' => $employeeStats,
        ];
    }

    /**
     * Get employee performance statistics
     */
    private function getEmployeePerformance(int $dealershipId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $employees = User::where('dealership_id', $dealershipId)
            ->where('role', 'employee')
            ->get();

        $stats = [];

        foreach ($employees as $employee) {
            $shiftsCount = Shift::where('user_id', $employee->id)
                ->whereBetween('shift_start', [$weekStart, $weekEnd])
                ->count();

            $lateCount = Shift::where('user_id', $employee->id)
                ->whereBetween('shift_start', [$weekStart, $weekEnd])
                ->where('status', 'late')
                ->count();

            $completedTasks = TaskResponse::where('user_id', $employee->id)
                ->where('status', 'completed')
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->count();

            $postponedTasks = TaskResponse::where('user_id', $employee->id)
                ->where('status', 'postponed')
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->count();

            $stats[] = [
                'name' => $employee->full_name,
                'shifts' => $shiftsCount,
                'late' => $lateCount,
                'completed' => $completedTasks,
                'postponed' => $postponedTasks,
            ];
        }

        // Sort by most issues (late + postponed)
        usort($stats, function ($a, $b) {
            return ($b['late'] + $b['postponed']) <=> ($a['late'] + $a['postponed']);
        });

        return $stats;
    }

    /**
     * Format weekly report message
     */
    private function formatWeeklyReport(
        AutoDealership $dealership,
        array $stats,
        Carbon $weekStart,
        Carbon $weekEnd
    ): string {
        $message = "📊 *Еженедельный отчёт*\n";
        $message .= "*{$dealership->name}*\n";
        $message .= "📅 {$weekStart->format('d.m.Y')} - {$weekEnd->format('d.m.Y')}\n\n";

        // Shifts
        $message .= "🔄 *Смены:*\n";
        $message .= "• Всего: {$stats['total_shifts']}\n";
        $message .= "• Опоздания: {$stats['late_shifts']}\n";
        if ($stats['total_late_minutes'] > 0) {
            $message .= "• Всего опозданий: {$stats['total_late_minutes']} мин\n";
        }
        $message .= "\n";

        // Replacements
        if ($stats['replacements_count'] > 0) {
            $message .= "🔄 *Замещения ({$stats['replacements_count']}):*\n";
            foreach ($stats['replacements'] as $replacement) {
                $replacing = User::find($replacement->replacing_user_id);
                $replaced = User::find($replacement->replaced_user_id);
                $message .= "• {$replacing->full_name} → {$replaced->full_name}\n";
                $message .= "  Причина: {$replacement->reason}\n";
            }
            $message .= "\n";
        }

        // Tasks
        $message .= "📋 *Задачи:*\n";
        $message .= "• Выполнено: {$stats['completed_tasks']}\n";
        $message .= "• Отложено: {$stats['postponed_tasks']}\n";
        $message .= "• Просрочено: {$stats['overdue_tasks']}\n\n";

        // Top issues
        if (!empty($stats['employee_stats'])) {
            $message .= "👥 *Статистика сотрудников:*\n";
            $topCount = min(5, count($stats['employee_stats']));
            for ($i = 0; $i < $topCount; $i++) {
                $emp = $stats['employee_stats'][$i];
                $message .= "• {$emp['name']}\n";
                $message .= "  Смены: {$emp['shifts']}, Опоздания: {$emp['late']}\n";
                $message .= "  Задачи: ✅{$emp['completed']} ⏭️{$emp['postponed']}\n";
            }
        }

        $message .= "\n---\n🤖 _Автоматический отчёт системы Мисье Бот_";

        return $message;
    }
}
