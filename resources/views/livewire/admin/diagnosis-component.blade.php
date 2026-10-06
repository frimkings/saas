<div class="clinic-ui ui-page" data-livewire-root>
<div>

    {{-- ── Page Header ── --}}
    <div class="content-header">
        <div class="w-full">
            <div class="flex flex-wrap -mx-2 items-center">
                <div class="w-full sm:w-6/12 px-2">
                    <h1 class="m-0 flex items-center gap-2">
                        <span class="dx-icon"><i class="fas fa-stethoscope"></i></span>
                        Diagnosis Registry
                        <span class="inline-flex items-center px-1.5 py-0.5 text-xs font-semibold rounded-full dx-total-badge">{{ $diagnoses->total() }}</span>
                    </h1>
                    <p class="text-slate-500 mb-0" style="font-size:.82rem; margin-top:.2rem;">
                        Manage the ophthalmic diagnosis reference list used across consultations.
                    </p>
                </div>
                <div class="w-full sm:w-6/12 px-2">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Diagnoses</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="w-full">

            {{-- ── Add / Edit Form ── --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white dx-form-card mb-6 {{ $isEditing ? 'dx-form-card--editing' : '' }}">
                <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
                    <h3 class="font-semibold">
                        @if($isEditing)
                            <i class="fas fa-edit mr-2 text-amber-600"></i>Edit Diagnosis
                        @else
                            <i class="fas fa-plus-circle mr-2 text-teal-700"></i>Add New Diagnosis
                        @endif
                    </h3>
                </div>
                <div class="card-body p-4">
                    <form wire:submit="{{ $isEditing ? 'update' : 'store' }}">
                        <div class="flex flex-wrap -mx-2 items-start">
                            <div class="w-full md:w-8/12 px-2">
                                <div class="mb-0">
                                    <input type="text"
                                           class="form-control ui-input  dx-name-input @error('name') is-invalid @enderror"
                                           placeholder="e.g. Acute Angle Closure Glaucoma"
                                           wire:model="name"
                                           autofocus>
                                    @error('name')
                                        <div class="ui-error">{{ $message }}</div>
                                    @enderror
                                    <small class="mt-1 block text-xs text-slate-500">
                                        Use the full clinical name. Minimum 3 characters.
                                    </small>
                                </div>
                            </div>
                            <div class="w-full md:w-4/12 px-2">
                                <div class="flex gap-2 mt-1">
                                    <button type="submit"
                                            class="btn ui-button  grow {{ $isEditing ? 'ui-button-secondary' : 'ui-button-primary' }}"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $isEditing ? 'update' : 'store' }}">
                                        <span wire:loading.remove wire:target="{{ $isEditing ? 'update' : 'store' }}">
                                            <i class="fas {{ $isEditing ? 'fa-save' : 'fa-plus' }} mr-1"></i>
                                            {{ $isEditing ? 'Save Changes' : 'Add Diagnosis' }}
                                        </span>
                                        <span wire:loading wire:target="{{ $isEditing ? 'update' : 'store' }}">
                                            <i class="fas fa-spinner fa-spin mr-1"></i>Saving…
                                        </span>
                                    </button>
                                    @if($isEditing)
                                        <button type="button"
                                                class="btn ui-button ui-button-secondary"
                                                wire:click="resetFields">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {{-- ── Registry Table Card ── --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white dx-table-card">
                <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
                    <div class="flex items-center">
                        <h3 class="font-semibold mr-4">
                            <i class="fas fa-list mr-2"></i>All Diagnoses
                        </h3>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ $diagnoses->total() }} total</span>
                        @if(count($selectedDiagnosisIds) > 0)
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-teal-100 text-teal-800 ml-2">{{ count($selectedDiagnosisIds) }} selected</span>
                            <button type="button"
                                    class="btn ui-button ui-button-sm ui-button-danger ml-2"
                                    wire:click="deleteSelected"
                                    wire:confirm="Delete selected diagnosis records? This cannot be undone.">
                                <i class="fas fa-trash-alt mr-1"></i>Delete Selected
                            </button>
                            <button type="button"
                                    class="btn ui-button ui-button-sm ui-button-link text-slate-500"
                                    wire:click="clearSelection">
                                Clear
                            </button>
                        @endif
                    </div>
                    <div class="ml-auto flex items-center gap-1" style="gap:.5rem;">
                        {{-- Search --}}
                        <div class="flex items-stretch dx-search-group">
                            <div class="flex">
                                <span class="flex items-center border border-slate-300 px-2 text-sm text-slate-600 bg-white border-r-0">
                                    <span wire:loading wire:target="search">
                                        <i class="fas fa-spinner fa-spin text-teal-700" style="font-size:.75rem;"></i>
                                    </span>
                                    <span wire:loading.remove wire:target="search">
                                        <i class="fas fa-search text-slate-500" style="font-size:.75rem;"></i>
                                    </span>
                                </span>
                            </div>
                            <input type="text"
                                   class="form-control ui-input border-l-0"
                                   placeholder="Search diagnoses…"
                                   wire:model.live.debounce.300ms="search"
                                   style="width:220px;">
                            @if($search)
                                <div class="flex">
                                    <button class="btn ui-button ui-button-secondary" wire:click="clearSearch" title="Clear search">
                                        <i class="fas fa-times" style="font-size:.75rem;"></i>
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- Import --}}
                        <button class="btn ui-button ui-button-sm {{ $showImportPanel ? 'ui-button-primary' : 'ui-button-secondary' }}"
                                wire:click="$toggle('showImportPanel')"
                                title="Import from CSV">
                            <i class="fas fa-file-import mr-1"></i>Import CSV
                        </button>

                        {{-- Export --}}
                        <button wire:click="export"
                                class="btn ui-button ui-button-sm ui-button-secondary"
                                title="Export as CSV">
                            <i class="fas fa-download mr-1"></i>Export CSV
                        </button>
                    </div>
                </div>

                {{-- ── Import Panel ── --}}
                @if($showImportPanel)
                <div class="dx-import-panel">

                    @if($importResults)
                        {{-- Results view --}}
                        <div class="dx-import-results">
                            <div class="dx-import-results__stats">
                                <div class="dx-stat dx-stat--success">
                                    <i class="fas fa-check-circle"></i>
                                    <div>
                                        <span class="dx-stat__num">{{ $importResults['imported'] }}</span>
                                        <span class="dx-stat__label">Imported</span>
                                    </div>
                                </div>
                                <div class="dx-stat dx-stat--muted">
                                    <i class="fas fa-forward"></i>
                                    <div>
                                        <span class="dx-stat__num">{{ $importResults['skipped'] }}</span>
                                        <span class="dx-stat__label">Skipped / Duplicates</span>
                                    </div>
                                </div>
                                <div class="dx-stat dx-stat--danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <div>
                                        <span class="dx-stat__num">{{ count($importResults['errors']) }}</span>
                                        <span class="dx-stat__label">Errors</span>
                                    </div>
                                </div>
                            </div>

                            @if(count($importResults['errors']) > 0)
                                <div class="dx-import-errors">
                                    <p class="dx-import-errors__title">
                                        <i class="fas fa-exclamation-circle mr-1"></i>Row issues
                                    </p>
                                    <ul class="dx-import-errors__list">
                                        @foreach($importResults['errors'] as $err)
                                            <li>{{ $err }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div class="flex" style="gap:.5rem; margin-top:1rem;">
                                <button class="btn ui-button ui-button-primary ui-button-sm"
                                        wire:click="clearImport">
                                    <i class="fas fa-check mr-1"></i>Done
                                </button>
                                <button class="btn ui-button ui-button-secondary ui-button-sm"
                                        wire:click="clearImport">
                                    <i class="fas fa-redo mr-1"></i>Import Another File
                                </button>
                            </div>
                        </div>
                    @else
                        {{-- Upload form --}}
                        <div class="dx-import-form">
                            <div class="flex flex-wrap -mx-2 items-start">
                                <div class="w-full md:w-7/12 px-2">
                                    <p class="dx-import-desc">
                                        Upload a <strong>.csv</strong> file with one diagnosis name per row.
                                        Duplicate names are skipped automatically.
                                    </p>

                                    {{-- Format guide --}}
                                    <div class="dx-csv-preview">
                                        <div class="dx-csv-preview__header">
                                            <i class="fas fa-table mr-1"></i>Expected CSV format
                                        </div>
                                        <table class="dx-csv-table">
                                            <thead><tr><th>name</th></tr></thead>
                                            <tbody>
                                                <tr><td>Acanthamoeba Keratitis</td></tr>
                                                <tr><td>Acute Angle Closure Glaucoma</td></tr>
                                                <tr><td>Amblyopia (Strabismic)</td></tr>
                                            </tbody>
                                        </table>
                                        <p class="dx-csv-note">
                                            Column header must be <code>name</code>.
                                            Two-column format <code>id, name</code> is also accepted (id column is ignored).
                                        </p>
                                    </div>
                                </div>

                                <div class="w-full md:w-5/12 px-2">
                                    <div class="dx-upload-area">
                                        <label class="dx-upload-label" for="importFilePicker">
                                            <i class="fas fa-cloud-upload-alt dx-upload-icon"></i>
                                            <span class="dx-upload-text">
                                                @if($importFile)
                                                    <strong>{{ $importFile->getClientOriginalName() }}</strong>
                                                    <small class="block text-slate-500">{{ number_format($importFile->getSize() / 1024, 1) }} KB</small>
                                                @else
                                                    Click to choose a CSV file
                                                    <small class="block text-slate-500">Max 2 MB</small>
                                                @endif
                                            </span>
                                        </label>
                                        <input id="importFilePicker"
                                               type="file"
                                               accept=".csv,text/csv,text/plain"
                                               wire:model.live="importFile"
                                               class="dx-file-input">
                                    </div>

                                    @error('importFile')
                                        <p class="dx-upload-error"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>
                                    @enderror

                                    <div class="flex" style="gap:.5rem; margin-top:.85rem;">
                                        <button class="btn ui-button ui-button-primary ui-button-sm grow"
                                                wire:click="importCsv"
                                                wire:loading.attr="disabled"
                                                wire:target="importCsv"
                                                {{ !$importFile ? 'disabled' : '' }}>
                                            <span wire:loading.remove wire:target="importCsv">
                                                <i class="fas fa-file-import mr-1"></i>Run Import
                                            </span>
                                            <span wire:loading wire:target="importCsv">
                                                <i class="fas fa-spinner fa-spin mr-1"></i>Importing…
                                            </span>
                                        </button>
                                        <button class="btn ui-button ui-button-secondary ui-button-sm"
                                                wire:click="downloadTemplate">
                                            <i class="fas fa-download mr-1"></i>Template
                                        </button>
                                    </div>

                                    <button class="btn ui-button ui-button-link ui-button-sm text-slate-500 p-0 mt-2"
                                            wire:click="clearImport">
                                        <i class="fas fa-times mr-1"></i>Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                </div>
                @endif

                <div class="card-body p-0">
                    <div class="ui-table-wrap">
                        <table class="table ui-table dx-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width:44px;" class="text-center">
                                        <input type="checkbox"
                                               class="dx-row-check"
                                               wire:model.live="selectAllPage"
                                               title="Select all visible diagnoses">
                                    </th>
                                    <th style="width:60px;" class="text-center">#</th>
                                    <th class="dx-sortable" wire:click="sortBy('name')">
                                        Diagnosis Name
                                        <span class="dx-sort-indicator">
                                            @if($sortField === 'name')
                                                <i class="fas fa-sort-{{ $sortAsc ? 'up' : 'down' }} text-teal-700 ml-1"></i>
                                            @else
                                                <i class="fas fa-sort text-slate-500 ml-1" style="opacity:.35;"></i>
                                            @endif
                                        </span>
                                    </th>
                                    <th class="dx-sortable text-center" style="width:100px;" wire:click="sortBy('id')">
                                        DB ID
                                        <span class="dx-sort-indicator">
                                            @if($sortField === 'id')
                                                <i class="fas fa-sort-{{ $sortAsc ? 'up' : 'down' }} text-teal-700 ml-1"></i>
                                            @else
                                                <i class="fas fa-sort text-slate-500 ml-1" style="opacity:.35;"></i>
                                            @endif
                                        </span>
                                    </th>
                                    <th style="width:120px;" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($diagnoses as $index => $diagnosis)
                                    <tr class="{{ $diagnosis_id == $diagnosis->id && $isEditing ? 'dx-row--editing' : '' }}">
                                        <td class="text-center">
                                            <input type="checkbox"
                                                   class="dx-row-check"
                                                   value="{{ $diagnosis->id }}"
                                                   wire:model.live="selectedDiagnosisIds">
                                        </td>
                                        <td class="text-center text-slate-500" style="font-size:.8rem;">
                                            {{ ($diagnoses->currentPage() - 1) * $diagnoses->perPage() + $index + 1 }}
                                        </td>
                                        <td>
                                            <div class="flex items-center">
                                                <span class="dx-dx-icon mr-2">
                                                    <i class="fas fa-eye text-slate-500" style="font-size:.75rem;"></i>
                                                </span>
                                                @if($inlineEditingId == $diagnosis->id)
                                                    <div class="dx-inline-edit">
                                                        <input type="text"
                                                               class="form-control ui-input ui-input-sm dx-inline-input @error('inlineName') is-invalid @enderror"
                                                               wire:model="inlineName"
                                                               wire:keydown.enter="saveInlineEdit"
                                                               wire:keydown.escape="cancelInlineEdit"
                                                               autofocus>
                                                        <button type="button"
                                                                class="btn ui-button ui-button-sm ui-button-primary dx-inline-btn"
                                                                wire:click="saveInlineEdit"
                                                                title="Save">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button type="button"
                                                                class="btn ui-button ui-button-sm ui-button-secondary dx-inline-btn"
                                                                wire:click="cancelInlineEdit"
                                                                title="Cancel">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                        @error('inlineName')
                                                            <small class="text-red-700 block">{{ $message }}</small>
                                                        @enderror
                                                    </div>
                                                @else
                                                    <span class="dx-name {{ $diagnosis_id == $diagnosis->id && $isEditing ? 'text-amber-600 font-semibold' : '' }}"
                                                          wire:dblclick="startInlineEdit({{ $diagnosis->id }})"
                                                          title="Double-click to edit inline">
                                                        {{ $diagnosis->name }}
                                                    </span>
                                                    @if($diagnosis_id == $diagnosis->id && $isEditing)
                                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-2" style="font-size:.65rem;">Editing</span>
                                                    @endif
                                                @endif
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <code class="dx-id-code">{{ $diagnosis->id }}</code>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn ui-button ui-button-sm dx-action-btn dx-action-btn--edit ui-button-secondary"
                                                    wire:click="startInlineEdit({{ $diagnosis->id }})"
                                                    title="Edit inline">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <button class="btn ui-button ui-button-sm dx-action-btn dx-action-btn--delete ui-button-secondary"
                                                    wire:click="delete({{ $diagnosis->id }})"
                                                    wire:confirm="Delete &quot;{{ $diagnosis->name }}&quot;? This cannot be undone."
                                                    title="Delete this diagnosis">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-12">
                                            <div class="dx-empty">
                                                <i class="fas fa-search-minus dx-empty__icon"></i>
                                                @if($search)
                                                    <p class="dx-empty__title">No results for "{{ $search }}"</p>
                                                    <p class="dx-empty__sub">Try a different keyword or <a href="#" wire:click.prevent="clearSearch">clear the search</a>.</p>
                                                @else
                                                    <p class="dx-empty__title">No diagnoses yet</p>
                                                    <p class="dx-empty__sub">Use the form above to add the first diagnosis.</p>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($diagnoses->hasPages())
                    <div class="border-t border-slate-200 px-4 py-2 bg-white">
                        {{ $diagnoses->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>

</div>

<style>
/* ── Spacing helpers (AdminLTE is BS4) ── */
.gap-2 { gap: .5rem !important; }
.me-2  { margin-right: .5rem !important; }

/* ── Page title ── */
.dx-icon {
    align-items: center;
    background: #e8f0fe;
    border-radius: 8px;
    color: #1a56db;
    display: inline-flex;
    font-size: 1rem;
    height: 36px;
    justify-content: center;
    width: 36px;
}

.dx-total-badge {
    background: #1a56db;
    border-radius: 999px;
    color: #fff;
    font-size: .75rem;
    font-weight: 700;
    padding: .25rem .6rem;
}

/* ── Flash alert ── */
.dx-alert {
    background: #ecfdf5;
    border: 1px solid #6ee7b7;
    border-left: 4px solid #10b981;
    border-radius: 8px;
    color: #065f46;
    font-weight: 600;
}

/* ── Form card ── */
.dx-form-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(15,23,42,.06);
    transition: border-color .2s, box-shadow .2s;
}

.dx-form-card .card-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    border-radius: 10px 10px 0 0;
    padding: .75rem 1.25rem;
}

.dx-form-card--editing {
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245,158,11,.12), 0 2px 8px rgba(15,23,42,.06);
}

