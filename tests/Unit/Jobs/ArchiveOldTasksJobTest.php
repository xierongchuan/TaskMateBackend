<?php

declare(strict_types=1);

use App\Jobs\ArchiveOldTasksJob;
use App\Models\AutoDealership;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;

beforeEach(function () {
    $this->dealership = AutoDealership::factory()->create([
        'name' => 'Test Dealership',
        'is_active' => true,
    ]);

    $this->manager = User::factory()->create([
        'role' => 'manager',
        'dealership_id' => $this->dealership->id,
    ]);

    $this->employee = User::factory()->create([
        'role' => 'employee',
        'dealership_id' => $this->dealership->id,
    ]);

    // Set archive threshold to 30 days
    Setting::factory()->create([
        'key' => 'task_archive_days',
        'value' => '30',
        'type' => 'integer',
        'dealership_id' => $this->dealership->id,
    ]);
});

test('old completed tasks are archived', function () {
    $oldTask = Task::factory()->create([
        'title' => 'Old Task',
        'dealership_id' => $this->dealership->id,
        'creator_id' => $this->manager->id,
        'is_active' => true, // Must be active to be archived
        'created_at' => Carbon::now()->subDays(35),
        'updated_at' => Carbon::now()->subDays(35),
    ]);

    $assignment = TaskAssignment::create([
        'task_id' => $oldTask->id,
        'user_id' => $this->employee->id,
    ]);

    TaskResponse::create([
        'task_id' => $oldTask->id,
        'user_id' => $this->employee->id,
        'status' => 'completed',
        'responded_at' => Carbon::now()->subDays(35),
        'created_at' => Carbon::now()->subDays(35), // For archive mode 'days' check
    ]);

    $job = new ArchiveOldTasksJob();
    $settingsService = app(SettingsService::class);
    $job->handle($settingsService);

    expect($oldTask->fresh()->archived_at)->not->toBeNull();
    expect($oldTask->fresh()->archive_reason)->toBe('completed');
});

test('recent tasks are not archived', function () {
    $recentTask = Task::factory()->create([
        'title' => 'Recent Task',
        'dealership_id' => $this->dealership->id,
        'creator_id' => $this->manager->id,
        'is_active' => true,
        'created_at' => Carbon::now()->subDays(5),
    ]);

    $assignment = TaskAssignment::create([
        'task_id' => $recentTask->id,
        'user_id' => $this->employee->id,
    ]);

    TaskResponse::create([
        'task_id' => $recentTask->id,
        'user_id' => $this->employee->id,
        'status' => 'completed',
        'responded_at' => Carbon::now()->subDays(5),
        'created_at' => Carbon::now()->subDays(5), // Recent completion
    ]);

    $job = new ArchiveOldTasksJob();
    $settingsService = app(SettingsService::class);
    $job->handle($settingsService);

    expect($recentTask->fresh()->archived_at)->toBeNull();
});

test('incomplete old tasks are not archived', function () {
    $incompleteTask = Task::factory()->create([
        'title' => 'Incomplete Task',
        'dealership_id' => $this->dealership->id,
        'creator_id' => $this->manager->id,
        'is_active' => true,
        'created_at' => Carbon::now()->subDays(35),
        'updated_at' => Carbon::now()->subDays(35),
    ]);

    $assignment = TaskAssignment::create([
        'task_id' => $incompleteTask->id,
        'user_id' => $this->employee->id,
    ]);

    // No response or pending response
    TaskResponse::create([
        'task_id' => $incompleteTask->id,
        'user_id' => $this->employee->id,
        'status' => 'postponed',
        'responded_at' => Carbon::now()->subDays(35),
    ]);

    $initialArchivedAt = $incompleteTask->archived_at;

    $job = new ArchiveOldTasksJob();
    $settingsService = app(SettingsService::class);
    $job->handle($settingsService);

    // Should remain unarchived
    expect($incompleteTask->fresh()->archived_at)->toBe($initialArchivedAt);
});

test('archive threshold is configurable per dealership', function () {
    // Create another dealership with different threshold
    $dealership2 = AutoDealership::factory()->create([
        'name' => 'Dealership 2',
        'is_active' => true,
    ]);

    Setting::factory()->create([
        'key' => 'task_archive_days',
        'value' => '60',
        'type' => 'integer',
        'dealership_id' => $dealership2->id,
    ]);

    $task40DaysOld = Task::factory()->create([
        'title' => '40 Days Old Task',
        'dealership_id' => $dealership2->id,
        'creator_id' => $this->manager->id,
        'is_active' => true, // Must be active
        'created_at' => Carbon::now()->subDays(40),
        'updated_at' => Carbon::now()->subDays(40),
    ]);

    $assignment = TaskAssignment::create([
        'task_id' => $task40DaysOld->id,
        'user_id' => $this->employee->id,
    ]);

    TaskResponse::create([
        'task_id' => $task40DaysOld->id,
        'user_id' => $this->employee->id,
        'status' => 'completed',
        'responded_at' => Carbon::now()->subDays(40),
        'created_at' => Carbon::now()->subDays(40),
    ]);

    $job = new ArchiveOldTasksJob();
    $settingsService = app(SettingsService::class);
    $job->handle($settingsService);

    // Should not be archived (threshold is 60 days for this dealership)
    expect($task40DaysOld->fresh()->archived_at)->toBeNull();
});

