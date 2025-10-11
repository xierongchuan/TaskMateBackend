<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Archive tasks older than configured threshold (default 30 days)
 */
class ArchiveOldTasks extends Command
{
    protected $signature = 'tasks:archive-old
                          {--days= : Override default archive threshold in days}
                          {--dry-run : Show what would be archived without actually archiving}';

    protected $description = 'Archive completed tasks older than configured threshold (default 30 days)';

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

        // Get unique dealership IDs to process settings per dealership
        $dealershipIds = Task::whereNull('archived_at')
            ->distinct()
            ->pluck('dealership_id');

        foreach ($dealershipIds as $dealershipId) {
            try {
                $archiveDays = $customDays
                    ? (int) $customDays
                    : $this->settingsService->getTaskArchiveDays($dealershipId);

                $threshold = Carbon::now()->subDays($archiveDays);

                $this->info("Processing dealership #{$dealershipId} (threshold: {$archiveDays} days)");

                // Get tasks that are completed and older than threshold
                $tasksToArchive = Task::where('dealership_id', $dealershipId)
                    ->whereNull('archived_at')
                    ->where('is_active', false)
                    ->where(function ($query) use ($threshold) {
                        // Archive if:
                        // 1. All responses are completed and last response is old enough
                        // 2. OR task has a deadline that passed the threshold
                        // 3. OR task was updated long ago and is inactive
                        $query->where('updated_at', '<', $threshold)
                            ->orWhere(function ($q) use ($threshold) {
                                $q->whereNotNull('deadline')
                                  ->where('deadline', '<', $threshold);
                            });
                    })
                    ->get();

                foreach ($tasksToArchive as $task) {
                    // Check if all responses are completed
                    $allCompleted = $task->responses()
                        ->whereIn('status', ['completed', 'acknowledged'])
                        ->count() === $task->responses()->count();

                    if ($allCompleted || $task->deadline?->lessThan($threshold)) {
                        if (!$dryRun) {
                            $task->archived_at = Carbon::now();
                            $task->save();
                        }

                        $archivedCount++;
                        $this->line("  - Archived task #{$task->id}: {$task->title}");

                        Log::info("Archived task #{$task->id}", [
                            'task_id' => $task->id,
                            'dealership_id' => $dealershipId,
                            'dry_run' => $dryRun,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $this->error("Error processing dealership #{$dealershipId}: " . $e->getMessage());
                Log::error("Error archiving tasks for dealership #{$dealershipId}: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }

        if ($dryRun) {
            $this->info("DRY RUN: Would have archived {$archivedCount} tasks.");
        } else {
            $this->info("Successfully archived {$archivedCount} tasks.");
        }

        return self::SUCCESS;
    }
}
