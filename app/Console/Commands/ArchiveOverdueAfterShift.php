<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shift;
use App\Models\Task;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Archive overdue tasks after shift closes.
 *
 * This command finds shifts that were closed N hours ago and archives
 * any overdue tasks that had their deadline during that shift.
 */
class ArchiveOverdueAfterShift extends Command
{
    protected $signature = 'tasks:archive-overdue-after-shift
                            {--force : Force archiving without time check}
                            {--dry-run : Show what would be archived without making changes}';

    protected $description = 'Archive overdue tasks N hours after their target shift closes';

    public function handle(SettingsService $settingsService): int
    {
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $now = Carbon::now('Asia/Yekaterinburg');

        $this->info("Current time: {$now->format('Y-m-d H:i:s')}");

        if ($dryRun) {
            $this->warn("DRY RUN MODE - no changes will be made");
        }

        $archivedCount = 0;

        // Get all dealerships with closed shifts that need to be processed
        $closedShifts = Shift::whereNotNull('shift_end')
            ->where(function ($query) {
                $query->whereNull('archived_tasks_processed')
                      ->orWhere('archived_tasks_processed', false);
            })
            ->orderBy('shift_end', 'asc')
            ->get();

        $this->info("Found {$closedShifts->count()} shifts to check");

        foreach ($closedShifts as $shift) {
            $hoursAfter = (int) $settingsService->getSettingWithFallback(
                'archive_overdue_hours_after_shift',
                $shift->dealership_id,
                2
            );

            $shiftEndTime = Carbon::parse($shift->shift_end, 'Asia/Yekaterinburg');
            $archiveAfter = $shiftEndTime->copy()->addHours($hoursAfter);

            // Check if enough time has passed since shift closed
            if (!$force && $now->lt($archiveAfter)) {
                $this->line("  Shift #{$shift->id} closed at {$shiftEndTime->format('H:i')} - waiting until {$archiveAfter->format('H:i')} to archive");
                continue;
            }

            $this->info("  Processing shift #{$shift->id} (closed: {$shiftEndTime->format('Y-m-d H:i')})");

            // Find overdue tasks for this dealership that had deadline during/before this shift
            $overdueTasks = Task::where('dealership_id', $shift->dealership_id)
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->whereNotNull('deadline')
                ->where('deadline', '<=', $shift->shift_end)
                ->whereDoesntHave('responses', function ($q) {
                    $q->whereIn('status', ['completed', 'completed_late']);
                })
                ->get();

            foreach ($overdueTasks as $task) {
                $this->line("    - Archiving task #{$task->id}: {$task->title}");

                if (!$dryRun) {
                    $task->update([
                        'is_active' => false,
                        'archived_at' => Carbon::now(),
                        'archive_reason' => 'expired_after_shift',
                    ]);
                }

                $archivedCount++;
            }

            // Mark shift as processed
            if (!$dryRun && $overdueTasks->count() >= 0) {
                // We need to add this column or use a different tracking mechanism
                // For now, we'll just log it
                $shift->timestamps = false;
                $shift->archived_tasks_processed = true;
                $shift->save();
            }
        }

        if ($archivedCount > 0) {
            $this->info("Archived {$archivedCount} overdue tasks");
            Log::info("ArchiveOverdueAfterShift: Archived {$archivedCount} overdue tasks");
        } else {
            $this->info("No tasks to archive");
        }

        return Command::SUCCESS;
    }
}
