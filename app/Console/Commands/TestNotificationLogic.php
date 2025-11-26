<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\User;
use App\Models\NotificationSetting;
use App\Services\TaskNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestNotificationLogic extends Command
{
    protected $signature = 'test:notification-logic';
    protected $description = 'Test notification logic with new settings';

    public function handle(TaskNotificationService $service)
    {
        $this->info('Starting notification logic test...');

        // 1. Create User
        $user = User::factory()->create([
            'role' => 'employee',
            'telegram_id' => '123456789'
        ]);
        $this->info("Created user: {$user->id} (Role: {$user->role})");

        // 2. Create Task with custom settings (override deadline offset to 15 mins)
        $now = Carbon::now('UTC');
        $deadline = $now->copy()->addMinutes(15);

        $task = Task::create([
            'title' => 'Test Task',
            'creator_id' => $user->id,
            'task_type' => 'individual',
            'response_type' => 'acknowledge',
            'deadline' => $deadline,
            'is_active' => true,
            'notification_settings' => [
                NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN => [
                    'enabled' => true,
                    'offset' => 15 // Override default 30
                ]
            ]
        ]);

        // Assign user
        $task->assignments()->create(['user_id' => $user->id]);

        $this->info("Created task: {$task->id} with deadline in 15 mins and offset 15 mins");

        // 3. Test Upcoming Deadline Logic
        // Should match because offset is 15 and deadline is in 15 mins
        $results = $service->notifyAboutUpcomingDeadlines();

        $this->info("Upcoming Deadline Results: " . json_encode($results));

        if ($results['tasks_processed'] > 0) {
            $this->info('SUCCESS: Task was processed based on custom offset.');
        } else {
            $this->error('FAILURE: Task was NOT processed.');
        }

        // 4. Test Role Filtering
        // Set dealership setting to only allow 'manager'
        if ($task->dealership_id) {
            NotificationSetting::updateOrCreate(
                ['dealership_id' => $task->dealership_id, 'channel_type' => NotificationSetting::CHANNEL_TASK_DEADLINE_30MIN],
                ['recipient_roles' => ['manager']]
            );

            $this->info("Updated dealership settings to restrict to 'manager' only.");

            // Reset notification history to allow sending again (if it was sent)
            \App\Models\TaskNotification::where('task_id', $task->id)->delete();

            $results = $service->notifyAboutUpcomingDeadlines();
            $this->info("Upcoming Deadline Results (Role Filtered): " . json_encode($results));

            if ($results['notifications_sent'] === 0) {
                 $this->info('SUCCESS: Notification was blocked due to role mismatch.');
            } else {
                 $this->error('FAILURE: Notification was sent despite role mismatch.');
            }
        } else {
            $this->warn('Skipping role test because task has no dealership.');
        }

        // Cleanup
        $task->delete();
        $user->delete();
    }
}
