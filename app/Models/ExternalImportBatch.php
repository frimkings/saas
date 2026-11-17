<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One upload of another vendor's export into an existing clinic (see App\Services\ExternalImport). */
class ExternalImportBatch extends Model
{
    public const SOURCES = ['cloudify' => 'Cloudify (ECMS)', 'itestemr' => 'iTestEMR (Optocodes)'];

    /** The importer class for each source; each has SOURCE, SHEETS, OPTIONAL, analyze(), commit() and flush(). */
    public const IMPORTERS = [
        'cloudify' => \App\Services\ExternalImport\CloudifyImport::class,
        'itestemr' => \App\Services\ExternalImport\ITestEmrImport::class,
    ];

    /** File extensions each source's upload takes (a .zip of them is always accepted). */
    public const FILE_TYPES = ['cloudify' => ['xlsx'], 'itestemr' => ['csv', 'html', 'htm']];

    public static function importerFor(string $source): object
    {
        return app(self::IMPORTERS[$source] ?? throw new \InvalidArgumentException('Unknown import source '.$source));
    }

    public function importer(): object
    {
        return self::importerFor($this->source);
    }

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
