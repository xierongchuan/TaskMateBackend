<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_TTL = 3600; // 1 hour

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
     */
    public function set(
        string $key,
        mixed $value,
        ?int $dealershipId = null,
        string $type = 'string',
        ?string $description = null
    ): Setting {
        $setting = Setting::updateOrCreate(
            [
                'key' => $key,
                'dealership_id' => $dealershipId,
            ],
            [
                'type' => $type,
                'description' => $description,
            ]
        );

        $setting->setTypedValue($value);
        $setting->save();

        // Clear cache
        Cache::forget($this->getCacheKey($key, $dealershipId));

        return $setting;
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
