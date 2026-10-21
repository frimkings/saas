<?php

namespace App\Models;

use App\Support\Pricing\AttendanceSuitePrices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clinic's attendance priced by staff size: a package, the staff it pays for, and its monthly price. See App\Services\AttendanceSizing. */
class ClinicAttendanceSize extends Model
{
    /** The start of an invoice line for it; the renewal line is found and replaced by this. */
    public const LINE_PREFIX = 'Attendance Suite: ';

    protected $fillable = ['clinic_id', 'package', 'staff_limit', 'monthly_price', 'currency', 'custom_price', 'starts_at', 'ends_at', 'cancelled_at',
        'reason', 'added_by', 'updated_by'];

    protected $casts = ['staff_limit' => 'integer', 'monthly_price' => 'decimal:2', 'custom_price' => 'boolean',
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** Working at $at: started, and not yet ended. */
    public function scopeActiveAt(Builder $query, $at = null): Builder
    {
        $at ??= now();

        return $query->where('starts_at', '<=', $at)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function packageName(): string
    {
        return AttendanceSuitePrices::PACKAGES[$this->package]['name'] ?? ucfirst($this->package);
    }

    /** "Professional, up to 400 staff". */
    public function name(): string
    {
        return $this->packageName() . ', up to ' . number_format($this->staff_limit) . ' staff';
    }
}
