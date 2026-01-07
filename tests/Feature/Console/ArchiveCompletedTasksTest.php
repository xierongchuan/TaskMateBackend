<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AutoDealership;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveCompletedTasksTest extends TestCase
{
    use RefreshDatabase;

    private AutoDealership $dealership;
    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE,
            'dealership_id' => $this->dealership->id,
        ]);
    }

    public function test_command_runs_successfully(): void
    {
        $this->artisan('tasks:archive-completed')
            ->expectsOutputToContain('Current day of week')
            ->assertSuccessful();
    }

    public function test_archives_completed_tasks_with_force_flag(): void
    {
        // Create task and mark as completed via response
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'individual',
        ]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
        ]);

        // Add completed response created 2 days ago
        TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Set auto_archive_day_of_week for dealership
        Setting::create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_archive_day_of_week',
            'value' => '1',
            'type' => 'integer',
        ]);

        $this->artisan('tasks:archive-completed', ['--force' => true])
            ->expectsOutputToContain('Archiving tasks for dealership')
            ->assertSuccessful();

        $task->refresh();
        $this->assertNotNull($task->archived_at);
        $this->assertFalse($task->is_active);
        $this->assertEquals('completed', $task->archive_reason);
    }

    public function test_does_not_archive_when_setting_is_disabled(): void
    {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'individual',
        ]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
        ]);

        TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Set auto_archive_day_of_week to 0 (disabled)
        Setting::create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_archive_day_of_week',
            'value' => '0',
            'type' => 'integer',
        ]);

        $this->artisan('tasks:archive-completed', ['--force' => true])
            ->expectsOutputToContain('No tasks to archive today')
            ->assertSuccessful();

        $task->refresh();
        $this->assertNull($task->archived_at);
        $this->assertTrue($task->is_active);
    }

    public function test_archives_overdue_tasks(): void
    {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'deadline' => Carbon::now()->subDays(3),
            'task_type' => 'individual',
        ]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
        ]);

        // No completed response - task will be overdue

        Setting::create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_archive_day_of_week',
            'value' => '1',
            'type' => 'integer',
        ]);

        $this->artisan('tasks:archive-completed', ['--force' => true])
            ->assertSuccessful();

        $task->refresh();
        $this->assertNotNull($task->archived_at);
        $this->assertFalse($task->is_active);
        $this->assertEquals('expired', $task->archive_reason);
    }

    public function test_does_not_archive_recent_completed_tasks(): void
    {
        // Task completed just now - should NOT be archived
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'individual',
        ]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
        ]);

        // Response created just now
        TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'created_at' => Carbon::now(),
        ]);

        Setting::create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_archive_day_of_week',
            'value' => '1',
            'type' => 'integer',
        ]);

        $this->artisan('tasks:archive-completed', ['--force' => true])
            ->assertSuccessful();

        $task->refresh();
        $this->assertNull($task->archived_at);
        $this->assertTrue($task->is_active);
    }

    public function test_respects_dealership_specific_settings(): void
    {
        $dealership2 = AutoDealership::factory()->create();

        // Task for first dealership (archiving enabled)
        $task1 = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'individual',
        ]);

        TaskAssignment::create([
            'task_id' => $task1->id,
            'user_id' => $this->employee->id,
        ]);

        TaskResponse::create([
            'task_id' => $task1->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Task for second dealership (archiving disabled)
        $task2 = Task::factory()->create([
            'dealership_id' => $dealership2->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'individual',
        ]);

        TaskAssignment::create([
            'task_id' => $task2->id,
            'user_id' => $this->employee->id,
        ]);

        TaskResponse::create([
            'task_id' => $task2->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Enable for first dealership
        Setting::create([
            'dealership_id' => $this->dealership->id,
            'key' => 'auto_archive_day_of_week',
            'value' => '1',
            'type' => 'integer',
        ]);

        // Disable for second dealership
        Setting::create([
            'dealership_id' => $dealership2->id,
            'key' => 'auto_archive_day_of_week',
            'value' => '0',
            'type' => 'integer',
        ]);

        $this->artisan('tasks:archive-completed', ['--force' => true])
            ->assertSuccessful();

        $task1->refresh();
        $task2->refresh();

        $this->assertNotNull($task1->archived_at);
        $this->assertNull($task2->archived_at);
    }
}
