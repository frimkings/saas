<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** A commission rate agreed with one partner for one scan, used instead of the catalogue's. */
class ScanPartnerRate extends Model
{
    use BelongsToClinic;

    protected $fillable = ['scan_partner_clinic_id', 'diagnostic_scan_id', 'commission_type', 'commission_value'];

    protected $casts = ['commission_value' => 'decimal:2'];

    public function partner()
    {
        return $this->belongsTo(ScanPartnerClinic::class, 'scan_partner_clinic_id');
    }

    public function scan()
    {
        return $this->belongsTo(DiagnosticScan::class, 'diagnostic_scan_id');
    }
}
