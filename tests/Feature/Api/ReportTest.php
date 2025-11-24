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
        $this->manager = User::factory()->create(['role' => Role::MANAGER->value]);
        $this->dealership = AutoDealership::factory()->create();
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
});
