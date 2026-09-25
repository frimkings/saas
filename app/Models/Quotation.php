<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBranch;

class Quotation extends Model
{
    use SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'quotation_number', 'patient_id', 'patient_name', 'patient_phone',
        'status', 'issue_date', 'valid_until', 'notes',
        'subtotal', 'discount_amount', 'total_amount', 'created_by',
    ];

    protected $casts = [
        'issue_date'  => 'date',
        'valid_until' => 'date',
        'subtotal'        => 'float',
        'discount_amount' => 'float',
        'total_amount'    => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->status === 'draft' && $this->valid_until->isPast();
    }

    public static function nextNumber(): string
    {
        $year  = now()->year;
        $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
        return 'QT-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
