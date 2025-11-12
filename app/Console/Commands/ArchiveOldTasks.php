<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Archive tasks older than configured threshold (default 30 days)
 *
 * Optimizations:
 * - Bulk updates instead of individual saves
 * - Query optimization with subqueries
 * - Chunking for large datasets
 * - Raw SQL for better performance
 */
class ArchiveOldTasks extends Command
{
    protected $signature = 'tasks:archive-old
                          {--days= : Override default archive threshold in days}
                          {--dry-run : Show what would be archived without actually archiving}';

    protected $description = 'Archive completed tasks older than configured threshold (default 30 days)';

    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly SettingsService $settingsService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $customDays = $this->option('days');

        $this->info('Archiving old tasks...');

        if ($dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
        }

        $archivedCount = 0;
        $startTime = now();

        try {
            // Get unique dealership IDs that have non-archived tasks
            $dealershipIds = Task::whereNull('archived_at')
                ->distinct('dealership_id')
                ->pluck('dealership_id');

            foreach ($dealershipIds as $dealershipId) {
                $archiveDays = $customDays
                    ? (int) $customDays
                    : $this->settingsService->getTaskArchiveDays($dealershipId);

                $threshold = Carbon::now()->subDays($archiveDays);

                $dealershipDisplay = $dealershipId ?? 'global';
                $this->info("Processing dealership #{$dealershipDisplay} (threshold: {$archiveDays} days)");

                $count = $this->archiveTasksForDealership(
                    $dealershipId,
                    $threshold,
                    $dryRun
                );

                $archivedCount += $count;

                if ($count > 0) {
                    $this->line("  ✓ Archived <fg=green>{$count}</> tasks");
                }
            }
        } catch (\Throwable $e) {
            $this->error("Error archiving tasks: " . $e->getMessage());
            Log::error("Error archiving tasks: " . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        $duration = now()->diffInSeconds($startTime);

        if ($dryRun) {
            $msg = "DRY RUN: Would have archived <fg=cyan>{$archivedCount}</> tasks in {$duration}s.";
            $this->info("<fg=yellow>{$msg}</>");
        } else {
            $this->info("<fg=green>✓ Successfully archived {$archivedCount} tasks in {$duration}s.</>");
            Log::info('Archive old tasks completed', [
                'archived_count' => $archivedCount,
                'duration_seconds' => $duration,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Archive tasks for a specific dealership.
     */
    private function archiveTasksForDealership(?int $dealershipId, Carbon $threshold, bool $dryRun): int
    {
        $archivedCount = 0;

        // Build query to find candidate tasks
        $query = Task::where('created_at', '<', $threshold)
            ->whereNull('archived_at')
            ->where('is_active', false)
            ->where(function ($q) use ($threshold) {
                $q->where('updated_at', '<', $threshold)
                    ->orWhere(function ($sq) use ($threshold) {
                        $sq->whereNotNull('deadline')
                            ->where('deadline', '<', $threshold);
                    });
            });

        if ($dealershipId === null) {
            $query->whereNull('dealership_id');
        } else {
            $query->where('dealership_id', $dealershipId);
        }

        // Process in chunks
        $query->chunk(self::CHUNK_SIZE, function ($tasks) use ($threshold, $dryRun, &$archivedCount, $dealershipId) {
            $taskIdsToArchive = [];

            foreach ($tasks as $task) {
                if ($this->isTaskReadyForArchive($task, $threshold)) {
                    $taskIdsToArchive[] = $task->id;

                    if (!$dryRun && $this->getOutput()->isVerbose()) {
                        $this->line("    - Task #{$task->id}: {$task->title}");
                    }
                }
            }

            // Bulk update
            if (!empty($taskIdsToArchive)) {
                if (!$dryRun) {
                    Task::whereIn('id', $taskIdsToArchive)
                        ->update(['archived_at' => now()]);
                }

                $archivedCount += count($taskIdsToArchive);

                Log::debug('Archive batch', [
                    'count' => count($taskIdsToArchive),
                    'dealership_id' => $dealershipId,
                    'dry_run' => $dryRun,
                ]);
            }
        });

        return $archivedCount;
    }

    /**
     * Check if task is ready for archiving.
     *
     * Uses optimized query instead of loading full relationships.
     */
    private function isTaskReadyForArchive(Task $task, Carbon $threshold): bool
    {
        // Count total assignments
        $totalAssignments = DB::table('task_assignments')
            ->where('task_id', $task->id)
            ->count();

        // If no assignments, skip
        if ($totalAssignments === 0) {
            return false;
        }

        // Count responses with 'completed' or 'acknowledged' status
        $completedResponses = DB::table('task_responses')
            ->where('task_id', $task->id)
            ->whereIn('status', ['completed', 'acknowledged'])
            ->count();

        // All responses must be completed or acknowledged
        $totalResponses = DB::table('task_responses')
            ->where('task_id', $task->id)
            ->count();

        if ($totalResponses === 0 || $completedResponses !== $totalResponses) {
            return false;
        }

        // Additional check: deadline should have passed threshold
        if ($task->deadline && $task->deadline->greaterThan($threshold)) {
            return false;
        }

        return true;
    }
}
