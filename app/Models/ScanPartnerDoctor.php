<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** A doctor at a partner clinic, for "who referred" on scan orders. Commission goes to the clinic. */
class ScanPartnerDoctor extends Model
{
    use BelongsToClinic;

    protected $fillable = ['scan_partner_clinic_id', 'name', 'phone', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function partner()
    {
        return $this->belongsTo(ScanPartnerClinic::class, 'scan_partner_clinic_id');
    }
}
