<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An optical centre's invitation for a clinic to link. Sits between clinics: no tenant scope. */
class OpticalLinkInvite extends Model
{
    protected $fillable = ['optical_clinic_id', 'optical_partner_clinic_id', 'code', 'created_by', 'expires_at',
        'accepted_clinic_id', 'accepted_by', 'accepted_at', 'revoked_at'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function opticalClinic()
    {
        return $this->belongsTo(Clinic::class, 'optical_clinic_id');
    }

    /** The partner record lives in the optical centre's tenant, so read it without the clinic scope. */
    public function partner()
    {
        return $this->belongsTo(OpticalPartnerClinic::class, 'optical_partner_clinic_id')->withoutGlobalScopes();
    }

    public function isOpen(): bool
    {
        return ! $this->accepted_at && ! $this->revoked_at && $this->expires_at->isFuture();
    }
}
