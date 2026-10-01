<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToClinic;

class Insurer extends Model
{
    use SoftDeletes, BelongsToClinic;

    protected $fillable = [
        'name', 'code', 'scheme_type', 'contact_person',
        'contact_phone', 'notes', 'active',
        'patient_pays_difference', 'shortfall_action',
    ];

    public const SHORTFALL_ACTIONS = [
        'bill_patient' => 'Bill the patient',
        'write_off'    => 'Clinic absorbs it (write off)',
    ];

    protected $casts = [
        'active'                  => 'boolean',
        'patient_pays_difference' => 'boolean',
    ];

    public function coverageRules(): HasMany
    {
        return $this->hasMany(InsurerCoverageRule::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sales::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }

    public function schemeBadgeClass(): string
    {
        return match($this->scheme_type) {
            'NHIS'      => 'badge-success',
            'Private'   => 'badge-primary',
            'Corporate' => 'badge-info',
            default     => 'badge-secondary',
        };
    }
}
