<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment recorded in the clinic's previous software, kept for reference on the patient record.
 * Not a sale: it never appears in bills, receipts, the cash book or finance reports.
 */
class PatientLegacyPayment extends Model
{
    use BelongsToClinic;

    protected $guarded = [];

    protected $casts = ['paid_at' => 'datetime', 'amount' => 'decimal:2'];

    public function patient() { return $this->belongsTo(Patient::class); }

    public function sourceLabel(): string
    {
        return ExternalImportBatch::SOURCES[$this->source] ?? ucfirst($this->source);
    }
}
