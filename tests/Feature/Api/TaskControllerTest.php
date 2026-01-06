<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Task API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('returns tasks list', function () {
        // Arrange
        Task::factory(3)->create(['dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}");

        // Assert
        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(3);
    });

    it('creates a task', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'New Task',
                'description' => 'Task Description',
                'dealership_id' => $this->dealership->id,
                'assigned_users' => [$user->id],
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
                'task_type' => 'individual',
                'response_type' => 'complete',
            ]);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('tasks', [
            'title' => 'New Task',
            'dealership_id' => $this->dealership->id,
        ]);
    });

    it('updates a task', function () {
        // Arrange
        $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => 'Updated Task',
            ]);

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated Task',
        ]);
    });

    it('deletes a task', function () {
        // Arrange
        $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/tasks/{$task->id}");

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    });
    it('creates a task with tags', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $tags = ['urgent', 'backend'];

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Task with Tags',
                'description' => 'Description',
                'dealership_id' => $this->dealership->id,
                'assigned_users' => [$user->id],
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
                'task_type' => 'individual',
                'response_type' => 'complete',
                'tags' => $tags,
            ]);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Task with Tags',
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::where('title', 'Task with Tags')->first();
        expect($task->tags)->toBe($tags);
    });

    it('updates task tags', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'tags' => ['old_tag']
        ]);
        $newTags = ['new_tag', 'updated'];

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'tags' => $newTags,
            ]);

        // Assert
        $response->assertStatus(200);
        $task->refresh();
        expect($task->tags)->toBe($newTags);
    });

    it('searches tasks by tag text', function () {
        // Arrange
        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'First Task',
            'tags' => ['apple', 'banana']
        ]);

        Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Second Task',
            'tags' => ['cherry']
        ]);

        // Act - search using 'banana' which is in tags
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks?dealership_id={$this->dealership->id}&search=banana");

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0]['title'])->toBe('First Task');
    });
});

