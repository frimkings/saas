<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Something the morning alerts email has flagged to the owner. See App\Services\OwnerAlertDigestService. */
class OwnerAlertItem extends Model
{
    protected $fillable = ['clinic_id', 'section', 'item_key', 'stage', 'title', 'detail', 'branch_name', 'alerted_on', 'resolved_at'];

    protected $casts = ['alerted_on' => 'date', 'resolved_at' => 'datetime'];
}
