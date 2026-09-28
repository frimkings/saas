<?php

namespace App\Models;

use App\Support\PlanProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A clinic asking the platform for an add-on. */
class ClinicAddonRequest extends Model
{
    protected $fillable = ['clinic_id', 'feature', 'message', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_notes'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function name(): string
    {
        return PlanProduct::EXTRAS[$this->feature][0] ?? ucwords(str_replace('_', ' ', $this->feature));
    }
}
