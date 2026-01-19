<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\TimeHelper;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskGenerator;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для получения данных дашборда.
 *
 * Оптимизирует запросы к базе данных путём объединения
 * и использования агрегатных функций.
 */
class DashboardService
{
    /**
     * Кэш временных границ текущего дня.
     *
     * @var array{start: Carbon, end: Carbon}|null
     */
    private ?array $todayBoundaries = null;

    /**
     * Получает все данные для дашборда.
     *
     * @param int|null $dealershipId ID автосалона для фильтрации
     * @return array<string, mixed>
     */
    public function getDashboardData(?int $dealershipId = null): array
    {
        $this->todayBoundaries = [
            'start' => TimeHelper::startOfDayUtc(),
            'end' => TimeHelper::endOfDayUtc(),
        ];

        // Получаем статистику задач одним оптимизированным запросом
        $taskStats = $this->getTaskStatistics($dealershipId);

        // Получаем активные смены с eager loading
        $activeShifts = $this->getActiveShifts($dealershipId);

        return [
            'total_users' => $this->getUserCount($dealershipId),
            'active_users' => $this->getUserCount($dealershipId),
            'total_tasks' => $taskStats['total_active'],
            'active_tasks' => $taskStats['total_active'],
            'completed_tasks' => $taskStats['completed_today'],
            'overdue_tasks' => $taskStats['overdue'],
            'overdue_tasks_list' => $this->getOverdueTasksList($dealershipId),
            'open_shifts' => count($activeShifts),
            'late_shifts_today' => $this->getLateShiftsCount($dealershipId),
            'active_shifts' => $activeShifts,
            'recent_tasks' => $this->getRecentTasks($dealershipId),
            'active_generators' => $this->getGeneratorStats($dealershipId)['active'],
            'total_generators' => $this->getGeneratorStats($dealershipId)['total'],
            'tasks_generated_today' => $this->getGeneratorStats($dealershipId)['generated_today'],
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Получает статистику задач оптимизированным запросом.
     *
     * @param int|null $dealershipId
     * @return array{total_active: int, completed_today: int, overdue: int, postponed: int}
     */
    protected function getTaskStatistics(?int $dealershipId): array
    {
        $nowUtc = TimeHelper::nowUtc();
        $todayStart = $this->todayBoundaries['start'];
        $todayEnd = $this->todayBoundaries['end'];

        // Оптимизированный запрос с условными агрегатами
        $query = Task::query()
            ->where('is_active', true)
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->selectRaw('
                COUNT(*) as total_active,
                SUM(CASE WHEN postpone_count > 0 THEN 1 ELSE 0 END) as postponed
            ')
            ->first();

        // Подсчёт просроченных задач (без выполненных)
        $overdueCount = Task::query()
            ->where('is_active', true)
            ->where('deadline', '<', $nowUtc)
            ->whereDoesntHave('responses', fn ($q) => $q->where('status', 'completed'))
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();

        // Подсчёт завершённых сегодня
        $completedToday = TaskResponse::query()
            ->where('status', 'completed')
            ->whereBetween('responded_at', [$todayStart, $todayEnd])
            ->when($dealershipId, function ($q) use ($dealershipId) {
                $q->whereHas('task', fn ($tq) => $tq->where('dealership_id', $dealershipId));
            })
            ->distinct('task_id')
            ->count('task_id');

        return [
            'total_active' => (int) ($query->total_active ?? 0),
            'completed_today' => $completedToday,
            'overdue' => $overdueCount,
            'postponed' => (int) ($query->postponed ?? 0),
        ];
    }

    /**
     * Получает активные смены.
     *
     * @param int|null $dealershipId
     * @return Collection
     */
    protected function getActiveShifts(?int $dealershipId): Collection
    {
        return Shift::with(['user:id,full_name', 'dealership:id,name', 'replacement.replacingUser:id,full_name'])
            ->where('status', 'open')
            ->whereNull('shift_end')
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderBy('shift_start')
            ->get()
            ->map(fn ($shift) => [
                'id' => $shift->id,
                'user' => [
                    'id' => $shift->user->id,
                    'full_name' => $shift->user->full_name,
                ],
                'replacement' => $shift->replacement ? [
                    'id' => $shift->replacement->replacingUser->id,
                    'full_name' => $shift->replacement->replacingUser->full_name,
                ] : null,
                'status' => $shift->status,
                'opened_at' => $shift->shift_start->toIso8601String(),
                'closed_at' => $shift->shift_end?->toIso8601String(),
                'scheduled_start' => $shift->scheduled_start?->toIso8601String(),
                'scheduled_end' => $shift->scheduled_end?->toIso8601String(),
                'is_late' => $shift->late_minutes > 0,
                'late_minutes' => $shift->late_minutes,
            ]);
    }

    /**
     * Получает количество опоздавших смен сегодня.
     *
     * @param int|null $dealershipId
     * @return int
     */
    protected function getLateShiftsCount(?int $dealershipId): int
    {
        return Shift::query()
            ->whereBetween('shift_start', [$this->todayBoundaries['start'], $this->todayBoundaries['end']])
            ->where('late_minutes', '>', 0)
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();
    }

    /**
     * Получает последние задачи.
     *
     * @param int|null $dealershipId
     * @return Collection
     */
    protected function getRecentTasks(?int $dealershipId): Collection
    {
        return Task::with('creator:id,full_name')
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function ($task) {
                $data = $task->toApiArray();
                return [
                    'id' => $data['id'],
                    'title' => $data['title'],
                    'status' => $data['status'],
                    'created_at' => $data['created_at'],
                    'creator' => $task->creator ? [
                        'full_name' => $task->creator->full_name,
                    ] : null,
                ];
            });
    }

    /**
     * Получает количество пользователей.
     *
     * @param int|null $dealershipId
     * @return int
     */
    protected function getUserCount(?int $dealershipId): int
    {
        return User::query()
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();
    }

    /**
     * Получает список просроченных задач.
     *
     * @param int|null $dealershipId
     * @return Collection
     */
    protected function getOverdueTasksList(?int $dealershipId): Collection
    {
        return Task::with(['creator:id,full_name', 'dealership:id,name', 'assignments.user:id,full_name', 'responses.user:id,full_name'])
            ->where('is_active', true)
            ->where('deadline', '<', TimeHelper::nowUtc())
            ->whereDoesntHave('responses', fn ($q) => $q->where('status', 'completed'))
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->orderBy('deadline')
            ->limit(10)
            ->get()
            ->map(fn ($task) => $task->toApiArray());
    }

    /**
     * Получает статистику генераторов задач.
     *
     * @param int|null $dealershipId
     * @return array{total: int, active: int, generated_today: int}
     */
    protected function getGeneratorStats(?int $dealershipId): array
    {
        // Оптимизированный запрос с условными агрегатами
        $stats = TaskGenerator::query()
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active
            ')
            ->first();

        // Подсчёт сгенерированных задач за сегодня
        $generatedToday = Task::query()
            ->whereNotNull('generator_id')
            ->whereBetween('created_at', [$this->todayBoundaries['start'], $this->todayBoundaries['end']])
            ->when($dealershipId, fn ($q) => $q->where('dealership_id', $dealershipId))
            ->count();

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'generated_today' => $generatedToday,
        ];
    }
}
