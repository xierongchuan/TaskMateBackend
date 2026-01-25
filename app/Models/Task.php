<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\TimeHelper;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Task extends Model
{
    use HasFactory;
    use SoftDeletes;
    use Auditable;

    protected $table = 'tasks';

    protected $fillable = [
        'generator_id',
        'title',
        'description',
        'comment',
        'creator_id',
        'dealership_id',
        'appear_date',
        'deadline',
        'scheduled_date',
        'task_type',
        'target_shift_type',
        'response_type',
        'tags',
        'is_active',
        'postpone_count',
        'archived_at',
        'archive_reason',
        'notification_settings',
        'priority',
    ];

    protected $casts = [
        'appear_date' => 'datetime',
        'deadline' => 'datetime',
        'scheduled_date' => 'datetime',
        'archived_at' => 'datetime',
        'tags' => 'array',
        'is_active' => 'boolean',
        'postpone_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'notification_settings' => 'array',
    ];

    /**
     * Mutator for tags to ensure they are stored with unescaped unicode.
     * This fixes search issues with Cyrillic tags in Postgres.
     */
    public function setTagsAttribute($value)
    {
        $this->attributes['tags'] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    }

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
            // Include timezone offset for correct JavaScript parsing
            return $this->appear_date->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d\TH:i:sP');
        }
        return null;
    }

    /**
     * Get deadline for API response (always in UTC+5)
     */
    public function getDeadlineApiAttribute()
    {
        if ($this->deadline) {
            // Include timezone offset for correct JavaScript parsing
            return $this->deadline->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d\TH:i:sP');
        }
        return null;
    }

    /**
     * Get the calculated status of the task
     *
     * For group tasks: 'completed' only when ALL assignees have completed
     * For individual tasks: first response determines status
     *
     * Status priority:
     * 1. completed_late - all completed but after deadline
     * 2. completed - all completed before deadline
     * 3. pending_review - at least one pending review
     * 4. acknowledged - at least one acknowledged
     * 5. overdue - deadline passed, not completed
     * 6. pending - default
     *
     * Note: This accessor uses relationLoaded() checks to avoid N+1 queries.
     * Always eager load 'responses' and 'assignments' when fetching tasks.
     */
    public function getStatusAttribute()
    {
        // Use already loaded relations or load them (with warning for N+1 prevention)
        $responses = $this->relationLoaded('responses') ? $this->responses : $this->responses()->get();
        $assignments = $this->relationLoaded('assignments') ? $this->assignments : $this->assignments()->get();
        $hasDeadline = $this->deadline !== null;
        $deadlinePassed = TimeHelper::isDeadlinePassed($this->deadline);

        // Helper: check if task is completed (all required responses received)
        $isCompleted = false;
        $completedLate = false;

        if ($this->task_type === 'group') {
            // For group tasks: check that ALL assignees have completed
            $assignedUserIds = $assignments->pluck('user_id')->unique()->values()->toArray();
            $completedResponses = $responses->where('status', 'completed');
            $completedUserIds = $completedResponses->pluck('user_id')->unique()->values()->toArray();

            // All assigned users must have completed
            if (count($assignedUserIds) > 0 && count(array_diff($assignedUserIds, $completedUserIds)) === 0) {
                $isCompleted = true;

                // Check if any completion was after deadline
                if ($hasDeadline) {
                    foreach ($completedResponses as $response) {
                        if ($response->responded_at && $response->responded_at->gt($this->deadline)) {
                            $completedLate = true;
                            break;
                        }
                    }
                }
            }
        } else {
            // For individual tasks: first completed response determines status
            $completedResponse = $responses->firstWhere('status', 'completed');
            if ($completedResponse) {
                $isCompleted = true;

                // Check if completion was after deadline
                if ($hasDeadline && $completedResponse->responded_at && $completedResponse->responded_at->gt($this->deadline)) {
                    $completedLate = true;
                }
            }
        }

        // Return completed statuses
        if ($isCompleted) {
            return $completedLate ? 'completed_late' : 'completed';
        }

        // Check for pending_review and acknowledged (group or individual)
        if ($this->task_type === 'group') {
            $pendingReviewUserIds = $responses->where('status', 'pending_review')->pluck('user_id')->unique()->values()->toArray();
            if (count($pendingReviewUserIds) > 0) {
                return 'pending_review';
            }

            $acknowledgedUserIds = $responses->where('status', 'acknowledged')->pluck('user_id')->unique()->values()->toArray();
            if (count($acknowledgedUserIds) > 0) {
                return 'acknowledged';
            }
        } else {
            if ($responses->contains('status', 'pending_review')) {
                return 'pending_review';
            }

            if ($responses->contains('status', 'acknowledged')) {
                return 'acknowledged';
            }
        }

        // Check for overdue (only if active and not completed)
        if ($this->is_active && $deadlinePassed) {
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

        // Convert datetime fields to UTC+5 with proper offset
        // appear_date and deadline are stored in UTC, need to convert to local time
        if ($this->appear_date) {
            $data['appear_date'] = $this->appear_date_api;
        }

        if ($this->deadline) {
            $data['deadline'] = $this->deadline_api;
        }

        if ($this->archived_at) {
            // archived_at is stored in UTC
            $data['archived_at'] = $this->archived_at->copy()
                ->setTimezone('Asia/Yekaterinburg')
                ->format('Y-m-d\TH:i:sP');
        }

        // created_at and updated_at workaround:
        // DB stores local time value as UTC (e.g. 01:25 stored as 01:25 UTC instead of 20:25 UTC)
        // We need to output "01:25+05:00" so browser shows 01:25.
        $offset = TimeHelper::getUserTimezoneOffset(); // e.g. +05:00

        if ($this->created_at) {
            // Take the raw time value (01:25) and append the local offset
            $data['created_at'] = $this->created_at->format('Y-m-d\TH:i:s') . $offset;
        }

        if ($this->updated_at) {
            $data['updated_at'] = $this->updated_at->format('Y-m-d\TH:i:s') . $offset;
        }

        // Add responses with user info for group task progress tracking
        if ($this->relationLoaded('responses')) {
            $data['responses'] = $this->responses->map(function ($response) {
                $responseData = [
                    'id' => $response->id,
                    'user_id' => $response->user_id,
                    'status' => $response->status,
                    'comment' => $response->comment,
                    'responded_at' => $response->responded_at
                        ? $response->responded_at->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d\TH:i:sP')
                        : null,
                    'verified_at' => $response->verified_at
                        ? $response->verified_at->copy()->setTimezone('Asia/Yekaterinburg')->format('Y-m-d\TH:i:sP')
                        : null,
                    'verified_by' => $response->verified_by,
                    'rejection_reason' => $response->rejection_reason,
                    'rejection_count' => $response->rejection_count ?? 0,
                    'submission_source' => $response->submission_source ?? 'individual',
                    'uses_shared_proofs' => $response->uses_shared_proofs ?? false,
                ];

                // Include user info if loaded
                if ($response->relationLoaded('user') && $response->user) {
                    $responseData['user'] = [
                        'id' => $response->user->id,
                        'full_name' => $response->user->full_name,
                    ];
                }

                // Include verifier info if loaded
                if ($response->relationLoaded('verifier') && $response->verifier) {
                    $responseData['verifier'] = [
                        'id' => $response->verifier->id,
                        'full_name' => $response->verifier->full_name,
                    ];
                }

                // Include proofs if loaded
                if ($response->relationLoaded('proofs')) {
                    $responseData['proofs'] = $response->proofs->map(function ($proof) {
                        return $proof->toApiArray();
                    })->values()->toArray();
                }

                return $responseData;
            })->values()->toArray();
        }

        // Общие файлы задачи (для групповых задач с complete_for_all)
        if ($this->relationLoaded('sharedProofs')) {
            $data['shared_proofs'] = $this->sharedProofs->map(function ($proof) {
                return $proof->toApiArray();
            })->values()->toArray();
        } else {
            $data['shared_proofs'] = [];
        }

        // Add completion progress for group tasks
        if ($this->task_type === 'group') {
            $assignments = $this->relationLoaded('assignments') ? $this->assignments : collect();
            $responses = $this->relationLoaded('responses') ? $this->responses : collect();

            $totalAssignees = $assignments->count();
            $completedCount = $responses->where('status', 'completed')->pluck('user_id')->unique()->count();
            $pendingReviewCount = $responses->where('status', 'pending_review')->pluck('user_id')->unique()->count();

            // Rejected = явный статус 'rejected' (отклонённые, ожидающие повторной отправки)
            $rejectedCount = $responses->where('status', 'rejected')->pluck('user_id')->unique()->count();

            // Pending = те, кто ещё не ответил (нет response или status pending)
            $pendingCount = max(0, $totalAssignees - $completedCount - $pendingReviewCount - $rejectedCount);

            $data['completion_progress'] = [
                'total_assignees' => $totalAssignees,
                'completed_count' => $completedCount,
                'pending_review_count' => $pendingReviewCount,
                'rejected_count' => $rejectedCount,
                'pending_count' => $pendingCount,
                'percentage' => $totalAssignees > 0 ? (int) round(($completedCount / $totalAssignees) * 100) : 0,
            ];
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

    /**
     * Общие файлы задачи (для complete_for_all).
     */
    public function sharedProofs()
    {
        return $this->hasMany(TaskSharedProof::class, 'task_id');
    }

    public function assignedUsers()
    {
        return $this->belongsToMany(User::class, 'task_assignments', 'task_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Scope to get only active tasks.
     * Active = is_active=true AND archived_at=null
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    /**
     * Scope to get archived tasks.
     * Archived = is_active=false OR archived_at IS NOT NULL
     */
    public function scopeArchived($query)
    {
        return $query->where(function ($q) {
            $q->where('is_active', false)->orWhereNotNull('archived_at');
        });
    }

    /**
     * Accessor for checking if task is archived.
     * Unifies the two archive indicators (is_active and archived_at).
     */
    public function getIsArchivedAttribute(): bool
    {
        return !$this->is_active || $this->archived_at !== null;
    }

    /**
     * Archive the task with a reason.
     * This method ensures both is_active and archived_at are set consistently.
     *
     * @param string|null $reason The reason for archiving
     */
    public function archive(?string $reason = null): void
    {
        $this->update([
            'is_active' => false,
            'archived_at' => now(),
            'archive_reason' => $reason,
        ]);
    }

    /**
     * Restore the task from archive.
     * This method ensures both is_active and archived_at are set consistently.
     *
     * Note: Named restoreFromArchive() to avoid conflict with SoftDeletes::restore()
     */
    public function restoreFromArchive(): void
    {
        $this->update([
            'is_active' => true,
            'archived_at' => null,
            'archive_reason' => null,
        ]);
    }

    /**
     * Auto-dealership relationship (alias for dealership)
     */
    public function autoDealership()
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    /**
     * Generator relationship - links to task generator if created by one
     */
    public function generator()
    {
        return $this->belongsTo(TaskGenerator::class, 'generator_id');
    }

    /**
     * Get completion percentage for group tasks.
     * Returns percentage of assignees who have completed the task.
     *
     * Note: Uses relationLoaded() checks to avoid N+1 queries.
     * Always eager load 'responses' and 'assignments' when needing this attribute.
     */
    public function getCompletionPercentageAttribute(): int
    {
        if ($this->task_type !== 'group') {
            return $this->status === 'completed' ? 100 : 0;
        }

        // Use already loaded relations or load them
        $assignments = $this->relationLoaded('assignments') ? $this->assignments : $this->assignments()->get();
        $totalAssignees = $assignments->count();

        if ($totalAssignees === 0) {
            return 0;
        }

        $responses = $this->relationLoaded('responses') ? $this->responses : $this->responses()->get();
        $completedCount = $responses
            ->where('status', 'completed')
            ->pluck('user_id')
            ->unique()
            ->count();

        return (int) round(($completedCount / $totalAssignees) * 100);
    }

    /**
     * Scope to get tasks for a specific scheduled date
     */
    public function scopeForScheduledDate($query, $date)
    {
        return $query->whereDate('scheduled_date', $date);
    }

    /**
     * Scope to get tasks from a generator
     */
    public function scopeFromGenerator($query, $generatorId)
    {
        return $query->where('generator_id', $generatorId);
    }
}

