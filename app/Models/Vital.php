<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One set of vitals taken at a visit: BP, blood sugar and IOP (23 Nov 2026). Clinic-scoped, not
 * branch-scoped, so a patient's readings follow them across branches like the rest of the chart.
 */
class Vital extends Model
{
    use BelongsToClinic;

    protected $fillable = [
        'clinic_id', 'branch_id', 'patient_id', 'clearance_id', 'recorded_by',
        'bp_systolic', 'bp_diastolic', 'blood_sugar', 'blood_sugar_type', 'blood_sugar_unit',
        'iop_od', 'iop_os', 'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'blood_sugar' => 'decimal:2',
        'iop_od' => 'decimal:1',
        'iop_os' => 'decimal:1',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** "120/80" or null when neither figure is set. */
    public function bp(): ?string
    {
        return $this->bp_systolic || $this->bp_diastolic ? ($this->bp_systolic ?? '—').'/'.($this->bp_diastolic ?? '—') : null;
    }

    /** High blood pressure by the common 140/90 threshold. */
    public function bpHigh(): bool
    {
        return ($this->bp_systolic && $this->bp_systolic >= 140) || ($this->bp_diastolic && $this->bp_diastolic >= 90);
    }

    /** Raised blood sugar: fasting >=126, random >=200 mg/dL (converted when stored in mmol/L). */
    public function sugarHigh(): bool
    {
        if ($this->blood_sugar === null) {
            return false;
        }
        $mgdl = $this->blood_sugar_unit === 'mmol/L' ? (float) $this->blood_sugar * 18 : (float) $this->blood_sugar;

        return $mgdl >= ($this->blood_sugar_type === 'fasting' ? 126 : 200);
    }

    public function sugarLabel(): ?string
    {
        if ($this->blood_sugar === null) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $this->blood_sugar, 2), '0'), '.').' '.$this->blood_sugar_unit
            .($this->blood_sugar_type ? ' ('.$this->blood_sugar_type.')' : '');
    }
}
