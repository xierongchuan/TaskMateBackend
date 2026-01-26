<?php

declare(strict_types=1);

use App\Models\CalendarDay;
use App\Models\AutoDealership;
use Carbon\Carbon;

describe('CalendarDay Model', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
    });

    describe('isHoliday', function () {
        it('returns true for holiday', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act & Assert
            expect(CalendarDay::isHoliday(Carbon::create(2025, 1, 1)))->toBeTrue();
        });

        it('returns false for workday', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 2),
                'type' => 'workday',
                'dealership_id' => null,
            ]);

            // Act & Assert
            expect(CalendarDay::isHoliday(Carbon::create(2025, 1, 2)))->toBeFalse();
        });

        it('returns false for non-existing day (default is workday)', function () {
            // Act & Assert
            expect(CalendarDay::isHoliday(Carbon::create(2025, 6, 15)))->toBeFalse();
        });

        it('checks dealership specific setting first', function () {
            // Arrange - Global is workday, but dealership specific is holiday
            CalendarDay::create([
                'date' => Carbon::create(2025, 3, 8),
                'type' => 'workday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 3, 8),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act & Assert
            expect(CalendarDay::isHoliday(Carbon::create(2025, 3, 8), $this->dealership->id))->toBeTrue();
        });

        it('falls back to global setting if dealership specific not found', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 5, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act & Assert
            expect(CalendarDay::isHoliday(Carbon::create(2025, 5, 1), $this->dealership->id))->toBeTrue();
        });
    });

    describe('isWorkday', function () {
        it('returns opposite of isHoliday', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act & Assert
            expect(CalendarDay::isWorkday(Carbon::create(2025, 1, 1)))->toBeFalse();
            expect(CalendarDay::isWorkday(Carbon::create(2025, 1, 2)))->toBeTrue();
        });
    });

    describe('setWeekdaysForYear', function () {
        it('sets all Saturdays and Sundays as holidays', function () {
            // Act
            $count = CalendarDay::setWeekdaysForYear(2025, [6, 7], null, 'holiday');

            // Assert
            expect($count)->toBeGreaterThan(100); // ~104 weekend days

            // Check first Saturday of 2025 (January 4)
            expect(CalendarDay::isHoliday(Carbon::create(2025, 1, 4)))->toBeTrue();
            // Check first Sunday of 2025 (January 5)
            expect(CalendarDay::isHoliday(Carbon::create(2025, 1, 5)))->toBeTrue();
            // Check Monday (January 6) - should not be holiday
            expect(CalendarDay::isHoliday(Carbon::create(2025, 1, 6)))->toBeFalse();
        });

        it('sets weekdays for specific dealership', function () {
            // Act
            CalendarDay::setWeekdaysForYear(2025, [7], $this->dealership->id, 'holiday');

            // Assert
            expect(CalendarDay::isHoliday(Carbon::create(2025, 1, 5), $this->dealership->id))->toBeTrue();
            // Global should not be affected
            expect(CalendarDay::isHoliday(Carbon::create(2025, 1, 5)))->toBeFalse();
        });
    });

    describe('getYearCalendar', function () {
        it('returns calendar entries for a year', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 5, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2024, 12, 31),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $calendar = CalendarDay::getYearCalendar(2025);

            // Assert
            expect($calendar)->toHaveCount(2);
        });

        it('includes both global and dealership specific entries', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 3, 8),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $calendar = CalendarDay::getYearCalendar(2025, $this->dealership->id);

            // Assert
            expect($calendar)->toHaveCount(2);
        });
    });

    describe('getHolidaysForYear', function () {
        it('returns only holidays', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 2),
                'type' => 'workday',
                'dealership_id' => null,
            ]);

            // Act
            $holidays = CalendarDay::getHolidaysForYear(2025);

            // Assert
            expect($holidays)->toHaveCount(1);
        });
    });

    describe('clearYear', function () {
        it('clears all entries for a year', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 5, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $deleted = CalendarDay::clearYear(2025);

            // Assert
            expect($deleted)->toBe(2);
            expect(CalendarDay::getYearCalendar(2025))->toHaveCount(0);
        });

        it('clears only dealership specific entries', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);
            CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            CalendarDay::clearYear(2025, $this->dealership->id);

            // Assert
            // Global entry should remain
            expect(CalendarDay::where('dealership_id', null)->count())->toBe(1);
            // Dealership entry should be deleted
            expect(CalendarDay::where('dealership_id', $this->dealership->id)->count())->toBe(0);
        });
    });

    describe('setDay', function () {
        it('creates new calendar day', function () {
            // Act
            $day = CalendarDay::setDay(Carbon::create(2025, 12, 31), 'holiday', null, 'Новый год');

            // Assert
            expect($day)->toBeInstanceOf(CalendarDay::class);
            expect($day->type)->toBe('holiday');
            expect($day->description)->toBe('Новый год');
        });

        it('updates existing calendar day', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 12, 25),
                'type' => 'workday',
                'dealership_id' => null,
            ]);

            // Act
            $day = CalendarDay::setDay(Carbon::create(2025, 12, 25), 'holiday', null, 'Рождество');

            // Assert
            expect($day->type)->toBe('holiday');
            expect(CalendarDay::where('date', '2025-12-25')->count())->toBe(1);
        });
    });

    describe('removeDay', function () {
        it('removes calendar day entry', function () {
            // Arrange
            CalendarDay::create([
                'date' => Carbon::create(2025, 8, 1),
                'type' => 'holiday',
                'dealership_id' => null,
            ]);

            // Act
            $deleted = CalendarDay::removeDay(Carbon::create(2025, 8, 1));

            // Assert
            expect($deleted)->toBeTrue();
            $this->assertDatabaseMissing('calendar_days', ['date' => '2025-08-01']);
        });

        it('returns false when entry does not exist', function () {
            // Act
            $deleted = CalendarDay::removeDay(Carbon::create(2025, 9, 1));

            // Assert
            expect($deleted)->toBeFalse();
        });
    });

    describe('toApiArray', function () {
        it('returns formatted array', function () {
            // Arrange
            $day = CalendarDay::create([
                'date' => Carbon::create(2025, 1, 1),
                'type' => 'holiday',
                'description' => 'Новый год',
                'dealership_id' => null,
            ]);

            // Act
            $array = $day->toApiArray();

            // Assert
            expect($array)->toHaveKeys(['id', 'date', 'type', 'description', 'dealership_id']);
            expect($array['date'])->toBe('2025-01-01');
            expect($array['type'])->toBe('holiday');
        });
    });

    describe('dealership relationship', function () {
        it('belongs to dealership', function () {
            // Arrange
            $day = CalendarDay::create([
                'date' => Carbon::create(2025, 3, 8),
                'type' => 'holiday',
                'dealership_id' => $this->dealership->id,
            ]);

            // Act & Assert
            expect($day->dealership)->toBeInstanceOf(AutoDealership::class);
            expect($day->dealership->id)->toBe($this->dealership->id);
        });
    });
});
