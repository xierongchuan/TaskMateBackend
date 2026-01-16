<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftReplacement extends Model
{
    use HasFactory;

    protected $table = 'shift_replacements';

    protected $fillable = [
        'shift_id',
        'replacing_user_id',
        'replaced_user_id',
        'reason',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function replacingUser()
    {
        return $this->belongsTo(User::class, 'replacing_user_id');
    }

    public function replacedUser()
    {
        return $this->belongsTo(User::class, 'replaced_user_id');
    }
}
