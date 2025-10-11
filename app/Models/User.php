<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'login',
        'full_name',
        'telegram_id',
        'phone',
        'role',
        'company_id',
        'dealership_id',
        'password'
    ];

    protected $hidden = [
        'password',
    ];

    public function dealership()
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'user_id');
    }

    public function taskAssignments()
    {
        return $this->hasMany(TaskAssignment::class, 'user_id');
    }

    public function taskResponses()
    {
        return $this->hasMany(TaskResponse::class, 'user_id');
    }

    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'creator_id');
    }

    public function createdLinks()
    {
        return $this->hasMany(ImportantLink::class, 'creator_id');
    }

    public function replacementsAsReplacing()
    {
        return $this->hasMany(ShiftReplacement::class, 'replacing_user_id');
    }

    public function replacementsAsReplaced()
    {
        return $this->hasMany(ShiftReplacement::class, 'replaced_user_id');
    }
}
