<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\CalendarDay;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Calendar API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    describe('GET /api/v1/calendar/{year}', function () {
        it('returns calendar for a year', function () {
            // Arrange
            $year = 2025;
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 1),
                'type' => 'holiday',
                'description' => 'Новый год',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create($year, 5, 1),
                'type' => 'holiday',
                'description' => 'Праздник труда',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/{$year}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.year', $year)
                ->assertJsonPath('data.holidays_count', 2);
        });

        it('returns calendar filtered by dealership', function () {
            // Arrange
            $year = 2025;
            // Global holiday
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            // Dealership specific holiday
            CalendarDay::create([
                'date' => Carbon::create($year, 3, 8),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/calendar/{$year}?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.dealership_id', $this->dealership->id)
                ->assertJsonPath('data.holidays_count', 2);
        });
    });

    describe('GET /api/v1/calendar/{year}/holidays', function () {
        it('returns only holiday dates', function () {
            // Arrange
            $year = 2025;
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create($year, 1, 2),
                'type' => 'workday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/{$year}/holidays");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.dates.0', '2025-01-01');
        });
    });

    describe('GET /api/v1/calendar/check/{date}', function () {
        it('checks if date is holiday', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/calendar/check/2025-01-01');

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.is_holiday', true)
                ->assertJsonPath('data.is_workday', false);
        });

        it('checks if date is workday', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/calendar/check/2025-01-15');

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.is_holiday', false)
                ->assertJsonPath('data.is_workday', true);
        });

        it('returns error for invalid date', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/calendar/check/invalid-date');

            // Assert
            $response->assertStatus(422)
                ->assertJsonPath('success', false);
        });

        it('checks dealership specific holiday', function () {
            // Arrange - Date is workday globally but holiday for dealership
            CalendarDay::create([
                'date' => Carbon::create(2025, 6, 15),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/calendar/check/2025-06-15?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.is_holiday', true);
        });
    });

    describe('PUT /api/v1/calendar/{date}', function () {
        it('allows manager to set a holiday', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', [
                    'type' => 'holiday',
                    'description' => 'Новогодний корпоратив',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.type', 'holiday');

            $this->assertDatabaseHas('calendar_days', [
                'date' => '2025-12-31',
                'type' => 'holiday',
                'description' => 'Новогодний корпоратив',
            ]);
        });

        it('allows owner to set a holiday', function () {
            // Act
            $response = $this->actingAs($this->owner, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', [
                    'type' => 'holiday',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true);
        });

        it('denies employee to set a holiday', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', [
                    'type' => 'holiday',
                ]);

            // Assert
            $response->assertStatus(403);
        });

        it('allows setting dealership specific holiday', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-07-04', [
                    'type' => 'holiday',
                    'dealership_id' => $this->dealership->id,
                    'description' => 'День автосалона',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.dealership_id', $this->dealership->id);
        });

        it('validates required fields', function () {
            // Act - missing type
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', []);

            // Assert
            $response->assertStatus(422)
                ->assertJsonPath('success', false);
        });

        it('validates type enum', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-31', [
                    'type' => 'invalid',
                ]);

            // Assert
            $response->assertStatus(422);
        });

        it('updates existing calendar day', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 12, 25),
                'type' => 'workday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-12-25', [
                    'type' => 'holiday',
                    'description' => 'Рождество',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.type', 'holiday');

            $this->assertDatabaseHas('calendar_days', [
                'date' => '2025-12-25',
                'type' => 'holiday',
            ]);
        });
    });

    describe('DELETE /api/v1/calendar/{date}', function () {
        it('allows manager to delete calendar day', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 8, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/calendar/2025-08-01');

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.deleted', true);

            $this->assertDatabaseMissing('calendar_days', [
                'date' => '2025-08-01',
            ]);
        });

        it('returns deleted=false when no record exists', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/calendar/2025-09-01');

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.deleted', false);
        });

        it('deletes only dealership specific record', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 10, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 10, 1),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act - Delete only dealership specific
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson("/api/v1/calendar/2025-10-01?dealership_id={$this->dealership->id}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.deleted', true);

            // Global record should still exist
            $this->assertDatabaseHas('calendar_days', [
                'date' => '2025-10-01',
                'dealership_id' => null,
            ]);
            $this->assertDatabaseMissing('calendar_days', [
                'date' => '2025-10-01',
                'dealership_id' => $this->dealership->id,
            ]);
        });

        it('denies employee to delete calendar day', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 8, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->deleteJson('/api/v1/calendar/2025-08-01');

            // Assert
            $response->assertStatus(403);
        });
    });

    describe('POST /api/v1/calendar/bulk', function () {
        it('sets weekdays as holidays for year', function () {
            // Act - Set all Saturdays and Sundays as holidays
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'set_weekdays',
                    'year' => 2025,
                    'weekdays' => [6, 7], // Saturday, Sunday
                    'type' => 'holiday',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.operation', 'set_weekdays');

            expect($response->json('data.affected_count'))->toBeGreaterThan(100);
        });

        it('sets specific dates as holidays', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'set_dates',
                    'year' => 2025,
                    'dates' => ['2025-01-01', '2025-05-01', '2025-05-09'],
                    'type' => 'holiday',
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.affected_count', 3);

            $this->assertDatabaseHas('calendar_days', ['date' => '2025-01-01', 'type' => 'holiday']);
            $this->assertDatabaseHas('calendar_days', ['date' => '2025-05-01', 'type' => 'holiday']);
            $this->assertDatabaseHas('calendar_days', ['date' => '2025-05-09', 'type' => 'holiday']);
        });

        it('clears all records for year', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2024, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2024, 5, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'clear_year',
                    'year' => 2024,
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.operation', 'clear_year')
                ->assertJsonPath('data.affected_count', 2);

            $this->assertDatabaseMissing('calendar_days', ['date' => '2024-01-01']);
        });

        it('validates operation type', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'invalid_operation',
                    'year' => 2025,
                ]);

            // Assert
            $response->assertStatus(422);
        });

        it('validates year range', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'clear_year',
                    'year' => 1900, // Too old
                ]);

            // Assert
            $response->assertStatus(422);
        });

        it('denies employee bulk operations', function () {
            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'clear_year',
                    'year' => 2025,
                ]);

            // Assert
            $response->assertStatus(403);
        });

        it('supports dealership specific bulk operations', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/calendar/bulk', [
                    'operation' => 'set_dates',
                    'year' => 2025,
                    'dates' => ['2025-06-01'],
                    'type' => 'holiday',
                    'dealership_id' => $this->dealership->id,
                ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.dealership_id', $this->dealership->id);

            $this->assertDatabaseHas('calendar_days', [
                'date' => '2025-06-01',
                'dealership_id' => $this->dealership->id,
            ]);
        });
    });
});
