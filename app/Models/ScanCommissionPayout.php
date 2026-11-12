<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** One payment of commission to a partner clinic or one of the clinic's doctors, by voucher or through payroll. */
class ScanCommissionPayout extends Model
{
    use BelongsToBranch;

    protected $fillable = ['voucher_number', 'payee_type', 'scan_partner_clinic_id', 'user_id', 'route', 'period_to', 'amount', 'payment_method',
        'reference', 'notes', 'expense_id', 'paid_by', 'paid_at'];

    protected $casts = ['amount' => 'decimal:2', 'period_to' => 'date', 'paid_at' => 'datetime'];

    public function commissions()
    {
        return $this->hasMany(ScanCommission::class);
    }

    public function partner()
    {
        return $this->belongsTo(ScanPartnerClinic::class, 'scan_partner_clinic_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function payeeName(): string
    {
        return $this->payee_type === 'partner' ? ($this->partner?->name ?? 'Partner clinic') : ($this->user?->name ?? 'Doctor');
    }

    /** The next voucher number for this clinic and year: COM-2026-0001. */
    public static function nextNumber(): string
    {
        $prefix = 'COM-'.now()->format('Y').'-';
        $last = static::withoutGlobalScope('branch')->where('voucher_number', 'like', $prefix.'%')->orderByDesc('voucher_number')->value('voucher_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
