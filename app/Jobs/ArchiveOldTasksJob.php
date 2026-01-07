<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to archive completed and expired tasks.
 *
 * Supports multiple archive modes per dealership:
 * - 'days': Archive completed tasks after N days
 * - 'weekend': Archive completed tasks on Sunday
 * - 'end_of_day': Archive completed tasks at end of completion day
 *
 * Also archives EXPIRED tasks (past scheduled_date without completion)
 */
class ArchiveOldTasksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK_SIZE = 500;

    /**
     * Execute the job.
     */
    public function handle(SettingsService $settingsService): void
    {
        try {
            $now = Carbon::now('Asia/Yekaterinburg');
            $archivedCompleted = 0;
            $archivedExpired = 0;

            // Get all dealership IDs that have non-archived tasks
            $dealershipIds = Task::whereNull('archived_at')
                ->distinct('dealership_id')
                ->pluck('dealership_id');

            foreach ($dealershipIds as $dealershipId) {
                // Archive completed tasks based on dealership settings
                $archivedCompleted += $this->archiveCompletedTasks(
                    $dealershipId,
                    $settingsService,
                    $now
                );

                // Archive expired tasks (past scheduled_date)
                $archivedExpired += $this->archiveExpiredTasks(
                    $dealershipId,
                    $now
                );
            }

            Log::info('ArchiveOldTasksJob completed', [
                'archived_completed' => $archivedCompleted,
                'archived_expired' => $archivedExpired,
                'total' => $archivedCompleted + $archivedExpired,
            ]);
        } catch (\Throwable $e) {
            Log::error('ArchiveOldTasksJob failed: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Archive completed tasks based on dealership archive mode.
     */
    private function archiveCompletedTasks(?int $dealershipId, SettingsService $settingsService, Carbon $now): int
    {
        $archiveMode = $settingsService->get('archive_mode', $dealershipId, 'days');
        $archiveDays = $settingsService->getTaskArchiveDays($dealershipId);

        // Determine if we should archive based on mode
        $shouldArchive = match ($archiveMode) {
            'weekend' => $now->isSunday(),
            'end_of_day' => true, // Always process, but only archive same-day completed
            'days' => true,
            default => true,
        };

        if (!$shouldArchive) {
            return 0;
        }

        $archivedCount = 0;

        // Build base query for completed tasks
        $query = Task::whereNull('archived_at')
            ->where('is_active', true);

        if ($dealershipId === null) {
            $query->whereNull('dealership_id');
        } else {
            $query->where('dealership_id', $dealershipId);
        }

        // Find completed tasks (based on responses)
        $taskIdsWithCompletedResponse = DB::table('task_responses')
            ->where('status', 'completed')
            ->distinct()
            ->pluck('task_id');

        $query->whereIn('id', $taskIdsWithCompletedResponse);

        // Apply archive mode logic
        if ($archiveMode === 'end_of_day') {
            // Archive tasks completed before today
            $query->whereHas('responses', function ($q) use ($now) {
                $q->where('status', 'completed')
                  ->whereDate('created_at', '<', $now->toDateString());
            });
        } elseif ($archiveMode === 'days') {
            // Archive tasks completed more than N days ago
            $archiveDate = $now->copy()->subDays($archiveDays)->startOfDay();
            $query->whereHas('responses', function ($q) use ($archiveDate) {
                $q->where('status', 'completed')
                  ->where('created_at', '<', $archiveDate);
            });
        }
        // For 'weekend' mode, archive all completed tasks (already checked it's Sunday)

        $query->chunk(self::CHUNK_SIZE, function ($tasks) use ($now, &$archivedCount) {
            $taskIdsToArchive = [];

            foreach ($tasks as $task) {
                // Verify all assignments are completed for group tasks
                if ($this->isTaskFullyCompleted($task)) {
                    $taskIdsToArchive[] = $task->id;
                }
            }

            if (!empty($taskIdsToArchive)) {
                Task::whereIn('id', $taskIdsToArchive)->update([
                    'archived_at' => $now->copy()->setTimezone('UTC'),
                    'archive_reason' => 'completed',
                    'is_active' => false,
                ]);

                $archivedCount += count($taskIdsToArchive);
            }
        });

        return $archivedCount;
    }

    /**
     * Archive expired tasks (past scheduled_date without completion).
     */
    private function archiveExpiredTasks(?int $dealershipId, Carbon $now): int
    {
        $yesterday = $now->copy()->subDay()->endOfDay();
        $archivedCount = 0;

        $query = Task::whereNull('archived_at')
            ->where('is_active', true)
            ->where(function ($q) use ($yesterday) {
                // Tasks with scheduled_date in the past
                $q->whereNotNull('scheduled_date')
                  ->where('scheduled_date', '<', $yesterday->setTimezone('UTC'));
            })
            ->orWhere(function ($q) use ($yesterday) {
                // Or tasks with deadline in the past (for one-time tasks without scheduled_date)
                $q->whereNull('scheduled_date')
                  ->whereNotNull('deadline')
                  ->where('deadline', '<', $yesterday->setTimezone('UTC'));
            });

        if ($dealershipId === null) {
            $query->whereNull('dealership_id');
        } else {
            $query->where('dealership_id', $dealershipId);
        }

        // Exclude already completed tasks
        $completedTaskIds = DB::table('task_responses')
            ->where('status', 'completed')
            ->distinct()
            ->pluck('task_id');

        $query->whereNotIn('id', $completedTaskIds);

        $query->chunk(self::CHUNK_SIZE, function ($tasks) use ($now, &$archivedCount) {
            $taskIds = $tasks->pluck('id')->toArray();

            if (!empty($taskIds)) {
                Task::whereIn('id', $taskIds)->update([
                    'archived_at' => $now->copy()->setTimezone('UTC'),
                    'archive_reason' => 'expired',
                    'is_active' => false,
                ]);

                $archivedCount += count($taskIds);

                Log::debug('Archived expired tasks', [
                    'count' => count($taskIds),
                ]);
            }
        });

        return $archivedCount;
    }

    /**
     * Check if a task is fully completed (all assignees for group tasks).
     */
    private function isTaskFullyCompleted(Task $task): bool
    {
        if ($task->task_type !== 'group') {
            // For individual tasks, any completion counts
            return $task->responses()->where('status', 'completed')->exists();
        }

        // For group tasks, all assignees must complete
        $totalAssignments = $task->assignments()->count();
        if ($totalAssignments === 0) {
            return false;
        }

        $completedResponses = $task->responses()
            ->where('status', 'completed')
            ->whereIn('user_id', $task->assignments()->pluck('user_id'))
            ->distinct('user_id')
            ->count();

        return $totalAssignments === $completedResponses;
    }
}
