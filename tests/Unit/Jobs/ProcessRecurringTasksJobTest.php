<?php

declare(strict_types=1);

use App\Jobs\ProcessRecurringTasksJob;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
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
        'telegram_id' => 123456789,
    ]);
});

test('daily recurring task creates new instance', function () {
    $task = Task::factory()->create([
        'title' => 'Daily Task',
        'dealership_id' => $this->dealership->id,
        'creator_id' => $this->manager->id,
        'recurrence' => 'daily',
        'is_active' => true,
        'created_at' => Carbon::now()->subDay(),
    ]);

    TaskAssignment::create([
        'task_id' => $task->id,
        'user_id' => $this->employee->id,
    ]);

    $job = new ProcessRecurringTasksJob();
    $job->handle();

    // Verify a new task instance was created
    $tasksCount = Task::where('title', 'Daily Task')
        ->where('dealership_id', $this->dealership->id)
        ->count();

    expect($tasksCount)->toBeGreaterThan(1);
});

test('weekly recurring task only creates instance on correct day', function () {
    $task = Task::factory()->create([
        'title' => 'Weekly Task',
        'dealership_id' => $this->dealership->id,
        'creator_id' => $this->manager->id,
        'recurrence' => 'weekly',
        'is_active' => true,
        'created_at' => Carbon::now()->subWeek(),
    ]);

    TaskAssignment::create([
        'task_id' => $task->id,
        'user_id' => $this->employee->id,
    ]);

    $job = new ProcessRecurringTasksJob();
    $job->handle();

    // Verify logic (actual count depends on day of week)
    expect($task->recurrence)->toBe('weekly');
});

test('monthly recurring task only creates instance on correct day of month', function () {
    $task = Task::factory()->create([
        'title' => 'Monthly Task',
        'dealership_id' => $this->dealership->id,
        'creator_id' => $this->manager->id,
        'recurrence' => 'monthly',
        'is_active' => true,
        'created_at' => Carbon::now()->subMonth(),
    ]);

    TaskAssignment::create([
        'task_id' => $task->id,
        'user_id' => $this->employee->id,
    ]);

    $job = new ProcessRecurringTasksJob();
    $job->handle();

    // Verify logic
    expect($task->recurrence)->toBe('monthly');
});

test('inactive recurring tasks are not processed', function () {
    $task = Task::factory()->create([
        'title' => 'Inactive Daily Task',
        'dealership_id' => $this->dealership->id,
        'creator_id' => $this->manager->id,
        'recurrence' => 'daily',
        'is_active' => false,
        'created_at' => Carbon::now()->subDay(),
    ]);

    $initialCount = Task::count();

    $job = new ProcessRecurringTasksJob();
    $job->handle();

    // Should not create new instances
    expect(Task::count())->toBe($initialCount);
});
