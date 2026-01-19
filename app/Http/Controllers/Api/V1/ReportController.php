<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (! $dateFrom || ! $dateTo) {
            return response()->json(['message' => 'Parameters date_from and date_to are required'], 400);
        }

        // Конвертируем даты в UTC для запросов к БД
        $from = TimeHelper::startOfDayUtc($dateFrom);
        $to = TimeHelper::endOfDayUtc($dateTo);
        $nowUtc = TimeHelper::nowUtc();

        // Фильтр по автосалону для менеджеров
        $dealershipId = null;
        if ($user->role === 'manager' && $user->dealership_id) {
            $dealershipId = $user->dealership_id;
        }

        // Helper для применения фильтра по автосалону
        $applyTaskFilter = function ($query) use ($dealershipId) {
            if ($dealershipId) {
                $query->where('dealership_id', $dealershipId);
            }
        };

        $applyShiftFilter = function ($query) use ($dealershipId) {
            if ($dealershipId) {
                $query->where('dealership_id', $dealershipId);
            }
        };

        // === SUMMARY STATISTICS ===

        // Всего задач в периоде
        $totalTasksQuery = Task::whereBetween('created_at', [$from, $to]);
        $applyTaskFilter($totalTasksQuery);
        $totalTasks = $totalTasksQuery->count();

        // Переносы
        $postponedTasksQuery = Task::whereBetween('created_at', [$from, $to])
            ->where('postpone_count', '>', 0);
        $applyTaskFilter($postponedTasksQuery);
        $postponedTasks = $postponedTasksQuery->count();

        // Смены
        $totalShiftsQuery = Shift::whereBetween('shift_start', [$from, $to]);
        $applyShiftFilter($totalShiftsQuery);
        $totalShifts = $totalShiftsQuery->count();

        $lateShiftsQuery = Shift::whereBetween('shift_start', [$from, $to])
            ->where('late_minutes', '>', 0);
        $applyShiftFilter($lateShiftsQuery);
        $lateShifts = $lateShiftsQuery->count();

        $totalReplacementsQuery = Shift::whereBetween('shift_start', [$from, $to])->has('replacement');
        $applyShiftFilter($totalReplacementsQuery);
        $totalReplacements = $totalReplacementsQuery->count();

        // === ПОДСЧЁТ СТАТУСОВ БЕЗ ДВОЙНОГО СЧЁТА ===
        // Используем взаимоисключающую логику как в Task::getStatusAttribute()

        // Получаем все задачи периода с responses для расчёта статусов
        $tasksQuery = Task::with(['responses', 'assignments'])->whereBetween('created_at', [$from, $to]);
        $applyTaskFilter($tasksQuery);
        $allTasks = $tasksQuery->get();

        // Считаем статусы по каждой задаче индивидуально
        $statusCounts = [
            'completed' => 0,
            'completed_late' => 0,
            'pending_review' => 0,
            'acknowledged' => 0,
            'overdue' => 0,
            'pending' => 0,
        ];

        foreach ($allTasks as $task) {
            $status = $this->calculateTaskStatus($task, $nowUtc);
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        // Формируем массив для API (сумма должна равняться totalTasks)
        $tasksByStatus = [];
        foreach ($statusCounts as $status => $count) {
            $tasksByStatus[] = [
                'status' => $status,
                'count' => $count,
                'percentage' => $totalTasks > 0 ? round(($count / $totalTasks) * 100, 1) : 0,
            ];
        }

        // Суммарные completed (включая с опозданием) и overdue для summary
        $completedTasks = $statusCounts['completed'] + $statusCounts['completed_late'];
        $overdueTasks = $statusCounts['overdue'];

        // === ПРОИЗВОДИТЕЛЬНОСТЬ СОТРУДНИКОВ ===
        $employeesQuery = User::where('role', 'employee');
        if ($dealershipId) {
            $employeesQuery->where('dealership_id', $dealershipId);
        }
        $employees = $employeesQuery->get();

        $employeesPerformance = $employees->map(function ($employee) use ($from, $to, $nowUtc, $applyTaskFilter) {
            // Задачи, назначенные этому сотруднику
            $userTasksQuery = Task::whereHas('assignedUsers', function ($q) use ($employee) {
                $q->where('user_id', $employee->id);
            })->whereBetween('created_at', [$from, $to]);

            $userTasks = (clone $userTasksQuery)->count();

            // Выполненные - есть completed response от этого пользователя
            $userCompleted = (clone $userTasksQuery)
                ->whereHas('responses', function ($q) use ($employee) {
                    $q->where('user_id', $employee->id)
                      ->where('status', 'completed');
                })
                ->count();

            // Просроченные - дедлайн прошёл, нет completed response от этого пользователя
            $userOverdue = (clone $userTasksQuery)
                ->where('is_active', true)
                ->whereNotNull('deadline')
                ->where('deadline', '<', $nowUtc)
                ->whereDoesntHave('responses', function ($q) use ($employee) {
                    $q->where('user_id', $employee->id)
                      ->where('status', 'completed');
                })
                ->count();

            // Опоздания на смены
            $userLateShifts = Shift::where('user_id', $employee->id)
                ->whereBetween('shift_start', [$from, $to])
                ->where('late_minutes', '>', 0)
                ->count();

            // Расчёт рейтинга
            $score = 100;
            if ($userTasks > 0) {
                $score -= ($userOverdue * 5);
            }
            $score -= ($userLateShifts * 10);
            $score = max(0, min(100, $score));

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'completed_tasks' => $userCompleted,
                'overdue_tasks' => $userOverdue,
                'late_shifts' => $userLateShifts,
                'performance_score' => $score,
            ];
        })->sortByDesc('performance_score')->values();

        // === ЕЖЕДНЕВНАЯ СТАТИСТИКА ===
        $dailyStats = [];
        $current = $from->copy();
        while ($current <= $to) {
            $dayStart = TimeHelper::startOfDayUtc($current);
            $dayEnd = TimeHelper::endOfDayUtc($current);

            // Задачи, выполненные в этот день (по времени response)
            $dayCompletedQuery = Task::whereHas('responses', function ($q) use ($dayStart, $dayEnd) {
                $q->where('status', 'completed')
                  ->whereBetween('responded_at', [$dayStart, $dayEnd]);
            });
            $applyTaskFilter($dayCompletedQuery);
            $dayCompleted = $dayCompletedQuery->count();

            // Задачи, просроченные в этот день (дедлайн попал в этот день и уже прошёл)
            $dayOverdueQuery = Task::whereBetween('deadline', [$dayStart, $dayEnd])
                ->where('deadline', '<', $nowUtc)
                ->where('is_active', true)
                ->whereDoesntHave('responses', function ($q) {
                    $q->where('status', 'completed');
                });
            $applyTaskFilter($dayOverdueQuery);
            $dayOverdue = $dayOverdueQuery->count();

            // Опоздания на смены в этот день
            $dayLateShiftsQuery = Shift::whereBetween('shift_start', [$dayStart, $dayEnd])
                ->where('late_minutes', '>', 0);
            $applyShiftFilter($dayLateShiftsQuery);
            $dayLateShifts = $dayLateShiftsQuery->count();

            $dailyStats[] = [
                'date' => $current->setTimezone(TimeHelper::USER_TIMEZONE)->format('Y-m-d'),
                'completed' => $dayCompleted,
                'overdue' => $dayOverdue,
                'late_shifts' => $dayLateShifts,
            ];
            $current->addDay();
        }

        // === ТОП ПРОБЛЕМ ===
        $topIssues = [];
        if ($overdueTasks > 0) {
            $topIssues[] = [
                'issue_type' => 'overdue_tasks',
                'count' => $overdueTasks,
                'description' => 'Просроченные задачи',
            ];
        }
        if ($lateShifts > 0) {
            $topIssues[] = [
                'issue_type' => 'late_shifts',
                'count' => $lateShifts,
                'description' => 'Опоздания на смены',
            ];
        }
        if ($postponedTasks > 0) {
            $topIssues[] = [
                'issue_type' => 'frequent_postponements',
                'count' => $postponedTasks,
                'description' => 'Частые переносы задач',
            ];
        }
        usort($topIssues, fn ($a, $b) => $b['count'] <=> $a['count']);

        return response()->json([
            'period' => $from->setTimezone(TimeHelper::USER_TIMEZONE)->format('Y-m-d') . ' - ' . $to->setTimezone(TimeHelper::USER_TIMEZONE)->format('Y-m-d'),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'overdue_tasks' => $overdueTasks,
                'postponed_tasks' => $postponedTasks,
                'total_shifts' => $totalShifts,
                'late_shifts' => $lateShifts,
                'total_replacements' => $totalReplacements,
            ],
            'tasks_by_status' => $tasksByStatus,
            'employees_performance' => $employeesPerformance,
            'daily_stats' => $dailyStats,
            'top_issues' => $topIssues,
        ]);
    }

    /**
     * Вычисляет статус задачи (копия логики из Task::getStatusAttribute для консистентности)
     */
    private function calculateTaskStatus(Task $task, $nowUtc): string
    {
        $responses = $task->responses;
        $assignments = $task->assignments;
        $hasDeadline = $task->deadline !== null;
        $deadlinePassed = $hasDeadline && $task->deadline->lt($nowUtc);

        $isCompleted = false;
        $completedLate = false;

        if ($task->task_type === 'group') {
            $assignedUserIds = $assignments->pluck('user_id')->unique()->values()->toArray();
            $completedResponses = $responses->where('status', 'completed');
            $completedUserIds = $completedResponses->pluck('user_id')->unique()->values()->toArray();

            if (count($assignedUserIds) > 0 && count(array_diff($assignedUserIds, $completedUserIds)) === 0) {
                $isCompleted = true;

                if ($hasDeadline) {
                    foreach ($completedResponses as $response) {
                        if ($response->responded_at && $response->responded_at->gt($task->deadline)) {
                            $completedLate = true;
                            break;
                        }
                    }
                }
            }
        } else {
            $completedResponse = $responses->firstWhere('status', 'completed');
            if ($completedResponse) {
                $isCompleted = true;

                if ($hasDeadline && $completedResponse->responded_at && $completedResponse->responded_at->gt($task->deadline)) {
                    $completedLate = true;
                }
            }
        }

        if ($isCompleted) {
            return $completedLate ? 'completed_late' : 'completed';
        }

        if ($task->task_type === 'group') {
            $pendingReviewUserIds = $responses->where('status', 'pending_review')->pluck('user_id')->unique()->values()->toArray();
            if (count($pendingReviewUserIds) > 0) {
                return 'pending_review';
            }

            $acknowledgedUserIds = $responses->where('status', 'acknowledged')->pluck('user_id')->unique()->values()->toArray();
            if (count($acknowledgedUserIds) > 0) {
                return 'acknowledged';
            }
        } else {
            if ($responses->contains('status', 'pending_review')) {
                return 'pending_review';
            }

            if ($responses->contains('status', 'acknowledged')) {
                return 'acknowledged';
            }
        }

        if ($task->is_active && $deadlinePassed) {
            return 'overdue';
        }

        return 'pending';
    }
}
