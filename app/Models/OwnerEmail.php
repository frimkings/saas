<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email sent (or attempted) to a clinic owner. See App\Services\OwnerMailer. */
class OwnerEmail extends Model
{
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    protected $fillable = ['clinic_id', 'kind', 'dedupe_key', 'recipient', 'subject', 'status', 'attempts', 'error', 'sent_at'];

    protected $casts = ['attempts' => 'integer', 'sent_at' => 'datetime'];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
