<?php

namespace App\Console\Commands;

use App\Models\PatientDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Patient documents whose file is gone, e.g. those saved to a hosted server's own disk before
 * the private bucket was attached (that disk is wiped on every deploy). Lists them by clinic so
 * they can be uploaded again.
 */
class ListMissingPatientDocumentsCommand extends Command
{
    protected $signature = 'documents:missing';
    protected $description = 'List patient documents whose stored file no longer exists';

    public function handle(): int
    {
        $missing = [];
        PatientDocument::withoutGlobalScopes()->with(['patient' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('id')->chunkById(200, function ($documents) use (&$missing) {
                foreach ($documents as $document) {
                    try {
                        $exists = Storage::disk($document->storage_disk)->exists($document->file_path);
                    } catch (\Throwable) {
                        $exists = false;
                    }
                    if (! $exists) {
                        $missing[] = [$document->clinic_id ?? '—', $document->id, $document->patient?->name ?? '—',
                            $document->title, $document->storage_disk, optional($document->created_at)->format('Y-m-d')];
                    }
                }
            });

        if (! $missing) {
            $this->info('Every patient document has its file.');
            return self::SUCCESS;
        }

        $this->table(['Clinic', 'Document', 'Patient', 'Title', 'Disk', 'Uploaded'], $missing);
        $this->warn(count($missing).' document(s) need uploading again.');

        return self::SUCCESS;
    }
}
