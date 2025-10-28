<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const int CACHE_TTL = 3600; // 1 hour

    /**
     * Get a setting value with caching.
     *
     * @param string $key
     * @param int|null $dealershipId
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, ?int $dealershipId = null, mixed $default = null): mixed
    {
        $cacheKey = $this->getCacheKey($key, $dealershipId);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $dealershipId, $default) {
            $setting = Setting::where('key', $key)
                ->where('dealership_id', $dealershipId)
                ->first();

            return $setting ? $setting->getTypedValue() : $default;
        });
    }

    /**
     * Set a setting value.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $dealershipId
     * @param string $type
     * @param string|null $description
     * @return Setting
     * @throws \InvalidArgumentException When value is invalid for the type
     */
    public function set(
        string $key,
        mixed $value,
        ?int $dealershipId = null,
        string $type = 'string',
        ?string $description = null
    ): Setting {
        // Validate value based on type
        $this->validateSettingValue($value, $type, $key);

        // Convert null to default value before creating/setting
        $processedValue = $this->processValueForStorage($value, $type);

        $setting = Setting::updateOrCreate(
            [
                'key' => $key,
                'dealership_id' => $dealershipId,
            ],
            [
                'type' => $type,
                'value' => $processedValue,
                'description' => $description,
            ]
        );

        // Set the typed value (this will handle any final conversions)
        $setting->setTypedValue($value);
        $setting->save();

        // Clear cache
        Cache::forget($this->getCacheKey($key, $dealershipId));

        return $setting;
    }

    /**
     * Process value for storage to avoid null constraint violations.
     *
     * @param mixed $value
     * @param string $type
     * @return string|int|float
     */
    private function processValueForStorage(mixed $value, string $type): string|int|float
    {
        // If value is not null, convert it for initial storage
        if ($value !== null) {
            return match ($type) {
                'boolean' => $value ? '1' : '0',
                'integer' => (int) $value,
                'time' => (string) $value,
                'json' => json_encode($value),
                default => (string) $value,
            };
        }

        // Convert null to default values to avoid constraint violations
        return match ($type) {
            'integer' => 0,
            'boolean' => '0',
            'time' => '00:00',
            'json' => json_encode(null),
            default => '',
        };
    }

    /**
     * Validate setting value based on type.
     *
     * @param mixed $value
     * @param string $type
     * @param string $key
     * @throws \InvalidArgumentException
     */
    private function validateSettingValue(mixed $value, string $type, string $key): void
    {
        match ($type) {
            'time' => $this->validateTimeValue($value, $key),
            'integer' => $this->validateIntegerValue($value, $key),
            'boolean' => $this->validateBooleanValue($value, $key),
            'json' => $this->validateJsonValue($value, $key),
            default => null, // string type accepts any value
        };
    }

    /**
     * Validate time value.
     *
     * @param mixed $value
     * @param string $key
     * @throws \InvalidArgumentException
     */
    private function validateTimeValue(mixed $value, string $key): void
    {
        if ($value === null) {
            return; // null will be converted to default '00:00'
        }

        if (!is_string($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException("Time value for '{$key}' must be a string or numeric");
        }

        // Convert numeric hours to HH:MM format if needed
        if (is_numeric($value)) {
            $hours = (int) $value;
            if ($hours < 0 || $hours > 23) {
                throw new \InvalidArgumentException("Hour value for '{$key}' must be between 0 and 23");
            }
            return; // Allow numeric hours, will be converted in setTypedValue
        }

        // Validate HH:MM format
        if (!preg_match('/^([0-1][0-9]|2[0-3]):([0-5][0-9])$/', $value)) {
            throw new \InvalidArgumentException("Time value for '{$key}' must be in HH:MM format (24-hour)");
        }
    }

    /**
     * Validate integer value.
     *
     * @param mixed $value
     * @param string $key
     * @throws \InvalidArgumentException
     */
    private function validateIntegerValue(mixed $value, string $key): void
    {
        if ($value === null) {
            return; // null will be converted to default 0
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Integer value for '{$key}' must be numeric");
        }
    }

    /**
     * Validate boolean value.
     *
     * @param mixed $value
     * @param string $key
     * @throws \InvalidArgumentException
     */
    private function validateBooleanValue(mixed $value, string $key): void
    {
        if ($value === null) {
            return; // null will be converted to default false
        }

        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            throw new \InvalidArgumentException(
                "Boolean value for '{$key}' must be true, false, 0, 1, or equivalent strings"
            );
        }
    }

    /**
     * Validate JSON value.
     *
     * @param mixed $value
     * @param string $key
     * @throws \InvalidArgumentException
     */
    private function validateJsonValue(mixed $value, string $key): void
    {
        if ($value === null) {
            return; // null is valid JSON
        }

        if (!is_array($value) && !is_object($value) && !is_string($value)) {
            throw new \InvalidArgumentException("JSON value for '{$key}' must be an array, object, or JSON string");
        }

        if (is_string($value) && json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON string for '{$key}': " . json_last_error_msg());
        }
    }

    /**
     * Get shift start time for a dealership.
     *
     * @param int|null $dealershipId
     * @param int $shiftNumber 1 or 2
     * @return string Time in HH:MM format
     */
    public function getShiftStartTime(?int $dealershipId = null, int $shiftNumber = 1): string
    {
        $key = $shiftNumber === 1 ? 'shift_1_start_time' : 'shift_2_start_time';
        $default = $shiftNumber === 1 ? '09:00' : '18:00';

        return $this->get($key, $dealershipId, $default);
    }

    /**
     * Get shift end time for a dealership.
     *
     * @param int|null $dealershipId
     * @param int $shiftNumber 1 or 2
     * @return string Time in HH:MM format
     */
    public function getShiftEndTime(?int $dealershipId = null, int $shiftNumber = 1): string
    {
        $key = $shiftNumber === 1 ? 'shift_1_end_time' : 'shift_2_end_time';
        $default = $shiftNumber === 1 ? '18:00' : '02:00';

        return $this->get($key, $dealershipId, $default);
    }

    /**
     * Get late tolerance in minutes.
     *
     * @param int|null $dealershipId
     * @return int
     */
    public function getLateTolerance(?int $dealershipId = null): int
    {
        return $this->get('late_tolerance_minutes', $dealershipId, 15);
    }

    /**
     * Get task archive days threshold.
     *
     * @param int|null $dealershipId
     * @return int
     */
    public function getTaskArchiveDays(?int $dealershipId = null): int
    {
        return $this->get('task_archive_days', $dealershipId, 30);
    }

    /**
     * Get weekly report day (0 = Sunday, 6 = Saturday).
     *
     * @param int|null $dealershipId
     * @return int
     */
    public function getWeeklyReportDay(?int $dealershipId = null): int
    {
        return $this->get('weekly_report_day', $dealershipId, 1); // Monday by default
    }

    /**
     * Get cache key for a setting.
     *
     * @param string $key
     * @param int|null $dealershipId
     * @return string
     */
    private function getCacheKey(string $key, ?int $dealershipId): string
    {
        return "setting:{$dealershipId}:{$key}";
    }

    /**
     * Clear all settings cache.
     */
    public function clearCache(): void
    {
        Cache::flush();
    }
}
