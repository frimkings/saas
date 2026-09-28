<?php

namespace App\Models;

use App\Support\PlanProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clinic's add-on: an extra feature on top of its plan, billed per month. */
class ClinicAddon extends Model
{
    protected $fillable = ['clinic_id', 'feature', 'monthly_price', 'currency', 'starts_at', 'ends_at', 'reason',
        'clinic_addon_request_id', 'added_by', 'cancelled_by', 'cancelled_at'];

    protected $casts = ['monthly_price' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime'];

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

    public function name(): string
    {
        return PlanProduct::EXTRAS[$this->feature][0] ?? ucwords(str_replace('_', ' ', $this->feature));
    }
}
