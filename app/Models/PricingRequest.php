<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A "Request this plan" from the public pricing page, followed up by the platform. */
class PricingRequest extends Model
{
    public const STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'won' => 'Became a clinic', 'lost' => 'Not going ahead'];

    protected $fillable = ['clinic_name', 'contact_name', 'phone', 'email', 'town', 'subscription_plan_id', 'plan_name', 'billing_interval',
        'selection', 'monthly_total', 'quoted_total', 'currency', 'message', 'status', 'notes', 'handled_by', 'handled_at', 'ip'];

    protected $casts = ['selection' => 'array', 'monthly_total' => 'decimal:2', 'quoted_total' => 'decimal:2', 'handled_at' => 'datetime'];

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
