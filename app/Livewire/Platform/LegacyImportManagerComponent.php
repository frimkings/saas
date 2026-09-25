<?php

namespace App\Livewire\Platform;

use App\Jobs\CommitLegacyClinicImport;
use App\Models\LegacyImportBatch;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LegacyClinicImportService;
use App\Services\LegacyImportAnalyzer;
use App\Services\LegacyImportCutoverService;
use App\Services\LegacyImportPostCutoverMonitor;
use App\Services\LegacyImportReadinessService;
use App\Services\LegacyImportEvidenceService;
use App\Services\PlatformAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class LegacyImportManagerComponent extends Component
{
    use WithFileUploads;

    public $sqlFile;
    public string $adminEmail = '';
    public string $clinicName = '';
    public string $clinicSlug = '';
    public string $branchCode = 'MAIN';
    public string $branchName = 'Main Branch';
    public string $timezone = 'UTC';
    public string $currency = 'GHS';
    public string $deploymentMode = 'hosted';
    public string $confirmation = '';
    public ?int $selectedBatchId = null;
    public ?int $planId = null;
    public array $conflictResolutions = [];
    public string $cutoverNotes = '';
    public string $rollbackConfirmation = '';
    public bool $showArchived = false;

    public function analyzeImport(LegacyImportAnalyzer $analyzer): void
    {
        $this->validate(['sqlFile' => 'required|file|max:204800', 'adminEmail' => 'required|email']);
        $name = $this->sqlFile->getClientOriginalName();
        if (! str_ends_with(strtolower($name), '.sql')) {
            $this->addError('sqlFile', 'Only .sql database dumps are accepted.');
            return;
        }
        $path = $this->sqlFile->store('legacy-imports', 'local');
        $fullPath = Storage::disk('local')->path($path);
        // Livewire keeps its temporary copy for a day; the dump is now stored, so drop the duplicate.
        $this->sqlFile->delete();

        try {
            $analysis = $analyzer->analyze($fullPath);
            $identities = collect($analysis['identities'] ?? [])->map(fn ($identity) => array_merge($identity, [
                'resolution' => $identity['existing'] ? 'merge' : (filter_var($identity['email'], FILTER_VALIDATE_EMAIL) ? 'create' : 'replace'),
            ]))->values()->all();
            $batch = LegacyImportBatch::create([
                'uuid' => Str::uuid(), 'created_by' => auth()->id(), 'original_filename' => $name,
                'stored_path' => $path, 'checksum' => hash_file('sha256', $fullPath), 'status' => 'analyzed',
                'clinic_name' => $analysis['clinic_name'], 'clinic_slug' => $analysis['suggested_slug'],
                'admin_email' => strtolower($this->adminEmail), 'analysis' => $analysis,
                'conflicts' => $identities, 'analyzed_at' => now(),
            ]);
            $this->selectBatch($batch->id);
            $this->reset('sqlFile');
            session()->flash('success', 'Upload and dry run complete. Review the analysis below before importing.');
            $this->dispatch('legacy-import-notice', icon: 'success', title: 'Upload successful', message: 'The SQL file has been uploaded and analyzed. Review the preflight results before importing.');
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            report($exception);
            $this->addError('sqlFile', 'The SQL dump could not be analyzed. Check that it is a valid legacy clinic export and try again.');
        }
    }

    public function refreshImport(): void
    {
        $batch = LegacyImportBatch::find($this->selectedBatchId);
        if (! $batch || in_array($batch->status, ['queued', 'importing'], true)) return;

        if ($batch->status === 'committed') {
            $this->dispatch('legacy-import-notice', icon: 'success', title: 'Import successful', message: 'The clinic data has been imported successfully. Review the validation report below.');
        } elseif ($batch->status === 'review_required') {
            $this->dispatch('legacy-import-notice', icon: 'warning', title: 'Import needs review', message: 'Processing has finished, but validation found issues. Review the report below before cutover.');
        } elseif ($batch->status === 'failed') {
            $this->dispatch('legacy-import-notice', icon: 'error', title: 'Import failed', message: 'The import could not finish. Review the error details below before retrying.');
        }
    }

    public function selectBatch(int $id): void
    {
        $batch = LegacyImportBatch::findOrFail($id);
        $this->selectedBatchId = $batch->id;
        $this->clinicName = $batch->clinic_name ?: '';
        $this->clinicSlug = $batch->clinic_slug ?: '';
        $this->adminEmail = $batch->admin_email ?: '';
        $this->planId = $batch->plan_id ?: SubscriptionPlan::where('is_active', 1)->value('id');
        $this->conflictResolutions = [];
        foreach ($batch->conflicts ?? [] as $index => $identity) {
            $this->conflictResolutions[$index] = [
                'action' => $identity['resolution'] ?? (($identity['existing'] ?? true) ? 'merge' : 'create'),
                'email' => $identity['replacement_email'] ?? '',
            ];
        }
        $this->confirmation = '';
        $this->rollbackConfirmation = '';
        $this->cutoverNotes = $batch->cutover_notes ?: '';
    }

    public function commitImport()
    {
        try {
            return $this->commit();
        } catch (\Throwable $exception) {
            $this->addError('commitImport', $exception->getMessage());
            return null;
        }
    }

    public function commit(): void
    {
        $batch = LegacyImportBatch::findOrFail($this->selectedBatchId);
        abort_unless(in_array($batch->status, ['analyzed','failed'], true) && ! $batch->committed_at, 422, 'This import is already queued, running, or complete.');
        $compatibility = $batch->analysis['compatibility'] ?? null;
        abort_if(is_array($compatibility) && ! ($compatibility['passed'] ?? false), 422, 'Preflight compatibility errors must be corrected before this import can be committed.');
        $data = $this->validate([
            'clinicName' => 'required|max:255', 'clinicSlug' => 'required|alpha_dash|unique:clinics,slug',
            'adminEmail' => 'required|email|unique:users,email', 'planId' => 'required|exists:subscription_plans,id',
            'branchCode' => 'required|max:30', 'branchName' => 'required|max:255', 'timezone' => 'required|timezone',
            'currency' => 'required|size:3', 'deploymentMode' => 'required|in:hosted,local', 'confirmation' => 'required',
        ]);
        abort_unless($this->confirmation === 'IMPORT '.$this->clinicSlug, 422, 'Type the exact confirmation phrase shown.');
        abort_if(LegacyImportBatch::where('id', '!=', $batch->id)
            ->whereIn('status', ['queued', 'importing'])
            ->where('clinic_slug', $this->clinicSlug)->exists(), 422,
            'An import for this clinic is already queued or running. Select that batch to check its progress.');

        $decisions = [];
        $replacementEmails = [];
        foreach ($batch->conflicts ?? [] as $index => $identity) {
            $resolution = $this->conflictResolutions[$index] ?? [];
            $action = $resolution['action'] ?? 'create';
            abort_unless(in_array($action, ['create','merge','replace','skip'], true), 422, 'Select a valid action for every identity.');
            $replacement = strtolower(trim((string) ($resolution['email'] ?? '')));
            if ($action === 'merge') abort_unless($identity['existing'] ?? false, 422, 'Only an existing identity can be merged.');
            if ($action === 'create' && ($identity['existing'] ?? false)) abort(422, 'Choose Merge, Replace Email, or Skip for '.$identity['email'].'.');
            if ($action === 'create') abort_unless(filter_var($identity['email'], FILTER_VALIDATE_EMAIL), 422, 'Enter a replacement email for legacy user '.$identity['legacy_id'].'.');
            if ($action === 'replace') {
                abort_unless(filter_var($replacement, FILTER_VALIDATE_EMAIL), 422, 'Enter a valid replacement email for '.$identity['email'].'.');
                abort_if(User::whereRaw('LOWER(email) = ?', [$replacement])->exists() || in_array($replacement, $replacementEmails, true), 422, 'Replacement email '.$replacement.' is already in use.');
                $replacementEmails[] = $replacement;
            }
            $decisions[(string) $identity['legacy_id']] = ['action' => $action, 'email' => $replacement];
        }

        $options = [
            'clinic_name' => $data['clinicName'], 'clinic_slug' => $data['clinicSlug'],
            'admin_email' => strtolower($data['adminEmail']), 'plan_id' => $data['planId'],
            'branch_code' => $data['branchCode'], 'branch_name' => $data['branchName'],
            'timezone' => $data['timezone'], 'currency' => strtoupper($data['currency']),
            'deployment_mode' => $data['deploymentMode'], 'conflicts' => $decisions,
        ];
        $batch->update([
            'status' => 'queued', 'error' => null, 'progress_percent' => 0, 'current_table' => 'queued',
            'processed_rows' => 0, 'total_rows' => array_sum($batch->analysis['counts'] ?? []),
            'finished_at' => null, 'result' => ['import_options' => $options],
            'conflicts' => collect($batch->conflicts ?? [])->map(function ($identity, $index) {
                $resolution = $this->conflictResolutions[$index] ?? [];
                return array_merge($identity, ['resolution' => $resolution['action'] ?? 'create', 'replacement_email' => $resolution['email'] ?? null]);
            })->all(),
        ]);
        CommitLegacyClinicImport::dispatch($batch->id)->onQueue('imports');
        $this->refreshImport();
    }

    public function rollback(int $id, LegacyClinicImportService $service, PlatformAuditService $audit): void
    {
        $batch = LegacyImportBatch::findOrFail($id);
        if ($batch->cutover_approved_at) {
            $this->addError('rollback', 'An approved cutover cannot be rolled back. Follow the production recovery procedure.');
            return;
        }
        if ($this->rollbackConfirmation !== 'ROLLBACK '.$batch->clinic_slug) {
            $this->addError('rollback', 'Type the exact confirmation phrase shown.');
            return;
        }
        try {
            $summary = $service->rollback($batch);
        } catch (\Throwable $exception) {
            $this->addError('rollback', $exception->getMessage());
            return;
        }
        // The clinic no longer exists, so the audit entry carries its identity in the payload instead of the foreign key.
        // The batch and everything it created are gone; this compact entry is the only trace kept.
        $audit->record('legacy_import.rolled_back', null, ['clinic_name' => $batch->clinic_name, 'clinic_slug' => $batch->clinic_slug, 'original_filename' => $batch->original_filename], ['deleted' => $summary['deleted'], 'deleted_user_count' => count($summary['deleted_user_ids'])]);
        $this->rollbackConfirmation = '';
        $this->selectedBatchId = null;
        session()->flash('success', 'Import rolled back. '.$batch->clinic_name.' and its imported records were removed.'
            .($summary['file_cleanup_errors'] ? ' Some stored files could not be deleted and were logged for follow-up: '.implode(', ', $summary['file_cleanup_errors']) : ''));
    }

    public function approveCutover(LegacyImportCutoverService $cutover, PlatformAuditService $audit): void
    {
        $batch=LegacyImportBatch::findOrFail($this->selectedBatchId);
        $checklist=$cutover->approve($batch,(int)auth()->id(),$this->cutoverNotes);
        $audit->record('legacy_import.cutover_approved',$batch->clinic_id,[],['batch_id'=>$batch->id,'checklist'=>$checklist],$this->cutoverNotes);
        session()->flash('success','Clinic import approved for cutover.');
    }

    public function runPostCutoverMonitoring(LegacyImportPostCutoverMonitor $monitor, PlatformAuditService $audit): void
    {
        $batch=LegacyImportBatch::findOrFail($this->selectedBatchId);
        $report=$monitor->run($batch,(int)auth()->id());
        $audit->record('legacy_import.monitoring_run',$batch->clinic_id,[],['batch_id'=>$batch->id,'report'=>$report]);
        session()->flash('success',$report['passed']?'Post-cutover monitoring passed.':'Monitoring found issues requiring attention.');
    }

    public function runReadinessChecklist(LegacyImportReadinessService $readiness, LegacyImportEvidenceService $evidence, PlatformAuditService $audit): void
    {
        $batch=LegacyImportBatch::findOrFail($this->selectedBatchId);$report=$readiness->run($batch);$evidence->archive($batch);
        $audit->record('legacy_import.readiness_checked',$batch->clinic_id,[],['batch_id'=>$batch->id,'report'=>$report]);
        session()->flash('success',$report['passed']?'Operational readiness passed.':'Readiness checks found issues requiring attention.');
    }

    public function archiveEvidence(LegacyImportEvidenceService $evidence, PlatformAuditService $audit): void
    {
        $batch=LegacyImportBatch::findOrFail($this->selectedBatchId);$evidence->archive($batch);
        $audit->record('legacy_import.evidence_archived',$batch->clinic_id,[],['batch_id'=>$batch->id,'checksum'=>$batch->fresh()->evidence_checksum]);
        session()->flash('success','Source evidence archive refreshed and integrity checksum recorded.');
    }

    public function closeMigration(LegacyImportPostCutoverMonitor $monitor, PlatformAuditService $audit): void
    {
        $batch=LegacyImportBatch::findOrFail($this->selectedBatchId);
        $monitor->close($batch);
        $audit->record('legacy_import.migration_closed',$batch->clinic_id,[],['batch_id'=>$batch->id]);
        session()->flash('success','Legacy migration lifecycle closed successfully.');
    }

    public function discard(int $id, LegacyClinicImportService $service): void
    {
        $service->purge(LegacyImportBatch::whereNull('committed_at')->findOrFail($id));
        if ($this->selectedBatchId === $id) $this->selectedBatchId = null;
    }

    // Leftover batches from before discard/rollback became permanent.
    public function purge(int $id, LegacyClinicImportService $service): void
    {
        $service->purge(LegacyImportBatch::findOrFail($id));
        if ($this->selectedBatchId === $id) $this->selectedBatchId = null;
    }

    // A due job that no worker has reserved for 30 seconds means nothing is processing the imports queue.
    private function importJobIsWaiting(): bool
    {
        if (config('queue.default') !== 'database') return false;
        return DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', 'imports')->whereNull('reserved_at')
            ->where('available_at', '<', now()->subSeconds(30)->getTimestamp())->exists();
    }

    public function archive(int $id, PlatformAuditService $audit): void
    {
        $batch = LegacyImportBatch::whereNull('archived_at')->findOrFail($id);
        abort_if(in_array($batch->status, ['queued', 'importing'], true), 422, 'An import that is queued or running cannot be removed from the list.');
        $batch->update(['archived_at' => now(), 'archived_by' => auth()->id()]);
        $audit->record('legacy_import.archived', $batch->clinic_id, [], ['batch_id' => $batch->id, 'status' => $batch->status]);
        if ($this->selectedBatchId === $id && ! $this->showArchived) $this->selectedBatchId = null;
    }

    public function restore(int $id, PlatformAuditService $audit): void
    {
        $batch = LegacyImportBatch::whereNotNull('archived_at')->findOrFail($id);
        $batch->update(['archived_at' => null, 'archived_by' => null]);
        $audit->record('legacy_import.restored', $batch->clinic_id, [], ['batch_id' => $batch->id]);
    }

    public function render()
    {
        $selected=$this->selectedBatchId ? LegacyImportBatch::with(['cutoverApprover','monitoringChecker'])->find($this->selectedBatchId) : null;
        return view('livewire.platform.legacy-import-manager-component', [
            'batches' => LegacyImportBatch::with(['clinic','creator'])->when(! $this->showArchived, fn ($query) => $query->whereNull('archived_at'))->latest()->get(),
            'archivedCount' => LegacyImportBatch::whereNotNull('archived_at')->count(),
            'selected' => $selected,
            'workerMissing' => $selected && in_array($selected->status, ['queued', 'importing'], true) && $this->importJobIsWaiting(),
            'cutoverChecklist' => $selected && $selected->committed_at ? app(LegacyImportCutoverService::class)->checklist($selected) : null,
            'plans' => SubscriptionPlan::where('is_active', 1)->get(),
        ])->layout('layouts.platform');
    }
}
