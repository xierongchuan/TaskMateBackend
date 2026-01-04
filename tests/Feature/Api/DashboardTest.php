<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Dashboard API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('returns dashboard data for manager', function () {
        // Arrange
        $shift = Shift::factory()->create([
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
            'shift_start' => Carbon::now()->subHour(),
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/dashboard');

        // Assert
        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'active_shifts',
                'active_tasks',
                'completed_tasks',
                'overdue_tasks',
                'late_shifts_today',
                'timestamp',
            ]);
    });

    it('filters dashboard data by dealership', function () {
        // Arrange
        $otherDealership = AutoDealership::factory()->create();

        Shift::factory()->create([
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
        ]);

        Shift::factory()->create([
            'dealership_id' => $otherDealership->id,
            'status' => 'open',
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/dashboard?dealership_id={$this->dealership->id}");

        // Assert
        $response->assertStatus(200);
        $data = $response->json();
        expect($data['active_shifts'])->toHaveCount(1);
    });

    it('calculates task statistics correctly', function () {
        // Arrange
        // Active task
        Task::factory()->create(['is_active' => true, 'dealership_id' => $this->dealership->id]);

        // Completed today
        $completedTask = Task::factory()->create(['is_active' => true, 'dealership_id' => $this->dealership->id]);
        TaskResponse::factory()->create([
            'task_id' => $completedTask->id,
            'status' => 'completed',
            'responded_at' => Carbon::now(),
        ]);

        // Overdue
        Task::factory()->create([
            'is_active' => true,
            'dealership_id' => $this->dealership->id,
            'deadline' => Carbon::now()->subHour(),
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/dashboard');

        // Assert
        $response->assertStatus(200);
        $data = $response->json();

        expect($data['active_tasks'])->toBeGreaterThanOrEqual(3);
        expect($data['completed_tasks'])->toBeGreaterThanOrEqual(1);
        expect($data['overdue_tasks'])->toBeGreaterThanOrEqual(1);
    });
});
