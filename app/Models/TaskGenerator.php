<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class TaskGenerator extends Model
{
    use HasFactory;

    protected $table = 'task_generators';

    protected $fillable = [
        'title',
        'description',
        'comment',
        'creator_id',
        'dealership_id',
        'recurrence',
        'recurrence_time',
        'deadline_time',
        'recurrence_day_of_week',
        'recurrence_day_of_month',
        'start_date',
        'end_date',
        'last_generated_at',
        'task_type',
        'response_type',
        'priority',
        'tags',
        'notification_settings',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'last_generated_at' => 'datetime',
        'tags' => 'array',
        'notification_settings' => 'array',
        'is_active' => 'boolean',
        'recurrence_day_of_week' => 'integer',
        'recurrence_day_of_month' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Mutator for tags to ensure they are stored with unescaped unicode.
     */
    public function setTagsAttribute($value)
    {
        $this->attributes['tags'] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Check if this generator should generate a task today.
     *
     * Logic:
     * 1. Generator must be active
     * 2. Current date must be within start_date and end_date range
     * 3. Task must not have been generated today (prevents duplicates)
     * 4. Current time must be >= recurrence_time (scheduled appear time)
     * 5. Today must match the recurrence pattern (daily/weekly/monthly)
     *
     * This approach allows "catching up" on missed tasks if the scheduler
     * was down - as long as the time has passed today, the task will generate.
     */
    public function shouldGenerateToday(?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now('Asia/Yekaterinburg');

        // Check if generator is active
        if (!$this->is_active) {
            return false;
        }

        // Check if start_date has passed
        $startDate = $this->start_date->copy()->setTimezone('Asia/Yekaterinburg');
        if ($now->lessThan($startDate->startOfDay())) {
            return false;
        }

        // Check if end_date has passed (if set)
        if ($this->end_date) {
            $endDate = $this->end_date->copy()->setTimezone('Asia/Yekaterinburg');
            if ($now->greaterThan($endDate->endOfDay())) {
                return false;
            }
        }

        // Check if already generated today (prevents duplicate generation)
        if ($this->last_generated_at) {
            $lastRun = $this->last_generated_at->copy()->setTimezone('Asia/Yekaterinburg');
            if ($lastRun->isSameDay($now)) {
                return false;
            }
        }

        // Check if scheduled time has arrived
        // This allows "catching up" if scheduler was down - task will generate
        // once the time has passed today (but only once due to last_generated_at check)
        $scheduledTime = $this->getAppearTimeForDate($now);
        if ($now->lessThan($scheduledTime)) {
            return false;
        }

        // Check recurrence type (day matching)
        return match ($this->recurrence) {
            'daily' => true,
            'weekly' => $now->dayOfWeekIso === $this->recurrence_day_of_week,
            'monthly' => $this->isMonthlyRunDay($now),
            default => false,
        };
    }

    /**
     * Check if today is the correct day for monthly recurrence.
     */
    private function isMonthlyRunDay(Carbon $now): bool
    {
        $targetDay = $this->recurrence_day_of_month;

        if ($targetDay > 0) {
            return $now->day === $targetDay;
        }

        // Handle negative days (e.g. -1 is last day of month)
        $daysInMonth = $now->daysInMonth;
        $calculatedDay = $daysInMonth + $targetDay + 1;

        return $now->day === $calculatedDay;
    }

    /**
     * Get the appear time for today.
     */
    public function getAppearTimeForDate(Carbon $date): Carbon
    {
        $time = Carbon::createFromFormat('H:i:s', $this->recurrence_time, 'Asia/Yekaterinburg');
        return $date->copy()->setTime($time->hour, $time->minute, 0);
    }

    /**
     * Get the deadline time for today.
     */
    public function getDeadlineTimeForDate(Carbon $date): Carbon
    {
        $time = Carbon::createFromFormat('H:i:s', $this->deadline_time, 'Asia/Yekaterinburg');
        return $date->copy()->setTime($time->hour, $time->minute, 0);
    }

    /**
     * Convert generator to API array.
     */
    public function toApiArray(): array
    {
        $data = $this->toArray();

        // Load tasks with responses for accurate status calculation
        $tasks = $this->generatedTasks()->with('responses')->get();

        // Add statistics
        $data['total_generated'] = $tasks->count();

        // Count completed: archived with reason 'completed' OR active with completed response
        $data['completed_count'] = $tasks->filter(function ($task) {
            if ($task->archived_at !== null && $task->archive_reason === 'completed') {
                return true;
            }
            // Check if task has completed response (uses the calculated status attribute)
            return $task->status === 'completed';
        })->count();

        // Count expired: archived with reason 'expired' OR active but overdue
        $data['expired_count'] = $tasks->filter(function ($task) {
            if ($task->archived_at !== null && $task->archive_reason === 'expired') {
                return true;
            }
            return $task->status === 'overdue';
        })->count();

        // Convert dates to UTC+5
        if ($this->start_date) {
            $data['start_date'] = $this->start_date->copy()
                ->setTimezone('Asia/Yekaterinburg')
                ->format('Y-m-d\TH:i:sP');
        }

        if ($this->end_date) {
            $data['end_date'] = $this->end_date->copy()
                ->setTimezone('Asia/Yekaterinburg')
                ->format('Y-m-d\TH:i:sP');
        }

        if ($this->created_at) {
            $data['created_at'] = $this->created_at->copy()
                ->setTimezone('Asia/Yekaterinburg')
                ->format('Y-m-d\TH:i:sP');
        }

        if ($this->updated_at) {
            $data['updated_at'] = $this->updated_at->copy()
                ->setTimezone('Asia/Yekaterinburg')
                ->format('Y-m-d\TH:i:sP');
        }

        return $data;
    }

    // Relationships

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function dealership()
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    public function assignments()
    {
        return $this->hasMany(TaskGeneratorAssignment::class, 'generator_id');
    }

    public function assignedUsers()
    {
        return $this->belongsToMany(User::class, 'task_generator_assignments', 'generator_id', 'user_id')
            ->withTimestamps();
    }

    public function generatedTasks()
    {
        return $this->hasMany(Task::class, 'generator_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDealership($query, ?int $dealershipId)
    {
        if ($dealershipId === null) {
            return $query->whereNull('dealership_id');
        }
        return $query->where('dealership_id', $dealershipId);
    }
}
