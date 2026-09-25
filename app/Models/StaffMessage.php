<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;

class StaffMessage extends Model
{
    use BelongsToClinic, BelongsToOptionalBranch;
    protected $fillable = [
        'sender_id',
        'recipient_id',
        'subject',
        'body',
        'read_at',
        'parent_id',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function parent()
    {
        return $this->belongsTo(StaffMessage::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(StaffMessage::class, 'parent_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForRecipient($query, int $userId)
    {
        return $query->where('recipient_id', $userId);
    }

    public function scopeThreadRoots($query)
    {
        return $query->whereNull('parent_id');
    }
}
