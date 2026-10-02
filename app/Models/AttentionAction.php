<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A staff member marked a "needs attention" reminder done or snoozed it. See App\Services\Reminders\AttentionItems. */
class AttentionAction extends Model
{
    use BelongsToBranch;

    public const DONE = 'done';
    public const SNOOZED = 'snoozed';

    protected $fillable = ['subject_type', 'subject_id', 'rule', 'action', 'snoozed_until', 'note', 'user_id'];

    protected $casts = ['snoozed_until' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
