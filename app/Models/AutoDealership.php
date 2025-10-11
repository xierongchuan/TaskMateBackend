<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoDealership extends Model
{
    use HasFactory;

    protected $table = 'auto_dealerships';

    protected $fillable = [
        'name',
        'address',
        'phone',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'dealership_id');
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'dealership_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'dealership_id');
    }

    public function importantLinks()
    {
        return $this->hasMany(ImportantLink::class, 'dealership_id');
    }
}
