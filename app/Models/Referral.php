<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBranch;

class Referral extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'letter_type',
        'referred_by',
        'patient_id',
        'referral_to',
        'referral_date',
        'patient_name',
        'patient_age_sex',
        'patient_contact',
        // Referral fields
        'complaint',
        'va_od',
        'va_os',
        'refraction',
        'anterior_segment',
        'posterior_segment',
        'iop',
        'diagnosis',
        'reason_for_referral',
        'management',
        // Medical Report fields
        'clinical_findings',
        'treatment',
        'recommendation',
        // Excuse Duty fields
        'excuse_from_date',
        'excuse_to_date',
        'excuse_notes',
        'status',
    ];

    protected $casts = [
        'referral_date'    => 'date',
        'excuse_from_date' => 'date',
        'excuse_to_date'   => 'date',
    ];

    public static array $letterTypeLabels = [
        'referral'       => 'Referral Letter',
        'medical_report' => 'Medical Report',
        'excuse_duty'    => 'Excuse Duty Letter',
    ];

    public static function defaultExcuseNotes(): string
    {
        return "The patient has been advised to be excused from work / school duties from {excused_from} to {excused_until} due to medical reasons.\n\n"
            . "The patient is expected to resume normal duties thereafter, unless otherwise reviewed.\n\n"
            . "Please accord this letter the necessary attention. Thank you.";
    }

    public function getLetterTypeLabelAttribute(): string
    {
        return self::$letterTypeLabels[$this->letter_type] ?? ucfirst($this->letter_type);
    }

    public function getDiagnosisDisplayAttribute(): string
    {
        if (!$this->diagnosis) return '';
        $decoded = json_decode($this->diagnosis, true);
        return is_array($decoded) ? implode(', ', $decoded) : $this->diagnosis;
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
