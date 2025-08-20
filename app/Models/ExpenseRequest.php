<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseRequest extends Model
{
    protected $fillable = [
        'requester_id',
        'title',
        'description',
        'amount',
        'currency',
        'status',
    ];
}
