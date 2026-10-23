<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** The clinic's product that a drug name from its previous software stands for (see App\Support\LegacyMedicationProducts). */
class LegacyMedicationMatch extends Model
{
    use BelongsToClinic;

    protected $guarded = [];

    public function product() { return $this->belongsTo(Product::class); }
}
