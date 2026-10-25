<x-platform.page active="imports" tour="platform.imports-other" title="Legacy Imports" subtitle="Bring a clinic's records over from the software they used before.">
    <style>.xi-files td,.xi-files th{vertical-align:top}.xi-list{margin:6px 0 0;padding-left:18px;color:#aebbd0}.xi-list li{margin:3px 0}.xi-progress{height:8px;border-radius:99px;background:#1b2a42;overflow:hidden;margin:10px 0 4px}.xi-progress span{display:block;height:100%;background:#38bdf8;transition:width .4s}.xi-options{display:grid;gap:8px;margin-top:6px}.xi-options label{display:flex;gap:8px;align-items:flex-start}</style>
    @include('livewire.platform.partials.import-tabs', ['tab' => 'other'])
    <x-ui.flash />
    @foreach(['discard', 'import', 'undo', 'staff'] as $key)@error($key)<div class="ui-alert ui-alert--danger">{{ $message }}</div>@enderror @endforeach

    <form class="ui-card" wire:submit="checkFiles" data-tour="xi-upload"
          x-data="{ sending: false, progress: 0 }" x-on:livewire-upload-start="sending = true; progress = 0" x-on:livewire-upload-finish="sending = false"
          x-on:livewire-upload-error="sending = false" x-on:livewire-upload-progress="progress = $event.detail.progress">
        <div class="ui-card-head"><div><h2>New import</h2><p>Set the clinic up through Clinics → Onboard new clinic first, then bring their old records into it. Checking the files writes nothing to the clinic.</p></div></div>
        <div class="ui-row">
            <label class="ui-field"><span>Clinic to import into</span>
                <select class="ui-input" wire:model="clinicId"><option value="">Choose a clinic…</option>@foreach($clinics as $clinic)<option value="{{ $clinic->id }}">{{ $clinic->name }}{{ $clinic->status !== 'active' ? ' ('.$clinic->status.')' : '' }}</option>@endforeach</select>
                @error('clinicId')<small class="ui-error">{{ $message }}</small>@enderror
            </label>
            <label class="ui-field"><span>Their previous software</span>
                <select class="ui-input" wire:model="source">@foreach(\App\Models\ExternalImportBatch::SOURCES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                <small class="ui-hint">Cloudify's backup copy: one .xlsx per table (…PATIENTS_DETAILS…, …BU_PAYMENTS…).</small>
            </label>
            <label class="ui-field"><span>Exported files</span>
                <input class="ui-input" type="file" wire:model="sheetFiles" multiple accept=".xlsx,.zip">
                <small class="ui-hint">Select all the .xlsx files at once, or one .zip of them. Up to 30 files, 20 MB each.</small>
                @error('sheetFiles')<small class="ui-error">{{ $message }}</small>@enderror
                @error('sheetFiles.*')<small class="ui-error">{{ $message }}</small>@enderror
            </label>
        </div>
        <div x-show="sending" x-cloak style="display:none" class="xi-progress" aria-hidden="true"><span :style="`width:${progress}%`"></span></div>
        <div class="ui-actions" style="margin-top:12px">
            <button type="submit" class="ui-button ui-button-primary" wire:loading.attr="disabled" wire:target="checkFiles,sheetFiles" x-bind:disabled="sending">
                <span wire:loading.remove wire:target="checkFiles">Check files</span><span wire:loading wire:target="checkFiles">Checking…</span>
            </button>
        </div>
    </form>

    @if($selected)
        @php($a = $selected->analysis ?? [])
        <section class="ui-card" @if(in_array($selected->status, ['queued', 'importing'], true)) wire:poll.2s @endif>
            <div class="ui-card-head">
                <div><h2>{{ $selected->clinic?->name ?? 'Deleted clinic' }} · {{ $selected->sourceLabel() }}</h2>
                    <p>Checked {{ $selected->created_at->format('d M Y H:i') }}{{ $selected->creator ? ' by '.$selected->creator->name : '' }}.
                        @if(($a['dates'][0] ?? null))Records from {{ \Carbon\Carbon::parse($a['dates'][0])->format('d M Y') }} to {{ \Carbon\Carbon::parse($a['dates'][1])->format('d M Y') }}.@endif</p></div>
                <div class="ui-actions">@include('livewire.platform.partials.external-import-status', ['batch' => $selected])<button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="closeBatch">Close</button></div>
            </div>

            <div class="ui-tiles ui-tiles--auto" data-tour="xi-summary">
                <x-ui.stat-tile label="Patients" :value="number_format($a['patients']['total'] ?? 0)" :hint="($a['patients']['already_in_clinic'] ?? 0) ? number_format($a['patients']['already_in_clinic']).' already in the clinic, kept apart' : 'New patient files'" />
                <x-ui.stat-tile label="Visits" :value="number_format($a['visits'] ?? 0)" hint="One consultation per patient per day" />
                <x-ui.stat-tile label="Old payments" :value="($selected->clinic?->default_currency ?? 'GHS').' '.number_format($a['payments']['total'] ?? 0, 2)" :hint="number_format($a['payments']['count'] ?? 0).' payments, shown on the patient record only'" />
                <x-ui.stat-tile label="Expense history" :value="($selected->clinic?->default_currency ?? 'GHS').' '.number_format($a['expenses']['total'] ?? 0, 2)" :hint="number_format($a['expenses']['count'] ?? 0).' entries'" />
            </div>

            @if($a['errors'] ?? [])
                <div class="ui-alert ui-alert--danger" style="margin-top:14px"><strong>These files can't be imported yet.</strong><ul class="xi-list">@foreach($a['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif

            <h3 style="margin:18px 0 8px;font-size:14px">What goes where</h3>
            <div class="ui-table-wrap ui-table-wrap--framed" data-tour="xi-sheets">
                <table class="ui-table xi-files">
                    <thead><tr><th>Cloudify table</th><th>File</th><th style="text-align:right">Rows</th><th>Goes into</th></tr></thead>
                    <tbody>
                    @foreach(array_intersect_key(array_replace(\App\Services\ExternalImport\CloudifyImport::SHEETS, $a['sheets'] ?? []), $a['sheets'] ?? []) as $key => $sheet)
                        <tr><td>{{ $sheet['label'] }}</td>
                            <td>{{ $sheet['file'] ?? '—' }}@if($sheet['file'] === null)<br><small class="warn">Not uploaded</small>@endif</td>
                            <td style="text-align:right">{{ $sheet['rows'] === null ? '—' : number_format($sheet['rows']) }}@if($a['orphans'][$key] ?? 0)<br><small class="warn">{{ number_format($a['orphans'][$key]) }} for unknown patients, skipped</small>@endif</td>
                            <td>{{ $sheet['into'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @php($notes = array_filter([
                ($a['patients']['no_dob'] ?? 0) ? number_format($a['patients']['no_dob']).' patients have no date of birth.' : null,
                ($a['patients']['no_gender'] ?? 0) ? number_format($a['patients']['no_gender']).' patients have no gender, so they come in as Other. Correct it when they next visit.' : null,
                ($a['patients']['no_phone'] ?? 0) ? number_format($a['patients']['no_phone']).' patients have no phone number.' : null,
                ($a['patients']['renumbered'] ?? 0) ? number_format($a['patients']['renumbered']).' patients have a missing or repeated Cloudify number, so they get CLD-… numbers instead.' : null,
                ($a['items']['negative_stock'] ?? 0) ? number_format($a['items']['negative_stock']).' items show negative stock in Cloudify. Products come in with prices only, stock 0, cost 0, batch CLD-… and an expiry two years ahead: set real costs, batches and expiry dates at the stock count when the clinic goes live.' : null,
                ($a['future_appointments'] ?? 0) ? number_format($a['future_appointments']).' appointments are in the future and will get the clinic\'s usual reminder SMS. Past ones come in with reminders off.' : null,
                'Payments come in as a history list on each patient record. They are not bills or sales and do not appear in receipts, the cash book or finance reports.',
                'Cloudify exports no invoices, so what patients still owe from the old software is not known here.',
                $a['duplicate_files'] ?? [] ? 'Ignored as exact copies of another file: '.implode(', ', $a['duplicate_files']).'.' : null,
                $a['unknown_files'] ?? [] ? 'Not recognised as Cloudify tables, left out: '.implode(', ', $a['unknown_files']).'.' : null,
            ]))
            <div class="ui-alert ui-alert--warning" style="margin-top:14px"><strong>Good to know</strong><ul class="xi-list">@foreach($notes as $note)<li>{{ $note }}</li>@endforeach</ul></div>

            @if(in_array($selected->status, ['analyzed', 'failed'], true) && ! ($a['errors'] ?? []))
                @if($selected->status === 'failed' && $selected->error)<div class="ui-alert ui-alert--danger" style="margin-top:14px"><strong>The last run failed.</strong> {{ $selected->error }}</div>@endif
                <form wire:submit="startImport" style="margin-top:18px">
                    @if($a['staff'] ?? [])
                        <h3 style="margin:0 0 4px;font-size:14px">Match their staff</h3>
                        <p class="ui-hint" style="margin:0 0 8px">Who recorded each entry in Cloudify. Anyone left as "Clinic owner" is recorded under the clinic's owner; a doctor left unmatched leaves the appointment without a doctor. Add missing staff to the clinic first if you want them matched.</p>
                        <div class="ui-table-wrap ui-table-wrap--framed" data-tour="xi-staff">
                            <table class="ui-table">
                                <thead><tr><th>In Cloudify</th><th style="text-align:right">Entries</th><th>Clinic account</th></tr></thead>
                                <tbody>
                                @foreach($a['staff'] as $person)
                                    <tr wire:key="xi-staff-{{ md5($person['key']) }}"><td>{{ $person['label'] }} <small class="ui-hint">{{ $person['kind'] === 'doctor' ? 'doctor on appointments' : 'recorded entries' }}</small></td>
                                        <td style="text-align:right">{{ number_format($person['rows']) }}</td>
                                        <td><select class="ui-input" wire:model="staff.{{ md5($person['key']) }}" aria-label="Clinic account for {{ $person['label'] }}">
                                            <option value="">{{ $person['kind'] === 'doctor' ? 'No doctor' : 'Clinic owner' }}</option>
                                            @foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }} · {{ $user->email }}</option>@endforeach
                                        </select></td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="ui-row" style="margin-top:14px" data-tour="xi-options">
                        <label class="ui-field"><span>Branch</span>
                            <select class="ui-input" wire:model="branchId">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
                            @error('branchId')<small class="ui-error">{{ $message }}</small>@enderror
                        </label>
                        <div class="ui-field"><span>Also bring in</span>
                            <div class="xi-options">
                                @foreach(\App\Services\ExternalImport\CloudifyImport::OPTIONAL as $key => $label)
                                    <label><input type="checkbox" wire:model="include.{{ $key }}"> <span>{{ $label }}@if($key === 'new_diagnoses')<br><small class="ui-hint">{{ number_format($a['new_diagnoses'] ?? 0) }} names would be added (eye markers such as BE or RE are dropped, so variants merge). Left out, those diagnoses stay only in the visit notes.</small>@endif @if($key === 'expenses')<br><small class="ui-hint">Kept as history the owner can read under Expenses → Previous software. Like the old payments, they stay out of the Expense Tracker, the income statement and the dashboard.</small>@endif</span></label>
                                @endforeach
                            </div>
                        </div>
                        <label class="ui-field"><span>Type <code>IMPORT {{ $selected->clinic?->slug }}</code> to confirm</span>
                            <input class="ui-input" type="text" wire:model="confirmation" autocomplete="off">
                            @error('confirmation')<small class="ui-error">{{ $message }}</small>@enderror
                        </label>
                    </div>
                    @if($blocker)<div class="ui-alert ui-alert--warning" style="margin-top:12px">{{ $blocker }}</div>@endif
                    <div class="ui-actions" style="margin-top:12px">
                        <button type="submit" class="ui-button ui-button-primary" @disabled($blocker) wire:loading.attr="disabled" wire:target="startImport" data-tour="xi-import">Import into {{ $selected->clinic?->name }}</button>
                    </div>
                </form>
            @endif

            @if(in_array($selected->status, ['queued', 'importing'], true))
                <div style="margin-top:18px"><strong>{{ $selected->status === 'queued' ? 'Waiting to start…' : 'Importing '.str_replace('_', ' ', $selected->current_step ?? '').'…' }}</strong>
                    <div class="xi-progress"><span style="width:{{ $selected->progress_percent }}%"></span></div><small class="ui-hint">{{ $selected->progress_percent }}% · you can leave this page; the import carries on.</small></div>
            @endif

            @if($selected->status === 'completed')
                @php($created = $selected->result['created'] ?? [])
                @php($labels = ['patients' => 'Patients', 'consultations' => 'Consultations', 'cashier_patient_clearances' => 'Visits', 'consultation_diagnosis' => 'Diagnoses on visits', 'diagnoses' => 'New diagnosis names', 'refractions' => 'Refractions', 'appointments' => 'Appointments', 'patient_legacy_payments' => 'Old payments', 'patient_legacy_medications' => 'Previous medications', 'products' => 'Products', 'categories' => 'New item categories', 'expenses' => 'Expenses', 'expense_categories' => 'New expense categories', 'legacy_expenses' => 'Old expenses'])
                <div class="ui-alert ui-alert--good" style="margin-top:16px"><strong>Imported {{ $selected->finished_at?->format('d M Y H:i') }}.</strong> The uploaded files have been deleted.</div>
                <div class="ui-table-wrap ui-table-wrap--framed" style="margin-top:10px">
                    <table class="ui-table"><thead><tr><th>Created</th><th style="text-align:right">Count</th></tr></thead><tbody>
                        @foreach($labels as $table => $label)@if($created[$table] ?? 0)<tr><td>{{ $label }}</td><td style="text-align:right">{{ number_format($created[$table]) }}</td></tr>@endif @endforeach
                    </tbody></table>
                </div>
                @if($selected->result['skipped'] ?? [])
                    <p class="ui-hint" style="margin:10px 0 0">Left out:
                        @foreach($selected->result['skipped'] as $sheet => $reasons)@foreach($reasons as $reason => $count){{ number_format($count) }} {{ str_replace('_', ' ', $sheet) }} ({{ $reason }}); @endforeach @endforeach</p>
                @endif
            @endif

            @if(in_array($selected->status, ['completed', 'failed'], true))
                <details style="margin-top:18px" data-tour="xi-undo"><summary>Undo this import</summary>
                    <form wire:submit="undoImport" class="ui-row" style="margin-top:10px">
                        <p class="ui-hint" style="flex-basis:100%;margin:0">Removes exactly what this import created. Not possible once the clinic has used an imported record (a new visit for an imported patient, a sale of an imported product).</p>
                        <label class="ui-field"><span>Type <code>UNDO {{ $selected->clinic?->slug }}</code></span><input class="ui-input" type="text" wire:model="undoConfirmation" autocomplete="off">@error('undoConfirmation')<small class="ui-error">{{ $message }}</small>@enderror</label>
                        <div class="ui-field"><span>&nbsp;</span><button type="submit" class="ui-button ui-button-danger" wire:loading.attr="disabled" wire:target="undoImport">Undo import</button></div>
                    </form>
                </details>
            @endif
        </section>
    @endif

    <section class="ui-card">
        <div class="ui-card-head"><div><h2>Imports</h2><p>The latest 20.</p></div></div>
        <div class="ui-table-wrap ui-table-wrap--framed" data-tour="xi-list">
            <table class="ui-table">
                <thead><tr><th>Clinic</th><th>From</th><th>Patients</th><th>Status</th><th>Checked</th><th></th></tr></thead>
                <tbody>
                @forelse($batches as $batch)
                    <tr wire:key="xi-batch-{{ $batch->id }}">
                        <td>{{ $batch->clinic?->name ?? '—' }}</td><td>{{ $batch->sourceLabel() }}</td>
                        <td>{{ number_format($batch->analysis['patients']['total'] ?? 0) }}</td>
                        <td>@include('livewire.platform.partials.external-import-status', ['batch' => $batch])</td>
                        <td>{{ $batch->created_at->format('d M Y H:i') }}</td>
                        <td><div class="ui-actions" style="justify-content:flex-end">
                            <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="selectBatch({{ $batch->id }})">Open</button>
                            @if(in_array($batch->status, ['analyzed', 'failed', 'rolled_back'], true))<button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="discard({{ $batch->id }})" wire:confirm="Remove this import from the list and delete its uploaded files?">Remove</button>@endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-empty">No imports yet. Choose a clinic and upload their exported files above.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-platform.page>
