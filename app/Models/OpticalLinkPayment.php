<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Money a linked clinic says it paid an optical centre. It sits between the two clinics (no
 * tenant scope): the clinic books it as its own expense when recording it, the optical centre
 * confirms it into its own partner payment. Neither side writes the other's records.
 */
class OpticalLinkPayment extends Model
{
    public const STATUSES = [
        'pending' => 'Waiting for the optical centre to confirm',
        'confirmed' => 'Confirmed',
        'disputed' => 'Not received, says the optical centre',
        'withdrawn' => 'Withdrawn',
    ];

    /** Paid money waiting on the optical centre (counted against what the clinic still owes). */
    public const WAITING = ['pending', 'disputed'];

    public const EXPENSE_CATEGORY = 'Optical centre jobs';

    protected $fillable = ['sender_clinic_id', 'optical_clinic_id', 'optical_partner_clinic_id', 'voucher_number', 'amount', 'payment_method',
        'reference', 'notes', 'allocations', 'status', 'expense_id', 'optical_partner_payment_id', 'recorded_by'];

    protected $casts = ['amount' => 'decimal:2', 'allocations' => 'array', 'confirmed_at' => 'datetime'];

    public function scopeForOptical(Builder $query, int $clinicId): Builder
    {
        return $query->where('optical_clinic_id', $clinicId);
    }

    public function scopeForSender(Builder $query, int $clinicId): Builder
    {
        return $query->where('sender_clinic_id', $clinicId);
    }

    public function senderClinic()
    {
        return $this->belongsTo(Clinic::class, 'sender_clinic_id');
    }

    public function opticalClinic()
    {
        return $this->belongsTo(Clinic::class, 'optical_clinic_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function methodLabel(): string
    {
        return OpticalPartnerPayment::METHODS[$this->payment_method] ?? str_replace('_', ' ', (string) $this->payment_method);
    }

    public static function nextVoucher(): string
    {
        do {
            $number = 'OLP-'.now()->format('ymd').'-'.strtoupper(\Illuminate\Support\Str::random(5));
        } while (self::where('voucher_number', $number)->exists());

        return $number;
    }
}
