<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * A drug prescribed for the patient to buy at a pharmacy (not stocked here, or out of stock).
 * Not a cart item: it is never billed, dispensed or counted in stock.
 */
class OutsidePrescription extends Model
{
    use BelongsToBranch;

    protected $guarded = ['id', 'clinic_id', 'branch_id'];

    protected $casts = ['duration_value' => 'integer'];

    public function patient() { return $this->belongsTo(Patient::class); }
    public function consultation() { return $this->belongsTo(Consultations::class, 'consultation_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function prescriber() { return $this->belongsTo(User::class, 'prescribed_by'); }

    public function durationLabel(): ?string
    {
        if ($this->duration_unit === 'until_finished') return 'Until finished';

        return $this->duration_value ? $this->duration_value . ' ' . ucfirst($this->duration_unit ?: 'days') : null;
    }
}
