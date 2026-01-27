<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Enums\Role;
use Carbon\Carbon;

describe('Report API', function () {
    beforeEach(function () {
        // Create dealership first
        $this->dealership = AutoDealership::factory()->create();

        // Create manager with access to the dealership
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    it('returns reports', function () {
        // Arrange
        $dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $dateTo = Carbon::now()->format('Y-m-d');

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports?dealership_id={$this->dealership->id}&date_from={$dateFrom}&date_to={$dateTo}");

        // Assert
        $response->assertStatus(200);
        expect($response->json('summary'))->toBeArray();
    });

    it('shows only employees in performance section', function () {
        // Arrange
        $dateFrom = Carbon::now()->subDays(7)->format('Y-m-d');
        $dateTo = Carbon::now()->format('Y-m-d');

        // Create users with different roles in the same dealership
        $employee1 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
            'full_name' => 'Employee One'
        ]);
        $employee2 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
            'full_name' => 'Employee Two'
        ]);
        $manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
            'full_name' => 'Manager User'
        ]);
        $observer = User::factory()->create([
            'role' => Role::OBSERVER->value,
            'dealership_id' => $this->dealership->id,
            'full_name' => 'Observer User'
        ]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/reports?date_from={$dateFrom}&date_to={$dateTo}");

        // Assert
        $response->assertStatus(200);
        $employeesPerformance = $response->json('employees_performance');

        // Check that only employees are in the report
        expect($employeesPerformance)->toBeArray();
        expect(count($employeesPerformance))->toBe(2);

        $employeeIds = array_column($employeesPerformance, 'employee_id');
        expect($employeeIds)->toContain($employee1->id);
        expect($employeeIds)->toContain($employee2->id);
        expect($employeeIds)->not->toContain($manager->id);
        expect($employeeIds)->not->toContain($observer->id);
    });
});
