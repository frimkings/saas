<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;

class SmsLog extends Model
{
    use HasFactory, BelongsToClinic, BelongsToOptionalBranch;

    protected $fillable = [
        'patient_id',
        'template_key',
        'channel',
        'recipient',
        'message',
        'success',
        'status',
        'segments',
        'charged_credits',
        'sender_id',
        'provider_message_id',
        'attempts',
        'sent_at',
        'error',
    ];

    protected $casts = [
        'success' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    /** Gateways can answer with a long body; keep what fits the column rather than failing the save. */
    public function setErrorAttribute(?string $value): void
    {
        $this->attributes['error'] = $value === null ? null : \Illuminate\Support\Str::limit($value, 250);
    }
}
