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
        $this->owner = User::factory()->create(['role' => Role::OWNER->value]);
        $this->employee = User::factory()->create(['role' => Role::EMPLOYEE->value]);
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

    it('owner can start a shift via API', function () {
        // Arrange
        Carbon::setTestNow(Carbon::parse('09:00:00'));
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $file = \Illuminate\Http\Testing\File::image('photo.jpg');

        // Act - Owner opening shift for employee
        $response = $this->actingAs($this->owner, 'sanctum')
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

    it('employee cannot start a shift via API', function () {
        // Arrange
        Carbon::setTestNow(Carbon::parse('09:00:00'));
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $file = \Illuminate\Http\Testing\File::image('photo.jpg');

        // Act - Employee trying to open shift via API (should be denied)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/shifts', [
                'dealership_id' => $this->dealership->id,
                'user_id' => $user->id,
                'opening_photo' => $file,
            ]);

        // Assert - Should be forbidden (employees must use Telegram bot)
        $response->assertStatus(403);
        expect($response->json('message'))->toContain('Telegram');
    });

    it('owner can end a shift via API', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $shift = Shift::factory()->create([
            'user_id' => $user->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
            'shift_start' => Carbon::now()->subHours(8),
        ]);
        $file = \Illuminate\Http\Testing\File::image('closing.jpg');

        // Act - Owner closing shift
        $response = $this->actingAs($this->owner, 'sanctum')
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

    it('employee cannot end a shift via API', function () {
        // Arrange
        $user = User::factory()->create(['role' => Role::EMPLOYEE->value, 'dealership_id' => $this->dealership->id]);
        $shift = Shift::factory()->create([
            'user_id' => $user->id,
            'dealership_id' => $this->dealership->id,
            'status' => 'open',
            'shift_start' => Carbon::now()->subHours(8),
        ]);
        $file = \Illuminate\Http\Testing\File::image('closing.jpg');

        // Act - Employee trying to close shift via API (should be denied)
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/shifts/{$shift->id}", [
                'closing_photo' => $file,
                'status' => 'closed',
            ]);

        // Assert - Should be forbidden (employees must use Telegram bot)
        $response->assertStatus(403);
        expect($response->json('message'))->toContain('Telegram');
    });
});