.dx-form-card--editing .card-header {
    background: #fffbeb;
    border-bottom-color: #fde68a;
}

.dx-name-input {
    border-color: #d1d5db;
    border-radius: 8px;
    font-size: .95rem;
    transition: border-color .15s, box-shadow .15s;
}
.dx-name-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}

/* ── Table card ── */
.dx-table-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(15,23,42,.06);
    overflow: hidden;
}

.dx-table-card .card-header {
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    padding: .75rem 1.25rem;
}

.dx-search-group .form-control,
.dx-search-group .input-group-text {
    border-color: #d1d5db;
    font-size: .85rem;
}
.dx-search-group .form-control:focus {
    border-color: #3b82f6;
    box-shadow: none;
}

/* ── Table ── */
.dx-table thead th {
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    border-top: none;
    color: #374151;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .06em;
    padding: .75rem 1rem;
    text-transform: uppercase;
    white-space: nowrap;
}

.dx-table tbody td {
    border-top: 1px solid #f1f5f9;
    color: #1e293b;
    padding: .75rem 1rem;
    vertical-align: middle;
}

.dx-table tbody tr:hover { background: #f8fafc; }

.dx-sortable {
    cursor: pointer;
    user-select: none;
}
.dx-sortable:hover { color: #1d4ed8; }

.dx-name {
    color: #111827;
    font-size: .92rem;
    font-weight: 500;
}
.dx-name[wire\:dblclick] {
    cursor: text;
}

.dx-row-check {
    cursor: pointer;
    height: 16px;
    width: 16px;
}

.dx-inline-edit {
    align-items: flex-start;
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    max-width: 680px;
    width: 100%;
}

.dx-inline-input {
    border-color: #93c5fd;
    border-radius: 7px;
    flex: 1 1 320px;
    min-width: 240px;
}

.dx-inline-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.14);
}

