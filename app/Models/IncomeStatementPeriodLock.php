<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBusinessLine;

class IncomeStatementPeriodLock extends Model
{
    use HasFactory, BelongsToBranch, HasBusinessLine;

    protected $fillable = [
        'from_date',
        'to_date',
        'locked_by',
        'locked_at',
        'notes',
        'business_line',
        'snapshot',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'locked_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
