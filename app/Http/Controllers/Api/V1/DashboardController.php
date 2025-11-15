<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * Live dashboard controller for managers
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $dealershipId = $request->query('dealership_id') !== null && $request->query('dealership_id') !== '' ? (int) $request->query('dealership_id') : null;

        $currentShifts = $this->getCurrentShifts($dealershipId);
        $taskStats = $this->getTaskStatistics($dealershipId);
        $lateShifts = $this->getLateShifts($dealershipId);
        $replacements = $this->getReplacements($dealershipId);

        return response()->json([
            'current_shifts' => $currentShifts,
            'task_statistics' => $taskStats,
            'late_shifts' => $lateShifts,
            'replacements' => $replacements,
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    private function getCurrentShifts($dealershipId = null)
    {
        $query = Shift::with(['user', 'dealership', 'replacement.replacingUser', 'replacement.replacedUser'])
            ->where('status', 'open')
            ->whereNull('shift_end');

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->orderBy('shift_start')->get()->map(function ($shift) {
            return [
                'id' => $shift->id,
                'user' => [
                    'id' => $shift->user->id,
                    'name' => $shift->user->full_name,
                    'role' => $shift->user->role,
                ],
                'dealership' => [
                    'id' => $shift->dealership->id,
                    'name' => $shift->dealership->name,
                ],
                'shift_start' => $shift->shift_start->toIso8601String(),
                'late_minutes' => $shift->late_minutes,
                'is_replacement' => $shift->replacement !== null,
                'replacement_info' => $shift->replacement ? [
                    'replaced_user' => $shift->replacement->replacedUser->full_name,
                    'reason' => $shift->replacement->reason,
                ] : null,
            ];
        });
    }

    private function getTaskStatistics($dealershipId = null)
    {
        $query = Task::where('is_active', true);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        $totalTasks = $query->count();

        $completedToday = TaskResponse::where('status', 'completed')
            ->whereDate('responded_at', Carbon::today())
            ->whereHas('task', function ($q) use ($dealershipId) {
                if ($dealershipId) {
                    $q->where('dealership_id', $dealershipId);
                }
            })
            ->count();

        $overdue = (clone $query)
            ->where('deadline', '<', Carbon::now())
            ->whereDoesntHave('responses', function ($q) {
                $q->where('status', 'completed');
            })
            ->count();

        $postponed = (clone $query)
            ->where('postpone_count', '>', 0)
            ->count();

        return [
            'total_active' => $totalTasks,
            'completed_today' => $completedToday,
            'overdue' => $overdue,
            'postponed' => $postponed,
        ];
    }

    private function getLateShifts($dealershipId = null)
    {
        $query = Shift::with(['user', 'dealership'])
            ->whereDate('shift_start', Carbon::today())
            ->where('late_minutes', '>', 0);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->orderByDesc('late_minutes')
            ->limit(10)
            ->get()
            ->map(function ($shift) {
                return [
                    'user' => $shift->user->full_name,
                    'dealership' => $shift->dealership->name,
                    'late_minutes' => $shift->late_minutes,
                    'shift_start' => $shift->shift_start->toIso8601String(),
                ];
            });
    }

    private function getReplacements($dealershipId = null)
    {
        $query = Shift::with(['replacement.replacingUser', 'replacement.replacedUser', 'dealership'])
            ->has('replacement')
            ->whereDate('shift_start', Carbon::today());

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->get()->map(function ($shift) {
            return [
                'replacing_user' => $shift->replacement->replacingUser->full_name,
                'replaced_user' => $shift->replacement->replacedUser->full_name,
                'reason' => $shift->replacement->reason,
                'dealership' => $shift->dealership->name,
                'shift_start' => $shift->shift_start->toIso8601String(),
            ];
        });
    }
}
