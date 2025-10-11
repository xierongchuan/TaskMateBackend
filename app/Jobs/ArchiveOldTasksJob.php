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
use Illuminate\Support\Facades\Log;

/**
 * Job to archive old completed tasks after N days (configurable, default 30)
 */
class ArchiveOldTasksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(SettingsService $settingsService): void
    {
        try {
            $now = Carbon::now();
            $archivedCount = 0;

            // Get all dealerships and archive their tasks
            $dealerships = \App\Models\AutoDealership::all();

            foreach ($dealerships as $dealership) {
                $archiveDays = $settingsService->getTaskArchiveDays($dealership->id);
                $archiveDate = $now->copy()->subDays($archiveDays);

                // Find completed tasks older than archive date
                $tasksToArchive = Task::where('dealership_id', $dealership->id)
                    ->whereNull('archived_at')
                    ->where('created_at', '<', $archiveDate)
                    ->whereHas('responses', function ($query) {
                        $query->where('status', 'completed');
                    })
                    ->get();

                foreach ($tasksToArchive as $task) {
                    // Check if all assignments are completed
                    $allCompleted = $task->assignments()
                        ->whereDoesntHave('responses', function ($query) {
                            $query->where('status', 'completed');
                        })
                        ->count() === 0;

                    if ($allCompleted) {
                        $task->archived_at = $now;
                        $task->save();
                        $archivedCount++;
                    }
                }
            }

            // Also archive tasks with no dealership
            $globalArchiveDays = $settingsService->getTaskArchiveDays(null);
            $globalArchiveDate = $now->copy()->subDays($globalArchiveDays);

            $globalTasksToArchive = Task::whereNull('dealership_id')
                ->whereNull('archived_at')
                ->where('created_at', '<', $globalArchiveDate)
                ->whereHas('responses', function ($query) {
                    $query->where('status', 'completed');
                })
                ->get();

            foreach ($globalTasksToArchive as $task) {
                $allCompleted = $task->assignments()
                    ->whereDoesntHave('responses', function ($query) {
                        $query->where('status', 'completed');
                    })
                    ->count() === 0;

                if ($allCompleted) {
                    $task->archived_at = $now;
                    $task->save();
                    $archivedCount++;
                }
            }

            Log::info('ArchiveOldTasksJob completed', [
                'archived_count' => $archivedCount,
            ]);
        } catch (\Throwable $e) {
            Log::error('ArchiveOldTasksJob failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
