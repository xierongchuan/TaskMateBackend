<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveCompletedTasks extends Command
{
    protected $signature = 'tasks:archive-completed {--force : Force archiving even if not the configured day}';

    protected $description = 'Archive completed and overdue tasks based on auto_archive_day_of_week setting';

    public function handle(SettingsService $settingsService): int
    {
        $today = Carbon::now('Asia/Yekaterinburg')->dayOfWeek;
        $todayConverted = $today === 0 ? 7 : $today;

        $this->info("Current day of week: $todayConverted (" . Carbon::now('Asia/Yekaterinburg')->format('l') . ")");

        $dealershipSettings = DB::table('settings')
            ->where('key', 'auto_archive_day_of_week')
            ->whereNotNull('dealership_id')
            ->get();

        $globalSetting = $settingsService->get('auto_archive_day_of_week');
        $archivedCount = 0;

        foreach ($dealershipSettings as $setting) {
            $archiveDay = (int) $setting->value;

            if ($archiveDay === 0) {
                continue;
            }

            if ($this->option('force') || $archiveDay === $todayConverted) {
                $this->info("Archiving tasks for dealership {$setting->dealership_id}...");
                $count = $this->archiveTasksForDealership((int) $setting->dealership_id);
                $archivedCount += $count;
                $this->info("  Archived $count tasks");
            }
        }

        if ($globalSetting && $globalSetting > 0) {
            if ($this->option('force') || (int) $globalSetting === $todayConverted) {
                $this->info("Archiving tasks without dealership (global setting)...");
                $count = $this->archiveTasksForDealership(null);
                $archivedCount += $count;
                $this->info("  Archived $count tasks");
            }
        }

        if ($archivedCount > 0) {
            $this->info("Total archived: $archivedCount tasks");
            Log::info("Auto-archived $archivedCount tasks");
        } else {
            $this->info("No tasks to archive today");
        }

        return Command::SUCCESS;
    }

    private function archiveTasksForDealership(?int $dealershipId): int
    {
        // Get tasks that are active and not already archived
        $query = Task::query()
            ->where('is_active', true)
            ->whereNull('archived_at');

        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        } else {
            $query->whereNull('dealership_id');
        }

        // Archive tasks based on their computed status
        $cutoffDate = Carbon::now('Asia/Yekaterinburg')->subDay();
        $tasksToArchive = $query->with(['responses', 'assignments'])->get();

        $archivedCount = 0;

        foreach ($tasksToArchive as $task) {
            $status = $task->status;
            $shouldArchive = false;
            $archiveReason = null;

            // Archive completed tasks that were completed more than 1 day ago
            if ($status === 'completed') {
                // Check last response for completion time
                $lastResponse = $task->responses()
                    ->where('status', 'completed')
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($lastResponse && Carbon::parse($lastResponse->created_at)->lt($cutoffDate)) {
                    $shouldArchive = true;
                    $archiveReason = 'completed';
                }
            }

            // Archive overdue tasks that have passed deadline by more than 1 day
            if ($status === 'overdue' && $task->deadline) {
                if (Carbon::parse($task->deadline)->lt($cutoffDate)) {
                    $shouldArchive = true;
                    $archiveReason = 'expired';
                }
            }

            if ($shouldArchive) {
                $task->update([
                    'is_active' => false,
                    'archived_at' => Carbon::now(),
                    'archive_reason' => $archiveReason,
                ]);
                $archivedCount++;
            }
        }

        return $archivedCount;
    }
}
