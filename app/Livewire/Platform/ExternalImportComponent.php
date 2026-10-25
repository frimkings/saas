<?php

namespace App\Livewire\Platform;

use App\Jobs\RunExternalImport;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ExternalImportBatch;
use App\Services\ExternalImport\CloudifyImport;
use App\Services\ExternalImport\ExternalImportRollback;
use App\Services\PlatformAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use ZipArchive;

/**
 * Legacy Imports → From other software: brings a prospect's records from another vendor's
 * software (Cloudify first) into a clinic that was already set up through Clinics → Onboard new clinic.
 * Upload → check (nothing written) → match staff → import in the background → undo if needed.
 */
class ExternalImportComponent extends Component
{
    use WithFileUploads;

    private const MAX_FILES = 30;
    private const MAX_KB = 20480;

    public ?int $clinicId = null;
    public string $source = CloudifyImport::SOURCE;
    public $sheetFiles = [];
    public ?int $selectedBatchId = null;
    public ?int $branchId = null;
    public array $staff = [];
    public array $include = [];
    public string $confirmation = '';
    public string $undoConfirmation = '';

    public function checkFiles(CloudifyImport $import): void
    {
        $this->validate([
            'clinicId' => 'required|integer|exists:clinics,id', 'source' => 'required|in:'.implode(',', array_keys(ExternalImportBatch::SOURCES)),
            'sheetFiles' => 'required|array|min:1|max:'.self::MAX_FILES, 'sheetFiles.*' => 'file|max:'.self::MAX_KB,
        ], [], ['clinicId' => 'clinic', 'sheetFiles' => 'files', 'sheetFiles.*' => 'file']);
        foreach ($this->sheetFiles as $file) {
            if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'zip'], true)) {
                $this->addError('sheetFiles', $file->getClientOriginalName().' is not an .xlsx or .zip file.');
                return;
            }
        }

        $uuid = (string) Str::uuid();
        $dir = 'external-imports/'.$uuid;
        try {
            $stored = $this->storeFiles($dir);
            $batch = new ExternalImportBatch([
                'uuid' => $uuid, 'clinic_id' => $this->clinicId, 'source' => $this->source, 'status' => 'analyzed',
                'stored_dir' => $dir, 'files' => $stored, 'created_by' => auth()->id(),
            ]);
            $batch->analysis = $import->analyze($batch);
            $batch->save();
        } catch (\Throwable $e) {
            Storage::disk('local')->deleteDirectory($dir);
            report($e);
            $this->addError('sheetFiles', $e instanceof \RuntimeException ? $e->getMessage() : 'The files could not be read. Check they are the .xlsx files exported from Cloudify.');
            return;
        }
        foreach ($this->sheetFiles as $file) $file->delete();
        $this->reset('sheetFiles');
        $this->selectBatch($batch->id);
        session()->flash('success', 'Files checked. Nothing has been imported yet: review the report, match the staff, then import.');
    }

    /** Uploaded .xlsx files are kept as they are; a .zip contributes the .xlsx files inside it. */
    private function storeFiles(string $dir): array
    {
        $disk = Storage::disk('local');
        $stored = [];
        $add = function (string $name, $contents) use (&$stored, $dir, $disk) {
            if (count($stored) >= self::MAX_FILES) throw new \RuntimeException('Upload at most '.self::MAX_FILES.' spreadsheets at a time.');
            $path = $dir.'/'.(count($stored) + 1).'.xlsx';
            $disk->put($path, $contents);
            $stored[] = ['name' => Str::limit(basename(str_replace('\\', '/', $name)), 150, ''), 'path' => $path];
        };
        foreach ($this->sheetFiles as $file) {
            // Excel leaves a small "~$name.xlsx" lock file beside a workbook that is open; it holds no data.
            if (str_starts_with($file->getClientOriginalName(), '~$')) continue;
            if (strtolower($file->getClientOriginalExtension()) === 'xlsx') { $add($file->getClientOriginalName(), file_get_contents($file->getRealPath())); continue; }
            $zip = new ZipArchive();
            if ($zip->open($file->getRealPath()) !== true) throw new \RuntimeException($file->getClientOriginalName().' is not a readable .zip file.');
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->statIndex($i);
                    $name = $entry['name'];
                    if (! str_ends_with(strtolower($name), '.xlsx') || str_contains($name, '__MACOSX') || str_starts_with(basename($name), '~$')) continue;
                    if ($entry['size'] > self::MAX_KB * 1024) throw new \RuntimeException(basename($name).' inside the .zip is larger than '.(self::MAX_KB / 1024).' MB.');
                    $add($name, $zip->getFromIndex($i));
                }
            } finally {
                $zip->close();
            }
        }
        if (! $stored) throw new \RuntimeException('No .xlsx spreadsheets were found in the upload.');

        return $stored;
    }

    public function selectBatch(int $id): void
    {
        $batch = ExternalImportBatch::findOrFail($id);
        $this->selectedBatchId = $batch->id;
        $this->branchId = $batch->branch_id ?: Branch::where('clinic_id', $batch->clinic_id)->orderByDesc('is_default')->orderBy('id')->value('id');
        $users = $this->clinicUsers($batch->clinic_id);
        $this->staff = [];
        foreach ($batch->analysis['staff'] ?? [] as $person) {
            $saved = $batch->settings['staff'][$person['key']] ?? null;
            $match = $users->first(fn ($u) => $person['kind'] === 'email' ? strtolower($u->email) === $person['label'] : mb_strtolower($u->name) === mb_strtolower($person['label']));
            $this->staff[md5($person['key'])] = (string) ($saved ?? $match?->id ?? '');
        }
        $this->include = array_map(fn ($key) => (bool) ($batch->settings['include'][$key] ?? true), array_combine(array_keys(CloudifyImport::OPTIONAL), array_keys(CloudifyImport::OPTIONAL)));
        $this->confirmation = $this->undoConfirmation = '';
        $this->resetErrorBag();
    }

    public function closeBatch(): void
    {
        $this->selectedBatchId = null;
    }

    public function startImport(PlatformAuditService $audit): void
    {
        $batch = ExternalImportBatch::with('clinic')->findOrFail($this->selectedBatchId);
        if (! in_array($batch->status, ['analyzed', 'failed'], true) || ! $batch->stored_dir) { $this->addError('import', 'This import has already run or its files are gone. Upload the files again.'); return; }
        if ($batch->analysis['errors'] ?? []) { $this->addError('import', 'Fix the problems with the files first, then upload them again.'); return; }
        if ($this->blocker($batch)) { $this->addError('import', $this->blocker($batch)); return; }
        if ($this->confirmation !== 'IMPORT '.$batch->clinic->slug) { $this->addError('confirmation', 'Type the exact phrase shown.'); return; }
        if (! Branch::where('clinic_id', $batch->clinic_id)->whereKey($this->branchId)->exists()) { $this->addError('branchId', 'Choose one of the clinic\'s branches.'); return; }

        $userIds = $this->clinicUsers($batch->clinic_id)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $staff = [];
        foreach ($batch->analysis['staff'] ?? [] as $person) {
            $choice = (string) ($this->staff[md5($person['key'])] ?? '');
            if ($choice !== '' && ! in_array($choice, $userIds, true)) { $this->addError('staff', 'Choose staff from this clinic only.'); return; }
            $staff[$person['key']] = $choice === '' ? null : (int) $choice;
        }

        $batch->update([
            'status' => 'queued', 'branch_id' => $this->branchId, 'error' => null, 'progress_percent' => 0, 'current_step' => 'queued', 'result' => null,
            'settings' => ['staff' => $staff, 'include' => array_map('boolval', $this->include)],
        ]);
        $audit->record('external_import.started', $batch->clinic_id, [], ['batch_id' => $batch->id, 'source' => $batch->source, 'patients' => $batch->analysis['patients']['total'] ?? 0, 'include' => $this->include]);
        $this->confirmation = '';
        RunExternalImport::dispatch($batch->id)->onQueue('imports');
    }

    public function undoImport(ExternalImportRollback $rollback, PlatformAuditService $audit): void
    {
        $batch = ExternalImportBatch::with('clinic')->findOrFail($this->selectedBatchId);
        if (! in_array($batch->status, ['completed', 'failed'], true)) { $this->addError('undo', 'Only a finished or failed import can be undone.'); return; }
        if ($this->undoConfirmation !== 'UNDO '.$batch->clinic->slug) { $this->addError('undoConfirmation', 'Type the exact phrase shown.'); return; }
        try {
            $deleted = $rollback->undo($batch);
        } catch (\RuntimeException $e) {
            $this->addError('undo', $e->getMessage());
            return;
        }
        $audit->record('external_import.undone', $batch->clinic_id, [], ['batch_id' => $batch->id, 'source' => $batch->source, 'deleted' => $deleted]);
        $this->undoConfirmation = '';
        session()->flash('success', 'The import was undone: '.number_format(array_sum($deleted)).' imported records were removed.');
    }

    /** Removes a batch that wrote nothing (checked only, failed and cleaned up, or undone), with its files. */
    public function discard(int $id, ExternalImportRollback $rollback): void
    {
        $batch = ExternalImportBatch::findOrFail($id);
        $hasRows = DB::table('external_import_rows')->where('external_import_batch_id', $batch->id)->exists();
        if (! in_array($batch->status, ['analyzed', 'failed', 'rolled_back'], true) || $hasRows) { $this->addError('discard', 'Undo this import before removing it.'); return; }
        $rollback->deleteFiles($batch);
        $batch->delete();
        if ($this->selectedBatchId === $id) $this->selectedBatchId = null;
    }

    /** Why this clinic can't take the import now, or null. */
    private function blocker(ExternalImportBatch $batch): ?string
    {
        $other = ExternalImportBatch::where('clinic_id', $batch->clinic_id)->where('id', '!=', $batch->id)->whereIn('status', ExternalImportBatch::ACTIVE)->first();
        if ($other) return $other->status === 'completed'
            ? 'This clinic already has an import from '.$other->created_at->format('d M Y').'. Undo it first to import a newer export, or the records would be doubled.'
            : 'Another import for this clinic is queued or running.';

        return null;
    }

    private function clinicUsers(int $clinicId)
    {
        return DB::table('users')->join('clinic_user', 'clinic_user.user_id', '=', 'users.id')
            ->where('clinic_user.clinic_id', $clinicId)->where('clinic_user.status', 'active')
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'clinic_user.clinic_role']);
    }

    public function render()
    {
        $selected = $this->selectedBatchId ? ExternalImportBatch::with(['clinic', 'creator:id,name'])->find($this->selectedBatchId) : null;
        if ($this->selectedBatchId && ! $selected) $this->selectedBatchId = null;

        return view('livewire.platform.external-import-component', [
            'clinics' => Clinic::orderBy('name')->get(['id', 'name', 'slug', 'status']),
            'batches' => ExternalImportBatch::with('clinic:id,name')->latest()->limit(20)->get(),
            'selected' => $selected,
            'users' => $selected ? $this->clinicUsers($selected->clinic_id) : collect(),
            'branches' => $selected ? Branch::where('clinic_id', $selected->clinic_id)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']) : collect(),
            'blocker' => $selected && in_array($selected->status, ['analyzed', 'failed'], true) ? $this->blocker($selected) : null,
        ]);
    }
}
