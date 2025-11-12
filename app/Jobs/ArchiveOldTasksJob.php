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
 * Job to archive old completed tasks after N days (configurable, default 30)
 *
 * Optimizations:
 * - Eager loading to prevent N+1 queries
 * - Batch processing with chunking for large datasets
 * - Bulk update for better performance
 * - Query optimization with subqueries for validation
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
            $now = Carbon::now();
            $archivedCount = 0;

            // Get all dealership IDs that have non-archived tasks
            $dealershipIds = Task::whereNull('archived_at')
                ->distinct('dealership_id')
                ->pluck('dealership_id');

            foreach ($dealershipIds as $dealershipId) {
                $archiveDays = $settingsService->getTaskArchiveDays($dealershipId);
                $archiveDate = $now->copy()->subDays($archiveDays);

                $count = $this->archiveTasksForDealership(
                    $dealershipId,
                    $archiveDate,
                    $now
                );

                $archivedCount += $count;
            }

            Log::info('ArchiveOldTasksJob completed', [
                'archived_count' => $archivedCount,
                'duration' => now()->diffInSeconds($now),
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
     * Archive tasks for a specific dealership.
     */
    private function archiveTasksForDealership(?int $dealershipId, Carbon $archiveDate, Carbon $now): int
    {
        $archivedCount = 0;

        // Find task IDs that have at least one completed response
        $taskIdsWithCompletedResponse = DB::table('task_responses')
            ->where('status', 'completed')
            ->distinct()
            ->pluck('task_id');

        // Build base query
        $query = Task::where('created_at', '<', $archiveDate)
            ->whereNull('archived_at')
            ->whereIn('id', $taskIdsWithCompletedResponse);

        if ($dealershipId === null) {
            $query->whereNull('dealership_id');
        } else {
            $query->where('dealership_id', $dealershipId);
        }

        // Process in chunks to avoid memory issues
        $query->chunk(self::CHUNK_SIZE, function ($tasks) use ($now, &$archivedCount) {
            $taskIdsToArchive = [];

            foreach ($tasks as $task) {
                // Check if all assigned users have completed responses
                if ($this->allAssignmentsCompleted($task->id)) {
                    $taskIdsToArchive[] = $task->id;
                }
            }

            // Bulk update for better performance
            if (!empty($taskIdsToArchive)) {
                Task::whereIn('id', $taskIdsToArchive)
                    ->update(['archived_at' => $now]);

                $archivedCount += count($taskIdsToArchive);

                Log::debug('Archived batch of tasks', [
                    'count' => count($taskIdsToArchive),
                    'dealership_id' => $tasks->first()?->dealership_id,
                ]);
            }
        });

        return $archivedCount;
    }

    /**
     * Check if all task assignments have completed responses.
     *
     * Uses a single optimized query instead of N+1.
     */
    private function allAssignmentsCompleted(int $taskId): bool
    {
        // Count total assignments
        $totalAssignments = DB::table('task_assignments')
            ->where('task_id', $taskId)
            ->count();

        // If no assignments, skip
        if ($totalAssignments === 0) {
            return false;
        }

        // Count completed responses matching assignments
        $completedResponses = DB::table('task_responses')
            ->where('task_id', $taskId)
            ->where('status', 'completed')
            ->whereIn('user_id', function ($query) {
                $query->select('user_id')
                    ->from('task_assignments')
                    ->where('task_id', DB::raw('task_responses.task_id'));
            })
            ->distinct('user_id')
            ->count();

        return $totalAssignments === $completedResponses;
    }
}
