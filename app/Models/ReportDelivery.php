<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;

class ReportDelivery extends Model
{
    use BelongsToClinic, BelongsToOptionalBranch;
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SENT = 'sent';

    protected $fillable = [
        'delivery_key',
        'period',
        'period_start',
        'period_end',
        'subject',
        'report_payload',
        'recipients',
        'sent_recipients',
        'failed_recipients',
        'status',
        'attempts',
        'last_attempt_at',
        'sent_at',
        'last_error',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'report_payload' => 'array',
        'recipients' => 'array',
        'sent_recipients' => 'array',
        'failed_recipients' => 'array',
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function pendingRecipients(): array
    {
        $sent = collect($this->sent_recipients ?? [])
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->all();

        return collect($this->recipients ?? [])
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->reject(fn ($email) => in_array($email, $sent, true))
            ->values()
            ->all();
    }

    public function markAttempt(array $sentRecipients, array $failedRecipients, ?string $lastError): void
    {
        $sent = collect($this->sent_recipients ?? [])
            ->merge($sentRecipients)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $failed = collect($failedRecipients)
            ->map(fn ($row) => [
                'email' => strtolower(trim((string) ($row['email'] ?? ''))),
                'error' => (string) ($row['error'] ?? ''),
            ])
            ->filter(fn ($row) => $row['email'] !== '')
            ->values()
            ->all();

        $allSent = count($sent) >= count(collect($this->recipients ?? [])->filter());

        $this->update([
            'sent_recipients' => $sent ?: null,
            'failed_recipients' => $allSent ? null : ($failed ?: null),
            'status' => $allSent ? self::STATUS_SENT : self::STATUS_FAILED,
            'attempts' => $this->attempts + 1,
            'last_attempt_at' => now(),
            'sent_at' => $allSent ? now() : $this->sent_at,
            'last_error' => $allSent ? null : $lastError,
        ]);
    }
}
