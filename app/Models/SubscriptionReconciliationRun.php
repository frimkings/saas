<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionReconciliationRun extends Model
{
    protected $guarded = [];
    protected $casts = ['summary' => 'array', 'completed_at' => 'datetime'];

    public function issues() { return $this->hasMany(SubscriptionReconciliationIssue::class, 'run_id'); }
    public function operator() { return $this->belongsTo(User::class, 'run_by'); }
}
