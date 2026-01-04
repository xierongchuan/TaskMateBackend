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
});
