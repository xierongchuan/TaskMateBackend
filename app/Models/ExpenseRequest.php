<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseRequest extends Model
{
    protected $table = 'expense_requests';

    protected $fillable = [
        'requester_id',
        'title',
        'description',
        'amount',
        'currency',
        'status',
        'company_id',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
