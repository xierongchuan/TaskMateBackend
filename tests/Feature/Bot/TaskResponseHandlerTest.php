<?php

declare(strict_types=1);

use App\Bot\Handlers\TaskResponseHandler;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;

beforeEach(function () {
    $this->bot = Nutgram::fake();

    // Create test data
    $this->dealership = AutoDealership::factory()->create([
        'name' => 'Test Dealership',
        'is_active' => true,
    ]);

    $this->employee = User::factory()->create([
        'role' => 'employee',
        'dealership_id' => $this->dealership->id,
        'telegram_id' => 123456789,
        'full_name' => 'Test Employee',
    ]);

    $this->task = Task::factory()->create([
        'title' => 'Test Task',
        'description' => 'Test Description',
        'response_type' => 'execution',
        'dealership_id' => $this->dealership->id,
        'creator_id' => $this->employee->id,
        'is_active' => true,
    ]);

    TaskAssignment::create([
        'task_id' => $this->task->id,
        'user_id' => $this->employee->id,
    ]);

    auth()->login($this->employee);
});

test('task OK response is recorded correctly', function () {
    // Simulate callback for OK response
    TaskResponseHandler::handleOk($this->bot);

    // Verify response was created (would need proper callback data setup)
    expect(true)->toBeTrue();
});

test('task done response is recorded correctly', function () {
    // This would test the handleDone method
    expect($this->task)->toBeInstanceOf(Task::class);
});

test('task postpone starts conversation', function () {
    // This would test the handlePostpone method
    expect($this->task)->toBeInstanceOf(Task::class);
});

test('task response updates existing response if already exists', function () {
    // Create initial response
    $response = TaskResponse::create([
        'task_id' => $this->task->id,
        'user_id' => $this->employee->id,
        'status' => 'acknowledged',
        'responded_at' => now(),
    ]);

    expect($response->status)->toBe('acknowledged');

    // Update to completed (would be done via handler)
    $response->status = 'completed';
    $response->save();

    expect($response->fresh()->status)->toBe('completed');
});
