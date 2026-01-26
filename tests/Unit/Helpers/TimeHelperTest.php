<?php

declare(strict_types=1);

use App\Helpers\TimeHelper;
use Carbon\Carbon;

describe('TimeHelper', function () {
    describe('constants', function () {
        it('has correct user timezone', function () {
            expect(TimeHelper::USER_TIMEZONE)->toBe('Asia/Yekaterinburg');
        });

        it('has correct db timezone', function () {
            expect(TimeHelper::DB_TIMEZONE)->toBe('UTC');
        });
    });

    describe('nowUtc', function () {
        it('returns current time in UTC', function () {
            // Act
            $now = TimeHelper::nowUtc();

            // Assert
            expect($now)->toBeInstanceOf(Carbon::class);
            expect($now->timezone->getName())->toBe('UTC');
        });
    });

    describe('todayUserTz', function () {
        it('returns today in user timezone', function () {
            // Act
            $today = TimeHelper::todayUserTz();

            // Assert
            expect($today)->toBeInstanceOf(Carbon::class);
            expect($today->timezone->getName())->toBe('Asia/Yekaterinburg');
            expect($today->hour)->toBe(0);
            expect($today->minute)->toBe(0);
            expect($today->second)->toBe(0);
        });
    });

    describe('startOfDayUtc', function () {
        it('returns start of today in UTC by default', function () {
            // Act
            $start = TimeHelper::startOfDayUtc();

            // Assert
            expect($start)->toBeInstanceOf(Carbon::class);
            expect($start->timezone->getName())->toBe('UTC');
        });

        it('accepts date string', function () {
            // Act
            $start = TimeHelper::startOfDayUtc('2025-06-15');

            // Assert
            expect($start->timezone->getName())->toBe('UTC');
            // Start of day in Yekaterinburg (UTC+5) is 19:00 UTC previous day
            // or 2025-06-14 19:00:00 UTC
            expect($start->toDateString())->toBe('2025-06-14');
        });

        it('accepts Carbon object', function () {
            // Arrange
            $date = Carbon::create(2025, 7, 20, 15, 30, 0, 'UTC');

            // Act
            $start = TimeHelper::startOfDayUtc($date);

            // Assert
            expect($start->timezone->getName())->toBe('UTC');
        });
    });

    describe('endOfDayUtc', function () {
        it('returns end of today in UTC by default', function () {
            // Act
            $end = TimeHelper::endOfDayUtc();

            // Assert
            expect($end)->toBeInstanceOf(Carbon::class);
            expect($end->timezone->getName())->toBe('UTC');
        });

        it('accepts date string', function () {
            // Act
            $end = TimeHelper::endOfDayUtc('2025-06-15');

            // Assert
            expect($end->timezone->getName())->toBe('UTC');
            // End of day in Yekaterinburg (UTC+5) is 18:59:59 UTC next day
            expect($end->toDateString())->toBe('2025-06-15');
        });

        it('accepts Carbon object', function () {
            // Arrange
            $date = Carbon::create(2025, 7, 20, 15, 30, 0, 'UTC');

            // Act
            $end = TimeHelper::endOfDayUtc($date);

            // Assert
            expect($end->timezone->getName())->toBe('UTC');
        });
    });

    describe('isDeadlinePassed', function () {
        it('returns false for null deadline', function () {
            expect(TimeHelper::isDeadlinePassed(null))->toBeFalse();
        });

        it('returns true for past deadline', function () {
            // Arrange
            $deadline = Carbon::now('UTC')->subHour();

            // Act & Assert
            expect(TimeHelper::isDeadlinePassed($deadline))->toBeTrue();
        });

        it('returns false for future deadline', function () {
            // Arrange
            $deadline = Carbon::now('UTC')->addHour();

            // Act & Assert
            expect(TimeHelper::isDeadlinePassed($deadline))->toBeFalse();
        });
    });

    describe('startOfWeekUtc', function () {
        it('returns start of week in UTC', function () {
            // Act
            $start = TimeHelper::startOfWeekUtc();

            // Assert - результат в UTC, представляющий начало недели в Екатеринбурге
            // Понедельник 00:00 в Екатеринбурге (UTC+5) = Воскресенье 19:00 UTC
            expect($start)->toBeInstanceOf(Carbon::class);
            expect($start->timezone->getName())->toBe('UTC');
            // Проверяем что время - это полночь минус 5 часов (19:00)
            expect($start->hour)->toBe(19);
            expect($start->minute)->toBe(0);
        });
    });

    describe('endOfWeekUtc', function () {
        it('returns end of week in UTC', function () {
            // Act
            $end = TimeHelper::endOfWeekUtc();

            // Assert - результат в UTC, представляющий конец недели в Екатеринбурге
            // Воскресенье 23:59:59 в Екатеринбурге (UTC+5) = Воскресенье 18:59:59 UTC
            expect($end)->toBeInstanceOf(Carbon::class);
            expect($end->timezone->getName())->toBe('UTC');
            // Проверяем что время - это 18:59 (почти 19:00 минус 5 часов)
            expect($end->hour)->toBe(18);
            expect($end->minute)->toBe(59);
        });
    });

    describe('startOfMonthUtc', function () {
        it('returns start of month in UTC', function () {
            // Act
            $start = TimeHelper::startOfMonthUtc();

            // Assert - результат в UTC, представляющий начало месяца в Екатеринбурге
            // 1-е число 00:00 в Екатеринбурге (UTC+5) = последний день предыдущего месяца 19:00 UTC
            expect($start)->toBeInstanceOf(Carbon::class);
            expect($start->timezone->getName())->toBe('UTC');
            // Проверяем что время - это 19:00 (полночь Екатеринбурга минус 5 часов)
            expect($start->hour)->toBe(19);
            expect($start->minute)->toBe(0);
        });
    });

    describe('endOfMonthUtc', function () {
        it('returns end of month in UTC', function () {
            // Act
            $end = TimeHelper::endOfMonthUtc();

            // Assert
            expect($end)->toBeInstanceOf(Carbon::class);
            expect($end->timezone->getName())->toBe('UTC');
        });
    });

    describe('getUserTimezoneOffset', function () {
        it('returns timezone offset string', function () {
            // Act
            $offset = TimeHelper::getUserTimezoneOffset();

            // Assert
            expect($offset)->toBe('+05:00');
        });
    });
});
