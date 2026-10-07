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

    /** The Laravel Cloud bucket for patient documents is attached under this disk name. */
    public const BUCKET_DISK = 'documents';

    /**
     * Where new uploads go. The server's own disk is wiped on every Laravel Cloud deploy, so
     * hosted installs use the private bucket once it is attached (Cloud adds the disk itself,
     * see Illuminate\Foundation\Cloud::configureDisks). Offline installs keep the local disk.
     * PATIENT_DOCUMENTS_DISK overrides both.
     */
    public static function uploadDisk(): string
    {
        if ($disk = config('filesystems.patient_documents_disk')) {
            return $disk;
        }

        return config('filesystems.disks.'.self::BUCKET_DISK.'.bucket') ? self::BUCKET_DISK : 'local';
    }

    /** Hosted installs still saving documents to the server's temporary disk (for the readiness check). */
    public static function hostedOnTemporaryDisk(): bool
    {
        return (bool) config('tenancy.enabled') && self::uploadDisk() === 'local';
    }

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
