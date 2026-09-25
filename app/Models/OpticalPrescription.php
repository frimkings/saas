<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

class OpticalPrescription extends Model
{
    use BelongsToClinic;

    protected $fillable = [
        'patient_id', 'refraction_id', 'created_by', 'source', 'prescriber_name',
        'prescriber_clinic', 'prescribed_at', 'measurements', 'notes',
        'verified_at', 'verified_by',
    ];

    protected $casts = [
        'measurements' => 'array',
        'prescribed_at' => 'date',
        'verified_at' => 'datetime',
    ];

    public function patient() { return $this->belongsTo(Patient::class); }
    public function refraction() { return $this->belongsTo(Refractions::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function orders() { return $this->hasMany(LensOrder::class, 'optical_prescription_id'); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }
}
