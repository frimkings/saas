<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A clinic's prepaid SMS credit balance. Only SmsCreditService changes it, always under a row lock. */
class SmsWallet extends Model
{
    protected $fillable = ['clinic_id', 'balance', 'last_topup_credits', 'low_balance_notified_at'];

    protected $casts = ['balance' => 'integer', 'last_topup_credits' => 'integer', 'low_balance_notified_at' => 'datetime'];

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    /** Warn once the balance falls to 20% of the last top-up, and never later than 20 credits left. */
    public function lowBalanceThreshold(): int
    {
        return max(20, (int) ceil(($this->last_topup_credits ?? 0) * 0.2));
    }
}
