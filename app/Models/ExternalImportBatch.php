<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One upload of another vendor's export into an existing clinic (see App\Services\ExternalImport). */
class ExternalImportBatch extends Model
{
    public const SOURCES = ['cloudify' => 'Cloudify (ECMS)'];

    /** Imports that hold the clinic's place: a second one would duplicate its records. */
    public const ACTIVE = ['queued', 'importing', 'completed'];

    protected $guarded = [];

    protected $casts = [
        'files' => 'array', 'analysis' => 'array', 'settings' => 'array', 'result' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'rolled_back_at' => 'datetime',
    ];

    public function clinic() { return $this->belongsTo(Clinic::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? ucfirst($this->source);
    }
}
