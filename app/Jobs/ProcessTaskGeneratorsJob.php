<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskGenerator;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to process task generators and create daily task instances.
 *
 * This job runs periodically (e.g., every hour) and:
 * 1. Finds all active generators that should create a task today
 * 2. Creates task instances with proper assignments
 * 3. Updates the last_generated_at timestamp
 */
class ProcessTaskGeneratorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('task_generators');
    }

    public function handle(): void
    {
        $now = Carbon::now('Asia/Yekaterinburg');
        Log::info('ProcessTaskGeneratorsJob started', ['time' => $now->format('Y-m-d H:i:s')]);

        $generators = TaskGenerator::where('is_active', true)
            ->whereDate('start_date', '<=', $now->toDateString())
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', $now->toDateString());
            })
            ->get();

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($generators as $generator) {
            try {
                if ($generator->shouldGenerateToday($now)) {
                    $this->createTaskFromGenerator($generator, $now);
                    $createdCount++;
                } else {
                    $skippedCount++;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to process task generator', [
                    'generator_id' => $generator->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('ProcessTaskGeneratorsJob completed', [
            'created' => $createdCount,
            'skipped' => $skippedCount,
            'total_generators' => $generators->count(),
        ]);
    }

    /**
     * Create a task instance from a generator.
     */
    private function createTaskFromGenerator(TaskGenerator $generator, Carbon $now): void
    {
        DB::transaction(function () use ($generator, $now) {
            // Calculate appear time and deadline for today
            $appearTime = $generator->getAppearTimeForDate($now);
            $deadlineTime = $generator->getDeadlineTimeForDate($now);

            // Create the task
            // Note: Task model mutators expect time in Asia/Yekaterinburg and convert to UTC.
            // So we pass the local time directly without manual UTC conversion.
            $task = Task::create([
                'generator_id' => $generator->id,
                'title' => $generator->title,
                'description' => $generator->description,
                'comment' => $generator->comment,
                'creator_id' => $generator->creator_id,
                'dealership_id' => $generator->dealership_id,
                'appear_date' => $appearTime->copy()->format('Y-m-d H:i:s'),  // Pass as local time string
                'deadline' => $deadlineTime->copy()->format('Y-m-d H:i:s'),   // Mutator converts to UTC
                'scheduled_date' => $now->copy()->startOfDay()->setTimezone('UTC'),
                'task_type' => $generator->task_type,
                'response_type' => $generator->response_type,
                'priority' => $generator->priority,
                'tags' => $generator->tags,
                'notification_settings' => $generator->notification_settings,
                'is_active' => true,
                'recurrence' => 'none', // Individual task instances are not recurring
            ]);

            // Copy assignments from generator
            $assignments = $generator->assignments;
            foreach ($assignments as $assignment) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $assignment->user_id,
                ]);
            }

            // Update generator's last_generated_at
            $generator->update([
                'last_generated_at' => $now->copy()->setTimezone('UTC'),
            ]);

            Log::info('Created task from generator', [
                'generator_id' => $generator->id,
                'task_id' => $task->id,
                'title' => $task->title,
                'scheduled_date' => $now->toDateString(),
            ]);
        });
    }
}
