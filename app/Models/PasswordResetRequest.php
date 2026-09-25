<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetRequest extends Model
{
    protected $fillable = ['email', 'status', 'approved_by', 'admin_note', 'actioned_at'];

    protected $casts = ['actioned_at' => 'datetime'];

    public function actionedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isApproved(): bool  { return $this->status === 'approved'; }
    public function isRejected(): bool  { return $this->status === 'rejected'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }

    /** Latest open (pending or approved) request for an email. */
    public static function latestFor(string $email): ?self
    {
        return static::where('email', $email)
            ->whereIn('status', ['pending', 'approved'])
            ->latest()
            ->first();
    }

    public static function pendingCount(): int
    {
        return static::where('status', 'pending')->count();
    }
}
