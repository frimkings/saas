<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient's clinical visit: the sales from one clearance (or one stand-alone POS sale),
 * printed as one receipt and texted once a day at closing time. See App\Services\Visits\PatientVisits.
 */
class PatientVisit extends Model
{
    use BelongsToBranch;

    protected $fillable = ['patient_id', 'clearance_id', 'visit_number', 'opened_on', 'sms_sent_for'];

    protected $casts = [
        'opened_on'    => 'date',
        'sms_sent_for' => 'date',
    ];

    protected static function booted(): void
    {
        static::created(function (PatientVisit $visit): void {
            $visit->forceFill(['visit_number' => 'V-' . $visit->opened_on->format('ymd') . '-' . str_pad((string) $visit->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
        });
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function clearance()
    {
        return $this->belongsTo(CashierPatientClearance::class, 'clearance_id');
    }

    public function sales()
    {
        return $this->hasMany(Sales::class)->orderBy('id');
    }
}
