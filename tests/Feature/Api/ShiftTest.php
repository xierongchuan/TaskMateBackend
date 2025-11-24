<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Shift;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Shift API', function () {
    beforeEach(function () {
        $this->manager = User::factory()->create(['role' => Role::MANAGER->value]);
        $this->dealership = AutoDealership::factory()->create();
        \Illuminate\Support\Facades\Storage::fake('public');
    });

    it('returns shifts list', function () {
        // Arrange
        Shift::factory(3)->create(['dealership_id' => $this->dealership->id]);

        // Act
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/shifts?dealership_id={$this->dealership->id}");

        // Assert
        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(3);
    });

    it('starts a shift', function () {
        // Arrange
        Carbon::setTestNow(Carbon::parse('09:00:00'));
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $file = \Illuminate\Http\Testing\File::image('photo.jpg');

        // Act
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/shifts', [
                'dealership_id' => $this->dealership->id,
                'user_id' => $user->id,
                'opening_photo' => $file,
            ]);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('shifts', [
            'user_id' => $user->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
        ]);
    });

    it('ends a shift', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $shift = Shift::factory()->create([
            'user_id' => $user->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
            'shift_start' => Carbon::now()->subHours(8),
        ]);
        $file = \Illuminate\Http\Testing\File::image('closing.jpg');

        // Act
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/shifts/{$shift->id}", [
                'closing_photo' => $file,
                'status' => 'closed',
            ]);

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'closed',
        ]);
    });
});
