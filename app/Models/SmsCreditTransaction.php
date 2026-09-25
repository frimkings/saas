<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only history of every change to a clinic's SMS credit balance. */
class SmsCreditTransaction extends Model
{
    protected $fillable = ['clinic_id', 'type', 'credits', 'balance_after', 'sms_log_id', 'platform_invoice_id',
        'sms_bundle_id', 'user_id', 'note', 'idempotency_key'];

    protected $casts = ['credits' => 'integer', 'balance_after' => 'integer'];

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function invoice()
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
