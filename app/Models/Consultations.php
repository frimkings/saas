<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToBranch;

class Consultations extends Model
{
    use HasFactory, BelongsToBranch;

    protected $fillable = [
        'patient_id',
        'user_id',
        'clearance_id',
        'chiefComplaint',
        'others',
        'odq',
        'odq_details',
        'examination_assessments',
        'vaOD6m',
        'vaOS6m',
        'lidsOD',
        'lidsOS',
        'conjunctivaOD',
        'conjunctivaOS',
        'corneaOD',
        'corneaOS',
        'irisOD',
        'irisOS',
        'pupilOD',
        'pupilOS',
        'acOD',
        'acOS',
        'lensOD',
        'lensOS',
        'vitreousOD',
        'vitreousOS',
        'fundusOD',
        'fundusOS',
        'cdrOD',
        'cdrOS',
        'IOPOD',
        'IOPOS',
        'notes',
        'drugs',
        'prescribed_products', // New column for Prescription First Pattern
    ];

    protected $casts = [
        'prescribed_products' => 'array',
        'odq' => 'array',
        'odq_details' => 'array',
        'examination_assessments' => 'array',
    ];

    /**
     * Get the patient that owns the consultation
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the user (doctor) who created the consultation
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias for user relationship - for clarity when referring to the doctor
     */
    public function doctor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the clearance associated with the consultation
     */
    public function clearance()
    {
        return $this->belongsTo(CashierPatientClearance::class, 'clearance_id');
    }

    /**
     * Get the refraction data for this consultation
     */
    public function refraction()
    {
        return $this->hasOne(Refractions::class, 'consultation_id');
    }

    /**
     * Get the sale associated with this prescription (if dispensed)
     */
    public function sale()
    {
        return $this->hasOne(Sales::class, 'consultation_id')->latestOfMany();
    }

    /**
     * Get the cart items for this consultation (prescription items)
     */
    public function cartItems()
    {
        return $this->hasMany(Cart::class, 'consultation_id');
    }

    public function documents()
    {
        return $this->hasMany(PatientDocument::class, 'consultation_id');
    }

    public function addenda()
    {
        return $this->hasMany(ConsultationNote::class, 'consultation_id')->latest();
    }

    /**
     * Get only dispensed cart items
     */
    public function dispensedCartItems()
    {
        return $this->hasMany(Cart::class, 'consultation_id')
            ->where('is_dispensed', true);
    }

    /**
     * Get only undispensed cart items
     */
    public function undispensedCartItems()
    {
        return $this->hasMany(Cart::class, 'consultation_id')
            ->where('is_dispensed', false);
    }

    /**
     * Get prescription products (supports both old and new column names)
     */
    public function getPrescriptionAttribute()
    {
        // Prioritize prescribed_products column
        if (!empty($this->prescribed_products)) {
            return $this->prescribed_products;
        }
        
        return [];
    }

    /**
     * Check if prescription has been dispensed
     */
    public function isDispensed()
    {
        return $this->sale()->exists();
    }

    /**
     * Check if prescription is pending (not dispensed)
     */
    public function isPendingDispensing()
    {
        return !empty($this->prescription) && !$this->isDispensed();
    }

    /**
     * Calculate total cost of prescribed products
     */
    public function getProductsTotalAttribute()
    {
        $prescription = $this->prescription;
        
        if (!$prescription || !is_array($prescription)) {
            return 0;
        }

        return collect($prescription)->sum('total');
    }

    /**
     * Scope to filter by patient
     */
    public function scopeForPatient($query, $patientId)
    {
        return $query->where('patient_id', $patientId);
    }

    /**
     * Scope to get consultations with prescriptions
     */
    public function scopeWithPrescriptions($query)
    {
        return $query->whereNotNull('prescribed_products');
    }

    /**
     * Scope to get pending prescriptions (not dispensed)
     */
    public function scopePendingDispensing($query)
    {
        return $query->withPrescriptions()
            ->whereDoesntHave('sale');
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get recent consultations
     */
    public function scopeRecent($query, $limit = 10)
    {
        return $query->latest()->limit($limit);
    }



    // App/Models/Consultations.php

public function diagnoses()
{
    return $this->belongsToMany(Diagnosis::class, 'consultation_diagnosis', 'consultation_id', 'diagnosis_id');
}

}
