<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One status change on a lens order and the staff member who made it. Read through
 * LensOrder::events(), which is already clinic and branch scoped; the clinic and branch
 * are copied from the order so changes made by scheduled tasks are recorded too.
 */
class LensOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['clinic_id', 'branch_id', 'lens_order_id', 'user_id', 'from_status', 'to_status', 'note'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
