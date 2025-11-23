<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class NotificationSettingController extends Controller
{
    /**
     * Get all notification settings for the user's dealership
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get dealership ID - managers can manage their own dealership
        $dealershipId = $request->input('dealership_id', $user->dealership_id);

        // Verify user has access to this dealership
        if (!in_array($user->role, ['owner', 'manager'])) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $settings = NotificationSetting::where('dealership_id', $dealershipId)
            ->orderBy('channel_type')
            ->get()
            ->map(function ($setting) {
                return [
                    'id' => $setting->id,
                    'channel_type' => $setting->channel_type,
                    'channel_label' => NotificationSetting::getChannelLabel($setting->channel_type),
                    'is_enabled' => $setting->is_enabled,
                    'notification_time' => $setting->notification_time,
                    'notification_day' => $setting->notification_day,
                    'notification_offset' => $setting->notification_offset,
                ];
            });

        return response()->json([
            'data' => $settings
        ]);
    }

    /**
     * Update a specific notification setting
     */
    public function update(Request $request, string $channelType): JsonResponse
    {
        $user = $request->user();

        // Verify user has access
        if (!in_array($user->role, ['owner', 'manager'])) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'dealership_id' => 'sometimes|exists:auto_dealerships,id',
            'is_enabled' => 'sometimes|boolean',
            'notification_time' => 'nullable|date_format:H:i',
            'notification_day' => 'nullable|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'notification_offset' => 'nullable|integer|min:1|max:1440', // 1 minute to 24 hours
        ]);

        $dealershipId = $validated['dealership_id'] ?? $user->dealership_id;

        $setting = NotificationSetting::where('dealership_id', $dealershipId)
            ->where('channel_type', $channelType)
            ->first();

        if (!$setting) {
            return response()->json([
                'message' => 'Notification setting not found'
            ], 404);
        }

        $setting->update([
            'is_enabled' => $validated['is_enabled'] ?? $setting->is_enabled,
            'notification_time' => $validated['notification_time'] ?? $setting->notification_time,
            'notification_day' => $validated['notification_day'] ?? $setting->notification_day,
            'notification_offset' => $validated['notification_offset'] ?? $setting->notification_offset,
        ]);

        Log::info('Notification setting updated', [
            'user_id' => $user->id,
            'dealership_id' => $dealershipId,
            'channel_type' => $channelType,
            'is_enabled' => $setting->is_enabled
        ]);

        return response()->json([
            'data' => [
                'id' => $setting->id,
                'channel_type' => $setting->channel_type,
                'channel_label' => NotificationSetting::getChannelLabel($setting->channel_type),
                'is_enabled' => $setting->is_enabled,
                'notification_time' => $setting->notification_time,
                'notification_day' => $setting->notification_day,
                'notification_offset' => $setting->notification_offset,
            ]
        ]);
    }

    /**
     * Bulk update notification settings
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $user = $request->user();

        // Verify user has access
        if (!in_array($user->role, ['owner', 'manager'])) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'dealership_id' => 'sometimes|exists:auto_dealerships,id',
            'settings' => 'required|array',
            'settings.*.channel_type' => 'required|string',
            'settings.*.is_enabled' => 'sometimes|boolean',
            'settings.*.notification_time' => 'nullable|date_format:H:i',
            'settings.*.notification_day' => 'nullable|string',
        ]);

        $dealershipId = $validated['dealership_id'] ?? $user->dealership_id;
        $updatedCount = 0;

        foreach ($validated['settings'] as $settingData) {
            $setting = NotificationSetting::where('dealership_id', $dealershipId)
                ->where('channel_type', $settingData['channel_type'])
                ->first();

            if ($setting) {
                $setting->update([
                    'is_enabled' => $settingData['is_enabled'] ?? $setting->is_enabled,
                    'notification_time' => $settingData['notification_time'] ?? $setting->notification_time,
                    'notification_day' => $settingData['notification_day'] ?? $setting->notification_day,
                ]);
                $updatedCount++;
            }
        }

        Log::info('Bulk notification settings updated', [
            'user_id' => $user->id,
            'dealership_id' => $dealershipId,
            'updated_count' => $updatedCount
        ]);

        return response()->json([
            'message' => 'Settings updated successfully',
            'updated_count' => $updatedCount
        ]);
    }

    /**
     * Reset all settings to defaults for a dealership
     */
    public function resetToDefaults(Request $request): JsonResponse
    {
        $user = $request->user();

        // Verify user has access
        if (!in_array($user->role, ['owner', 'manager'])) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $dealershipId = $request->input('dealership_id', $user->dealership_id);

        // Reset all settings to enabled
        NotificationSetting::where('dealership_id', $dealershipId)
            ->update([
                'is_enabled' => true,
            ]);

        // Reset times for scheduled notifications
        NotificationSetting::where('dealership_id', $dealershipId)
            ->where('channel_type', NotificationSetting::CHANNEL_DAILY_SUMMARY)
            ->update(['notification_time' => '20:00']);

        NotificationSetting::where('dealership_id', $dealershipId)
            ->where('channel_type', NotificationSetting::CHANNEL_WEEKLY_REPORT)
            ->update([
                'notification_time' => '09:00',
                'notification_day' => 'monday'
            ]);

        Log::info('Notification settings reset to defaults', [
            'user_id' => $user->id,
            'dealership_id' => $dealershipId
        ]);

        return response()->json([
            'message' => 'Settings reset to defaults successfully'
        ]);
    }
}