.dx-inline-btn {
    border-radius: 6px;
    height: 31px;
    line-height: 1;
    min-width: 32px;
    padding: 0 .55rem;
}

.dx-id-code {
    background: #f1f5f9;
    border-radius: 5px;
    color: #64748b;
    font-size: .78rem;
    padding: .15rem .45rem;
}

/* ── Row editing highlight ── */
.dx-row--editing {
    background: #fffbeb !important;
}
.dx-row--editing td { border-top-color: #fde68a !important; }

/* ── Action buttons ── */
.dx-action-btn {
    border-radius: 6px;
    height: 30px;
    line-height: 1;
    padding: 0;
    transition: background .12s, color .12s, border-color .12s;
    width: 30px;
}

.dx-action-btn--edit {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    margin-right: .25rem;
}
.dx-action-btn--edit:hover {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #1e40af;
}

.dx-action-btn--delete {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #e11d48;
}
.dx-action-btn--delete:hover {
    background: #ffe4e6;
    border-color: #fda4af;
    color: #be123c;
}

/* ── Import panel ── */
.dx-import-panel {
    background: #f8faff;
    border-bottom: 1px solid #e2e8f0;
    border-top: 1px solid #dbeafe;
    padding: 1.25rem 1.5rem;
}

.dx-import-desc {
    color: #374151;
    font-size: .875rem;
    margin-bottom: .85rem;
}

/* CSV format preview table */
.dx-csv-preview {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
}
.dx-csv-preview__header {
    background: #f1f5f9;
    border-bottom: 1px solid #e2e8f0;
    color: #475569;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .06em;
    padding: .4rem .75rem;
    text-transform: uppercase;
}
.dx-csv-table {
    border-collapse: collapse;
    font-family: 'Courier New', monospace;
    font-size: .8rem;
    width: 100%;
}
.dx-csv-table th {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    color: #64748b;
    font-weight: 800;
    padding: .35rem .75rem;
    text-align: left;
}
.dx-csv-table td {
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    padding: .3rem .75rem;
}
.dx-csv-table tr:last-child td { border-bottom: none; }
.dx-csv-note {
    border-top: 1px solid #e2e8f0;
    color: #64748b;
    font-size: .74rem;
    margin: 0;
    padding: .5rem .75rem;
}
.dx-csv-note code {
    background: #f1f5f9;
    border-radius: 4px;
    font-size: .78rem;
    padding: .05rem .35rem;
}

/* Upload area */
.dx-upload-area {
    background: #fff;
    border: 2px dashed #cbd5e1;
    border-radius: 10px;
    cursor: pointer;
    text-align: center;
    transition: border-color .15s, background .15s;
}
.dx-upload-area:hover { background: #f8faff; border-color: #93c5fd; }

.dx-upload-label {
    cursor: pointer;
    display: block;
    margin: 0;
    padding: 1.25rem 1rem;
}
.dx-upload-icon {
    color: #93c5fd;
    display: block;
    font-size: 2rem;
    margin-bottom: .5rem;
}
.dx-upload-text {
    color: #475569;
    font-size: .85rem;
    font-weight: 600;
}
.dx-file-input {
    height: 0;
    opacity: 0;
    position: absolute;
    width: 0;
}
.dx-upload-error {
    color: #dc2626;
    font-size: .78rem;
    margin: .4rem 0 0;
}

/* Import results */
.dx-import-results {
    max-width: 600px;
}
.dx-import-results__stats {
    display: flex;
    gap: .85rem;
    margin-bottom: .85rem;
}
.dx-stat {
    align-items: center;
    border-radius: 9px;
    display: flex;
    flex: 1;
    gap: .6rem;
    padding: .75rem 1rem;
}
.dx-stat i { font-size: 1.4rem; }
.dx-stat__num {
    display: block;
    font-size: 1.4rem;
    font-weight: 900;
    line-height: 1;
}
.dx-stat__label {
    color: inherit;
    display: block;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .04em;
    opacity: .75;
    text-transform: uppercase;
}
.dx-stat--success { background: #ecfdf5; color: #065f46; }
.dx-stat--success i { color: #10b981; }
.dx-stat--muted   { background: #f8fafc; color: #334155; }
.dx-stat--muted i { color: #94a3b8; }
.dx-stat--danger  { background: #fff1f2; color: #9f1239; }
.dx-stat--danger i { color: #f43f5e; }

.dx-import-errors {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 8px;
    padding: .75rem 1rem;
}
.dx-import-errors__title {
    color: #c2410c;
    font-size: .82rem;
    font-weight: 800;
    margin-bottom: .4rem;
}
.dx-import-errors__list {
    color: #7c2d12;
    font-size: .78rem;
    margin: 0;
    padding-left: 1.25rem;
}
.dx-import-errors__list li + li { margin-top: .2rem; }

/* ── Empty state ── */
.dx-empty {
    color: #94a3b8;
    padding: 1rem;
}
.dx-empty__icon {
    display: block;
    font-size: 2.5rem;
    margin-bottom: .75rem;
    opacity: .4;
}
.dx-empty__title {
    color: #475569;
    font-size: .95rem;
    font-weight: 700;
    margin-bottom: .25rem;
}
.dx-empty__sub {
    color: #94a3b8;
    font-size: .82rem;
    margin: 0;
}
.dx-empty__sub a { color: #3b82f6; }
</style>
</div>
