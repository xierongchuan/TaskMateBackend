<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'task_type',
        'response_type',
        'tags',
        'is_active',
        'postpone_count',
        'archived_at',
    ];

    protected $casts = [
        'appear_date' => 'datetime',
        'deadline' => 'datetime',
        'archived_at' => 'datetime',
        'tags' => 'array',
        'is_active' => 'boolean',
        'postpone_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

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
}
