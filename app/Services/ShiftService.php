<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftReplacement;
use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShiftService
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {
    }

    /**
     * Open a new shift for a user
     *
     * @param User $user
     * @param UploadedFile $photo
     * @param User|null $replacingUser
     * @param string|null $reason
     * @return Shift
     * @throws \InvalidArgumentException
     */
    public function openShift(User $user, UploadedFile $photo, ?User $replacingUser = null, ?string $reason = null, ?int $dealershipId = null): Shift
    {
        // Use provided dealershipId or fallback to user's primary dealership
        $dealershipId = $dealershipId ?? $user->dealership_id;

        // Validate user belongs to a dealership
        if (!$dealershipId) {
            throw new \InvalidArgumentException('User must belong to a dealership to open a shift');
        }

        // Check for existing open shift in this dealership
        $existingShift = $this->getUserOpenShift($user, $dealershipId);
        if ($existingShift) {
            throw new \InvalidArgumentException('User already has an open shift in this dealership');
        }

        $now = Carbon::now();

        // Get dealership-specific settings
        $scheduledStart = $this->getScheduledStartTime($now, $dealershipId);
        $scheduledEnd = $this->getScheduledEndTime($now, $dealershipId);
        $lateTolerance = $this->settingsService->getLateTolerance($dealershipId);

        // Calculate if user is late
        $lateMinutes = (int) max(0, $now->diffInMinutes($scheduledStart));
        $isLate = $lateMinutes > $lateTolerance;

        // Determine shift status
        $status = $isLate ? 'late' : 'open';

        // Determine shift type based on day of week
        $dayOfWeek = $now->dayOfWeek; // 0 = Sunday, 6 = Saturday
        $shiftType = ($dayOfWeek === 0 || $dayOfWeek === 6) ? 'weekend' : 'regular';

        // Store photo
        $photoPath = $this->storeShiftPhoto($photo, 'opening', $user->id, $dealershipId);

        try {
            DB::beginTransaction();

            // Create shift record
            $shift = Shift::create([
                'user_id' => $user->id,
                'dealership_id' => $dealershipId,
                'shift_start' => $now,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'opening_photo_path' => $photoPath,
                'status' => $status,
                'shift_type' => $shiftType,
                'late_minutes' => $lateMinutes,
            ]);

            // Handle replacement if needed
            if ($replacingUser) {
                $this->createReplacement($shift, $replacingUser, $user, $reason);

                // Close the replaced user's shift
                $replacedShift = $this->getUserOpenShift($replacingUser);
                if ($replacedShift) {
                    $this->closeShiftWithoutPhoto($replacedShift, 'replaced');
                }
            }

            DB::commit();

            Log::info("Shift opened for user {$user->id} in dealership {$dealershipId}", [
                'shift_id' => $shift->id,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'is_replacement' => $replacingUser ? true : false,
            ]);

            return $shift;
        } catch (\Exception $e) {
            DB::rollBack();

            // Clean up photo if shift creation failed
            if ($photoPath && Storage::exists($photoPath)) {
                Storage::delete($photoPath);
            }

            Log::error("Failed to open shift for user {$user->id}", [
                'error' => $e->getMessage(),
                'dealership_id' => $dealershipId,
            ]);

            throw new \InvalidArgumentException('Failed to open shift: ' . $e->getMessage());
        }
    }

    /**
     * Close a shift
     *
     * @param Shift $shift
     * @param UploadedFile $photo
     * @return Shift
     * @throws \InvalidArgumentException
     */
    public function closeShift(Shift $shift, UploadedFile $photo): Shift
    {
        if ($shift->status === 'closed') {
            throw new \InvalidArgumentException('Shift is already closed');
        }

        $now = Carbon::now();

        // Store photo
        $photoPath = $this->storeShiftPhoto($photo, 'closing', $user->id, $shift->dealership_id);

        try {
            DB::beginTransaction();

            // Update shift record
            $shift->update([
                'shift_end' => $now,
                'closing_photo_path' => $photoPath,
                'status' => 'closed',
            ]);

            // Log incomplete tasks
            $this->logIncompleteTasks($shift, $user);

            DB::commit();

            Log::info("Shift closed for user {$user->id}", [
                'shift_id' => $shift->id,
                'duration' => $shift->shift_start->diffInMinutes($now),
            ]);

            return $shift;
        } catch (\Exception $e) {
            DB::rollBack();

            // Clean up photo if shift update failed
            if ($photoPath && Storage::exists($photoPath)) {
                Storage::delete($photoPath);
            }

            Log::error("Failed to close shift for user {$user->id}", [
                'error' => $e->getMessage(),
                'shift_id' => $shift->id,
            ]);

            throw new \InvalidArgumentException('Failed to close shift: ' . $e->getMessage());
        }
    }

    /**
     * Get user's current open shift
     *
     * @param User $user
     * @param int|null $dealershipId
     * @return Shift|null
     */
    public function getUserOpenShift(User $user, ?int $dealershipId = null): ?Shift
    {
        $query = Shift::where('user_id', $user->id)
            ->where('status', '!=', 'closed');

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->first();
    }

    /**
     * Get current open shifts for a dealership
     *
     * @param int|null $dealershipId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCurrentShifts(?int $dealershipId = null)
    {
        $query = Shift::with(['user', 'dealership', 'replacement'])
            ->where('status', '!=', 'closed')
            ->orderBy('shift_start', 'desc');

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        return $query->get();
    }

    /**
     * Get shift statistics for a dealership and period
     *
     * @param int|null $dealershipId
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return array
     */
    public function getShiftStatistics(?int $dealershipId = null, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = Shift::query();

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        if ($startDate) {
            $query->where('shift_start', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('shift_start', '<=', $endDate);
        }

        $totalShifts = $query->count();
        $lateShifts = $query->where('status', 'late')->count();
        $avgLateMinutes = $query->whereNotNull('late_minutes')->avg('late_minutes') ?? 0;
        $replacements = ShiftReplacement::whereHas('shift', function ($q) use ($dealershipId, $startDate, $endDate) {
            if ($dealershipId) {
                $q->where('dealership_id', $dealershipId);
            }
            if ($startDate) {
                $q->where('shift_start', '>=', $startDate);
            }
            if ($endDate) {
                $q->where('shift_start', '<=', $endDate);
            }
        })->count();

        return [
            'total_shifts' => $totalShifts,
            'late_shifts' => $lateShifts,
            'avg_late_minutes' => round($avgLateMinutes, 2),
            'replacements' => $replacements,
            'period' => [
                'start' => $startDate?->format('Y-m-d'),
                'end' => $endDate?->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Create a shift replacement
     *
     * @param Shift $shift
     * @param User $replacedUser
     * @param User $replacingUser
     * @param string|null $reason
     * @return ShiftReplacement
     */
    private function createReplacement(Shift $shift, User $replacedUser, User $replacingUser, ?string $reason): ShiftReplacement
    {
        return ShiftReplacement::create([
            'shift_id' => $shift->id,
            'replaced_user_id' => $replacedUser->id,
            'replacing_user_id' => $replacingUser->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Close shift without photo (for replacement scenarios or manual close)
     *
     * @param Shift $shift
     * @param string $status
     * @return Shift
     */
    public function closeShiftWithoutPhoto(Shift $shift, string $status): Shift
    {
        $shift->update([
            'shift_end' => Carbon::now(),
            'status' => $status,
        ]);

        return $shift;
    }

    /**
     * Store shift photo with proper path structure
     *
     * @param UploadedFile $photo
     * @param string $type
     * @param int $userId
     * @param int $dealershipId
     * @return string
     */
    private function storeShiftPhoto(UploadedFile $photo, string $type, int $userId, int $dealershipId): string
    {
        $filename = $type . '_' . time() . '_' . $userId . '.' . $photo->getClientOriginalExtension();
        $path = "dealerships/{$dealershipId}/shifts/{$userId}/" . date('Y/m/d');

        return $photo->storeAs($path, $filename, 'public');
    }

    /**
     * Get scheduled start time based on dealership settings
     *
     * @param Carbon $dateTime
     * @param int $dealershipId
     * @return Carbon
     */
    private function getScheduledStartTime(Carbon $dateTime, int $dealershipId): Carbon
    {
        $hour = (int) $dateTime->format('H');

        // First shift: 00:00 - 12:59
        if ($hour < 13) {
            $startTime = $this->settingsService->getShiftStartTime($dealershipId, 1);
            return $dateTime->copy()->setTimeFromTimeString($startTime);
        }

        // Second shift: 13:00 - 23:59
        $startTime = $this->settingsService->getShiftStartTime($dealershipId, 2);
        return $dateTime->copy()->setTimeFromTimeString($startTime);
    }

    /**
     * Get scheduled end time based on dealership settings
     *
     * @param Carbon $dateTime
     * @param int $dealershipId
     * @return Carbon
     */
    private function getScheduledEndTime(Carbon $dateTime, int $dealershipId): Carbon
    {
        $hour = (int) $dateTime->format('H');

        // First shift: 00:00 - 12:59
        if ($hour < 13) {
            $endTime = $this->settingsService->getShiftEndTime($dealershipId, 1);
            $endDateTime = $dateTime->copy()->setTimeFromTimeString($endTime);

            // If end time is earlier than start time (e.g., night shift crossing midnight)
            if ($endDateTime->lt($dateTime)) {
                $endDateTime->addDay();
            }

            return $endDateTime;
        }

        // Second shift: 13:00 - 23:59
        $endTime = $this->settingsService->getShiftEndTime($dealershipId, 2);
        $endDateTime = $dateTime->copy()->setTimeFromTimeString($endTime);

        // If end time is earlier than start time (e.g., night shift crossing midnight)
        if ($endDateTime->lt($dateTime)) {
            $endDateTime->addDay();
        }

        return $endDateTime;
    }

    /**
     * Log incomplete tasks for a shift
     *
     * @param Shift $shift
     * @param User $user
     * @return void
     */
    private function logIncompleteTasks(Shift $shift, User $user): void
    {
        // Get tasks assigned to user that are due during the shift period
        $tasks = Task::where(function ($query) use ($shift, $user) {
                $query->whereHas('assignments', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })->orWhere('task_type', 'group');
            })
            ->where('dealership_id', $shift->dealership_id)
            ->where('is_active', true)
            ->where(function ($query) use ($shift) {
                $query->whereBetween('deadline', [$shift->shift_start, $shift->shift_end ?? Carbon::now()])
                    ->orWhereNull('deadline');
            })
            ->whereDoesntHave('responses', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->whereIn('status', ['completed', 'acknowledged']);
            })
            ->get();

        foreach ($tasks as $task) {
            Log::info("Incomplete task at shift end", [
                'shift_id' => $shift->id,
                'task_id' => $task->id,
                'user_id' => $user->id,
                'dealership_id' => $shift->dealership_id,
            ]);
        }
    }

    /**
     * Validate user can work with shifts in their dealership
     *
     * @param User $user
     * @param int|null $dealershipId
     * @return bool
     */
    public function validateUserDealership(User $user, ?int $dealershipId = null): bool
    {
        if (!$dealershipId) {
            return (bool) $user->dealership_id;
        }

        // Check primary dealership
        if ($user->dealership_id === $dealershipId) {
            return true;
        }

        // Allow admins and owners to operate in any dealership
        if (in_array($user->role, ['admin', 'owner'])) {
            return true;
        }

        // Check attached dealerships (many-to-many)
        return $user->dealerships()->where('auto_dealerships.id', $dealershipId)->exists();
    }

    /**
     * Get shifts for a user with dealership context
     *
     * @param User $user
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUserShifts(User $user, array $filters = [])
    {
        $query = Shift::where('user_id', $user->id)
            ->where('dealership_id', $user->dealership_id)
            ->with(['dealership', 'replacement.replacingUser', 'replacement.replacedUser']);

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('shift_start', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('shift_start', '<=', $filters['date_to']);
        }

        return $query->orderBy('shift_start', 'desc')->get();
    }
}
