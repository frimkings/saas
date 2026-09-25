<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Models\Concerns\BelongsToBranch;

class PatientDocument extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'patient_id',
        'consultation_id',
        'uploaded_by',
        'document_type',
        'title',
        'notes',
        'file_path',
        'storage_disk',
        'original_name',
        'mime_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function consultation()
    {
        return $this->belongsTo(Consultations::class, 'consultation_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return route('patient-documents.show', $this);
    }
}
