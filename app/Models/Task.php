<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Task extends Model
{
    use HasFactory;

    protected $table = 'tasks';

    protected $fillable = [
        'title',
        'description',
        'comment',
        'creator_id',
        'dealership_id',
        'appear_date',
        'deadline',
        'recurrence',
        'recurrence_time',
        'recurrence_day_of_week',
        'recurrence_day_of_month',
        'last_recurrence_at',
        'task_type',
        'response_type',
        'tags',
        'is_active',
        'postpone_count',
        'archived_at',
        'notification_settings',
    ];

    protected $casts = [
        'appear_date' => 'datetime',
        'deadline' => 'datetime',
        'archived_at' => 'datetime',
        'last_recurrence_at' => 'datetime',
        'tags' => 'array',
        'is_active' => 'boolean',
        'postpone_count' => 'integer',
        'recurrence_day_of_week' => 'integer',
        'recurrence_day_of_month' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'notification_settings' => 'array',
    ];

    /**
     * Set appear_date - convert from user input (Asia/Yekaterinburg) to UTC for storage
     */
    public function setAppearDateAttribute($value)
    {
        if ($value) {
            // Parse user input as Asia/Yekaterinburg, then convert to UTC for storage
            $userTime = Carbon::parse($value, 'Asia/Yekaterinburg');
            $this->attributes['appear_date'] = $userTime->setTimezone('UTC');
        } else {
            $this->attributes['appear_date'] = null;
        }
    }

    /**
     * Set deadline - convert from user input (Asia/Yekaterinburg) to UTC for storage
     */
    public function setDeadlineAttribute($value)
    {
        if ($value) {
            // Parse user input as Asia/Yekaterinburg, then convert to UTC for storage
            $userTime = Carbon::parse($value, 'Asia/Yekaterinburg');
            $this->attributes['deadline'] = $userTime->setTimezone('UTC');
        } else {
            $this->attributes['deadline'] = null;
        }
    }

    /**
     * Get appear_date formatted for bot display (always in UTC+5)
     */
    public function getAppearDateForBotAttribute()
    {
        if ($this->appear_date) {
            // Convert from UTC to Asia/Yekaterinburg for display
            return $this->appear_date->copy()->setTimezone('Asia/Yekaterinburg')->format('d.m.Y H:i');
        }
        return null;
    }

    /**
     * Get deadline formatted for bot display (always in UTC+5)
     */
    public function getDeadlineForBotAttribute()
    {
        if ($this->deadline) {
            // Convert from UTC to Asia/Yekaterinburg for display
            return $this->deadline->copy()->setTimezone('Asia/Yekaterinburg')->format('d.m.Y H:i');
        }
        return null;
    }

    /**
     * Get appear_date for API response (always in UTC+5)
     */
    public function getAppearDateApiAttribute()
    {
        if ($this->appear_date) {
            return $this->appear_date->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d\TH:i:s');
        }
        return null;
    }

    /**
     * Get deadline for API response (always in UTC+5)
     */
    public function getDeadlineApiAttribute()
    {
        if ($this->deadline) {
            return $this->deadline->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d\TH:i:s');
        }
        return null;
    }

    /**
     * Get the calculated status of the task
     *
     * For group tasks: 'completed' only when ALL assignees have completed
     * For individual tasks: first response determines status
     */
    public function getStatusAttribute()
    {
        $responses = $this->responses;
        $assignments = $this->assignments;

        if ($this->task_type === 'group') {
            // For group tasks: check that ALL assignees have completed
            $assignedUserIds = $assignments->pluck('user_id')->unique()->values()->toArray();
            $completedUserIds = $responses->where('status', 'completed')->pluck('user_id')->unique()->values()->toArray();

            // All assigned users must have completed
            if (count($assignedUserIds) > 0 && count(array_diff($assignedUserIds, $completedUserIds)) === 0) {
                return 'completed';
            }

            // Check if at least one has acknowledged
            $acknowledgedUserIds = $responses->where('status', 'acknowledged')->pluck('user_id')->unique()->values()->toArray();
            if (count($acknowledgedUserIds) > 0) {
                return 'acknowledged';
            }
        } else {
            // For individual tasks: first response determines status
            if ($responses->contains('status', 'completed')) {
                return 'completed';
            }

            if ($responses->contains('status', 'acknowledged')) {
                return 'acknowledged';
            }
        }

        // Check for overdue
        if ($this->is_active && $this->deadline && $this->deadline->isPast()) {
            return 'overdue';
        }

        // Default to pending
        return 'pending';
    }

    /**
     * Convert task to array with UTC+5 times for API response
     */
    public function toApiArray()
    {
        $data = $this->toArray();

        // Add calculated status
        $data['status'] = $this->status;

        // Convert datetime fields to UTC+5
        if ($this->appear_date) {
            $data['appear_date'] = $this->appear_date_api;
        }

        if ($this->deadline) {
            $data['deadline'] = $this->deadline_api;
        }

        if ($this->archived_at) {
            $data['archived_at'] = $this->archived_at->copy()
                ->setTimezone('Asia/Yekaterinburg')
                ->format('Y-m-d\TH:i:s');
        }

        // Also convert created_at and updated_at
        if ($this->created_at) {
            $data['created_at'] = $this->created_at->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d\TH:i:s');
        }

        if ($this->updated_at) {
            $data['updated_at'] = $this->updated_at->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d\TH:i:s');
        }

        return $data;
    }

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
        return $this->hasMany(TaskAssignment::class, 'task_id');
    }

    public function responses()
    {
        return $this->hasMany(TaskResponse::class, 'task_id');
    }

    public function assignedUsers()
    {
        return $this->belongsToMany(User::class, 'task_assignments', 'task_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Scope to get only active tasks
     * Currently uses is_active field, but prepares for future migration to archived_at
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    /**
     * Scope to get archived tasks
     */
    public function scopeArchived($query)
    {
        return $query->where(function ($q) {
            $q->where('is_active', false)->orWhereNotNull('archived_at');
        });
    }

    /**
     * Auto-dealership relationship (alias for dealership)
     */
    public function autoDealership()
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }
}
