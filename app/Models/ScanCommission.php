<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/**
 * What a referrer earned on one paid scan. Never deleted: a refund voids an unpaid row, or adds a
 * negative correction against a paid one. Balance owed = accrued rows (corrections included).
 * Clinic-wide (not per branch): a partner is paid once for scans done at any branch.
 */
class ScanCommission extends Model
{
    use BelongsToClinic;

    protected $fillable = ['branch_id', 'scan_order_id', 'scan_order_item_id', 'payee_type', 'scan_partner_clinic_id', 'user_id', 'base_amount',
        'rate_type', 'rate_value', 'amount', 'kind', 'earned_on', 'status', 'scan_commission_payout_id', 'void_reason', 'voided_at'];

    protected $casts = ['base_amount' => 'decimal:2', 'rate_value' => 'decimal:2', 'amount' => 'decimal:2', 'earned_on' => 'date', 'voided_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(ScanOrder::class, 'scan_order_id');
    }

    public function item()
    {
        return $this->belongsTo(ScanOrderItem::class, 'scan_order_item_id');
    }

    public function partner()
    {
        return $this->belongsTo(ScanPartnerClinic::class, 'scan_partner_clinic_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payout()
    {
        return $this->belongsTo(ScanCommissionPayout::class, 'scan_commission_payout_id');
    }

    /** Only this payee's rows. */
    public function scopeFor($query, string $type, int $id)
    {
        return $query->where('payee_type', $type)->where($type === 'partner' ? 'scan_partner_clinic_id' : 'user_id', $id);
    }
}
