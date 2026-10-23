<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/**
 * A drug prescribed in the clinic's previous software, listed in the Prescription tab for reference.
 * Not a cart item: it is never dispensed, billed or counted in stock.
 */
class PatientLegacyMedication extends Model
{
    use BelongsToClinic;

    protected $guarded = [];

    protected $casts = ['prescribed_on' => 'date'];

    public function patient() { return $this->belongsTo(Patient::class); }
}
