<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionReconciliationIssue extends Model
{
    protected $guarded = [];
    protected $casts = ['evidence' => 'array'];

    public function run() { return $this->belongsTo(SubscriptionReconciliationRun::class, 'run_id'); }
    public function clinic() { return $this->belongsTo(Clinic::class); }
}
