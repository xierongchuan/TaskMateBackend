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
        $this->manager = User::factory()->create(['role' => Role::MANAGER->value]);
        $this->dealership = AutoDealership::factory()->create();
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
        $response->assertStatus(200)
            ->assertJsonStructure([
                'current_shifts',
                'task_statistics',
                'late_shifts',
                'replacements',
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
        expect($data['current_shifts'])->toHaveCount(1)
            ->and($data['current_shifts'][0]['dealership']['id'])->toBe($this->dealership->id);
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
        $stats = $response->json('task_statistics');

        expect($stats['total_active'])->toBeGreaterThanOrEqual(3);
        expect($stats['completed_today'])->toBeGreaterThanOrEqual(1);
        expect($stats['overdue'])->toBeGreaterThanOrEqual(1);
    });
});
