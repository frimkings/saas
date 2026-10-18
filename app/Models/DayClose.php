<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A branch's business day, counted and closed at the till. Only the owner reopens one. */
class DayClose extends Model
{
    use BelongsToBranch;

    public const CLOSED = 'closed';
    public const REOPENED = 'reopened';

    protected $fillable = [
        'business_date', 'status', 'methods', 'cashiers', 'variance', 'banked', 'notes',
        'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected $casts = [
        'business_date' => 'date',
        'methods' => 'array',
        'cashiers' => 'array',
        'variance' => 'decimal:2',
        'banked' => 'decimal:2',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Day closes cannot be deleted.'));
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }
}
