<?php

declare(strict_types=1);

namespace App\Helpers;

use Carbon\Carbon;

/**
 * Централизованный helper для работы с временем и timezone.
 *
 * Система использует:
 * - UTC для хранения в БД
 * - Asia/Yekaterinburg (UTC+5) для пользовательского интерфейса
 */
class TimeHelper
{
    /** Timezone пользователей (Екатеринбург, UTC+5) */
    public const USER_TIMEZONE = 'Asia/Yekaterinburg';

    /** Timezone хранения в БД */
    public const DB_TIMEZONE = 'UTC';

    /**
     * Текущее время в UTC для сравнения с данными БД
     */
    public static function nowUtc(): Carbon
    {
        return Carbon::now(self::DB_TIMEZONE);
    }

    /**
     * Сегодняшняя дата в пользовательском timezone
     */
    public static function todayUserTz(): Carbon
    {
        return Carbon::today(self::USER_TIMEZONE);
    }

    /**
     * Начало дня в UTC (для запросов к БД)
     *
     * @param Carbon|string|null $date Дата в формате Y-m-d или Carbon объект
     */
    public static function startOfDayUtc(Carbon|string|null $date = null): Carbon
    {
        if ($date instanceof Carbon) {
            $carbon = $date->copy()->setTimezone(self::USER_TIMEZONE);
        } else {
            $carbon = $date
                ? Carbon::parse($date, self::USER_TIMEZONE)
                : Carbon::now(self::USER_TIMEZONE);
        }

        return $carbon->startOfDay()->setTimezone(self::DB_TIMEZONE);
    }

    /**
     * Конец дня в UTC (для запросов к БД)
     *
     * @param Carbon|string|null $date Дата в формате Y-m-d или Carbon объект
     */
    public static function endOfDayUtc(Carbon|string|null $date = null): Carbon
    {
        if ($date instanceof Carbon) {
            $carbon = $date->copy()->setTimezone(self::USER_TIMEZONE);
        } else {
            $carbon = $date
                ? Carbon::parse($date, self::USER_TIMEZONE)
                : Carbon::now(self::USER_TIMEZONE);
        }

        return $carbon->endOfDay()->setTimezone(self::DB_TIMEZONE);
    }

    /**
     * Проверка: дедлайн прошёл (сравнение в UTC)
     */
    public static function isDeadlinePassed(?Carbon $deadline): bool
    {
        if ($deadline === null) {
            return false;
        }

        return $deadline->lt(self::nowUtc());
    }

    /**
     * Начало недели в UTC
     */
    public static function startOfWeekUtc(): Carbon
    {
        return Carbon::now(self::USER_TIMEZONE)
            ->startOfWeek()
            ->setTimezone(self::DB_TIMEZONE);
    }

    /**
     * Конец недели в UTC
     */
    public static function endOfWeekUtc(): Carbon
    {
        return Carbon::now(self::USER_TIMEZONE)
            ->endOfWeek()
            ->setTimezone(self::DB_TIMEZONE);
    }

    /**
     * Начало месяца в UTC
     */
    public static function startOfMonthUtc(): Carbon
    {
        return Carbon::now(self::USER_TIMEZONE)
            ->startOfMonth()
            ->setTimezone(self::DB_TIMEZONE);
    }

    /**
     * Конец месяца в UTC
     */
    public static function endOfMonthUtc(): Carbon
    {
        return Carbon::now(self::USER_TIMEZONE)
            ->endOfMonth()
            ->setTimezone(self::DB_TIMEZONE);
    }

    /**
     * Получить offset для пользовательского timezone (например, +05:00)
     */
    public static function getUserTimezoneOffset(): string
    {
        return Carbon::now(self::USER_TIMEZONE)->format('P');
    }
}
