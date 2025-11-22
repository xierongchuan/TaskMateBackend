<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (! $dateFrom || ! $dateTo) {
            return response()->json(['message' => 'Parameters date_from and date_to are required'], 400);
        }

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();

        // Summary Statistics
        $totalTasks = Task::whereBetween('created_at', [$from, $to])->count();

        // Completed tasks: tasks created in period and completed (is_active = false)
        // Note: logic might vary depending on how completion is tracked.
        // Assuming is_active=false means completed for now, or we check status if available.
        // Based on Task model, there isn't a specific 'status' field like 'completed',
        // but there is 'is_active' and 'archived_at'.
        // Let's assume non-active tasks are completed.
        $completedTasks = Task::whereBetween('created_at', [$from, $to])
            ->where('is_active', false)
            ->count();

        // Overdue tasks: tasks with deadline in period and deadline < now and is_active = true
        $overdueTasks = Task::whereBetween('deadline', [$from, $to])
            ->where('deadline', '<', Carbon::now())
            ->where('is_active', true)
            ->count();

        $postponedTasks = Task::whereBetween('created_at', [$from, $to])
            ->where('postpone_count', '>', 0)
            ->count();

        $totalShifts = Shift::whereBetween('shift_start', [$from, $to])->count();

        $lateShifts = Shift::whereBetween('shift_start', [$from, $to])
            ->where('late_minutes', '>', 0)
            ->count();

        // Replacements logic (assuming Shift has replacement relationship)
        // We can count shifts that have a replacement
        $totalReplacements = Shift::whereBetween('shift_start', [$from, $to])
            ->has('replacement')
            ->count();

        // Tasks by Status
        // Since we don't have a strict status enum, we derive it.
        // Active, Completed (inactive), Overdue (active + past deadline)
        // This is a simplification.
        $activeTasksCount = Task::whereBetween('created_at', [$from, $to])
            ->where('is_active', true)
            ->where(function($q) {
                $q->whereNull('deadline')->orWhere('deadline', '>=', Carbon::now());
            })
            ->count();

        $tasksByStatus = [
            [
                'status' => 'completed',
                'count' => $completedTasks,
                'percentage' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0
            ],
            [
                'status' => 'overdue',
                'count' => $overdueTasks,
                'percentage' => $totalTasks > 0 ? round(($overdueTasks / $totalTasks) * 100, 1) : 0
            ],
            [
                'status' => 'active', // treating others as active
                'count' => $activeTasksCount,
                'percentage' => $totalTasks > 0 ? round(($activeTasksCount / $totalTasks) * 100, 1) : 0
            ]
        ];

        // Employee Performance
        $employees = User::where('role', '!=', 'owner')->get(); // Exclude owners from stats usually
        $employeesPerformance = $employees->map(function ($user) use ($from, $to) {
            // Base query for tasks assigned to the user
            $userTasksQuery = Task::whereHas('assignedUsers', function($q) use ($user) {
                $q->where('user_id', $user->id);
            });

            $userTasks = (clone $userTasksQuery)
                ->whereBetween('created_at', [$from, $to])
                ->count();

            $userCompleted = (clone $userTasksQuery)
                ->whereBetween('created_at', [$from, $to])
                ->where('is_active', false)
                ->count();

            $userOverdue = (clone $userTasksQuery)
                ->whereBetween('deadline', [$from, $to])
                ->where('deadline', '<', Carbon::now())
                ->where('is_active', true)
                ->count();

            $userLateShifts = Shift::where('user_id', $user->id)
                ->whereBetween('shift_start', [$from, $to])
                ->where('late_minutes', '>', 0)
                ->count();

            // Simple score calculation
            $score = 100;
            if ($userTasks > 0) {
                // Penalty for overdue tasks (weighted)
                $score -= ($userOverdue * 5);
            }
            // Penalty for late shifts
            $score -= ($userLateShifts * 10);

            // Normalize score
            $score = max(0, min(100, $score));

            return [
                'employee_id' => $user->id,
                'employee_name' => $user->full_name,
                'completed_tasks' => $userCompleted,
                'overdue_tasks' => $userOverdue,
                'late_shifts' => $userLateShifts,
                'performance_score' => $score,
            ];
        })->sortByDesc('performance_score')->values();

        // Daily Stats
        $dailyStats = [];
        $current = $from->copy();
        while ($current <= $to) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();

            $dayCompleted = Task::whereBetween('created_at', [$dayStart, $dayEnd])
                ->where('is_active', false)
                ->count();

            $dayOverdue = Task::whereBetween('deadline', [$dayStart, $dayEnd])
                ->where('deadline', '<', Carbon::now())
                ->where('is_active', true)
                ->count();

            $dayLateShifts = Shift::whereBetween('shift_start', [$dayStart, $dayEnd])
                ->where('late_minutes', '>', 0)
                ->count();

            $dailyStats[] = [
                'date' => $current->format('Y-m-d'),
                'completed' => $dayCompleted,
                'overdue' => $dayOverdue,
                'late_shifts' => $dayLateShifts,
            ];
            $current->addDay();
        }

        // Top Issues
        $topIssues = [];
        if ($overdueTasks > 0) {
            $topIssues[] = [
                'issue_type' => 'overdue_tasks',
                'count' => $overdueTasks,
                'description' => 'Просроченные задачи'
            ];
        }
        if ($lateShifts > 0) {
            $topIssues[] = [
                'issue_type' => 'late_shifts',
                'count' => $lateShifts,
                'description' => 'Опоздания на смены'
            ];
        }
        if ($postponedTasks > 0) {
            $topIssues[] = [
                'issue_type' => 'frequent_postponements',
                'count' => $postponedTasks,
                'description' => 'Частые переносы задач'
            ];
        }
        // Sort by count desc
        usort($topIssues, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return response()->json([
            'period' => $from->format('Y-m-d') . ' - ' . $to->format('Y-m-d'),
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
}
