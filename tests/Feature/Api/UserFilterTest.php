<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\AutoDealership;
use App\Enums\Role;

describe('Users API Filtering', function () {
    it('filters users by dealership_id', function () {
        // Arrange
        $owner = User::factory()->create(['role' => Role::OWNER->value]);
        $dealership1 = AutoDealership::factory()->create(['name' => 'D1']);
        $dealership2 = AutoDealership::factory()->create(['name' => 'D2']);

        $user1 = User::factory()->create(['dealership_id' => $dealership1->id, 'role' => Role::EMPLOYEE->value]);
        $user2 = User::factory()->create(['dealership_id' => $dealership2->id, 'role' => Role::EMPLOYEE->value]);

        // Multi-dealership user (primary D1, attached D2)
        $user3 = User::factory()->create(['dealership_id' => $dealership1->id, 'role' => Role::EMPLOYEE->value]);
        $user3->dealerships()->attach($dealership2->id);

        // Act & Assert for Dealership 1
        $response1 = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/users?dealership_id={$dealership1->id}");

        $response1->assertStatus(200);
        $data1 = $response1->json('data');
        $ids1 = array_column($data1, 'id');

        // Allow some flexibility if other users exist in DB from seeders
        expect($ids1)->toContain($user1->id);
        expect($ids1)->toContain($user3->id);
        expect($ids1)->not->toContain($user2->id);

        // Act & Assert for Dealership 2
        $response2 = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/users?dealership_id={$dealership2->id}");

        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        $ids2 = array_column($data2, 'id');

        expect($ids2)->toContain($user2->id);
        expect($ids2)->toContain($user3->id);
        expect($ids2)->not->toContain($user1->id);
    });

    it('filters users by dealership_id for Manager', function () {
        // Arrange
        $manager = User::factory()->create(['role' => Role::MANAGER->value]);
        $dealership1 = AutoDealership::factory()->create(['name' => 'D1']);
        $dealership2 = AutoDealership::factory()->create(['name' => 'D2']);
        $dealership3 = AutoDealership::factory()->create(['name' => 'D3']);

        // Manager accesses D1 and D2
        $manager->dealerships()->attach([$dealership1->id, $dealership2->id]);

        $userD1 = User::factory()->create(['dealership_id' => $dealership1->id, 'role' => Role::EMPLOYEE->value]);
        $userD2 = User::factory()->create(['dealership_id' => $dealership2->id, 'role' => Role::EMPLOYEE->value]);
        $userD3 = User::factory()->create(['dealership_id' => $dealership3->id, 'role' => Role::EMPLOYEE->value]);

        // Act & Assert 1: Filter by D1
        $response1 = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/users?dealership_id={$dealership1->id}");

        $response1->assertStatus(200);
        $ids1 = array_column($response1->json('data'), 'id');
        expect($ids1)->toContain($userD1->id);
        expect($ids1)->not->toContain($userD2->id);
        expect($ids1)->not->toContain($userD3->id);

        // Act & Assert 2: Filter by D2
        $response2 = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/users?dealership_id={$dealership2->id}");

        $response2->assertStatus(200);
        $ids2 = array_column($response2->json('data'), 'id');
        expect($ids2)->toContain($userD2->id);
        expect($ids2)->not->toContain($userD1->id);
        expect($ids2)->not->toContain($userD3->id);
    });
});
