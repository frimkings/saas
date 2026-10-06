<div class="clinic-ui ui-page space-y-5">

    {{-- ── Page Header ──────────────────────────────────────────────────── --}}
    <div class="ui-heading">
        <div>
            <h1><i class="fas fa-folder-open mr-2 text-teal-700" aria-hidden="true"></i>Patient records</h1>
            <p class="ui-muted"><a href="{{ route('doctor.dashboard') }}" class="text-teal-700 no-underline hover:underline">Dashboard</a> / All records</p>
        </div>
    </div>

    <div class="content">
        <div class="w-full">

            {{-- ═══════════════════════════════════════════════════════════ --}}
            {{-- FILTER CARD                                                  --}}
            {{-- ═══════════════════════════════════════════════════════════ --}}
            @php
                $hasSearch  = !empty($searchTerm);
                $hasGender  = !empty($genderFilter);
                $hasDiag    = count($diagnosisFilter) > 0;
                $hasAge     = $ageMin !== '' || $ageMax !== '';
                $hasIop     = $iopMin !== '' || $iopMax !== '';
                $hasCdr     = $cdrMin !== '' || $cdrMax !== '';
                $hasVa      = $vaMin !== '' || $vaMax !== '';
                $filterCount = (int)$hasSearch + (int)$hasGender + (int)$hasDiag
                             + (int)$hasAge + (int)$hasIop + (int)$hasCdr + (int)$hasVa;
            @endphp

            <div class="filter-card mb-6">

                {{-- Card Header --}}
                <div class="filter-card__header">
                    <div class="flex items-center">
                        <i class="fas fa-sliders-h mr-2"></i>
                        <span>Filter Records</span>
                        @if($filterCount)
                            <span class="filter-badge ml-2">{{ $filterCount }} active</span>
                        @endif
                    </div>
                    <div class="flex items-center" style="gap:8px;">
                        <span class="text-sm text-slate-500">
                            <span class="font-semibold text-slate-900">{{ number_format($allrecords->total()) }}</span> records
                            <span wire:loading.delay
                                  wire:target="searchTerm,startDate,endDate,genderFilter,diagnosisFilter,ageMin,ageMax,iopMin,iopMax,cdrMin,cdrMax,vaMin,vaMax,resetFilters,clearClinicalFilters,previousPage,nextPage,gotoPage">
                                <i class="fas fa-spinner fa-spin fa-xs text-slate-500 ml-1"></i>
                            </span>
                        </span>
                        <button wire:click="resetFilters" class="btn-reset">
                            <i class="fas fa-undo mr-1"></i>Reset All
                        </button>
                    </div>
                </div>

                <div class="filter-card__body">

                    {{-- ── Row 1: Search · Date · Age · Gender  (4 × col-md-3) --}}
                    <div class="flex flex-wrap -mx-2 items-stretch mb-4" style="row-gap:10px;">

                        {{-- Search --}}
                        <div class="w-full md:w-3/12 px-2">
                            <div class="filter-range h-full">
                                <div class="filter-range__label">
                                    <i class="fas fa-search mr-1" style="color:#007bff;"></i>Search
                                </div>
                                <div class="filter-input-wrap filter-input-wrap--inline">
                                    <i class="fas fa-search filter-input-icon"></i>
                                    <input type="text" wire:model.live.debounce.500ms="searchTerm"
                                           class="filter-range__input" style="padding-left:20px; text-align:left;"
                                           placeholder="Patient name or folder number…">
                                </div>
                            </div>
                        </div>

                        {{-- Date Range --}}
                        <div class="w-full md:w-3/12 px-2">
                            <div class="filter-range h-full">
                                <div class="filter-range__label">
                                    <i class="fas fa-calendar-alt mr-1" style="color:#20c997;"></i>Date Range
                                </div>
                                <div class="filter-range__controls">
                                    <div style="flex:1;min-width:0"><x-date-range from="startDate" to="endDate" presets="activity" /></div>
                                </div>
                            </div>
                        </div>

                        {{-- Age --}}
                        <div class="w-full md:w-3/12 px-2">
                            <div class="filter-range h-full">
                                <div class="filter-range__label">
                                    <i class="fas fa-user-alt mr-1" style="color:#17a2b8;"></i>Age <span class="text-slate-500">(yrs)</span>
                                </div>
                                <div class="filter-range__controls">
                                    <input type="number" wire:model.blur="ageMin" class="filter-range__input" placeholder="Min" min="0" max="120">
                                    <span class="filter-range__sep">–</span>
                                    <input type="number" wire:model.blur="ageMax" class="filter-range__input" placeholder="Max" min="0" max="120">
                                </div>
                            </div>
                        </div>

                        {{-- Gender --}}
                        <div class="w-full md:w-3/12 px-2">
                            <div class="filter-range h-full">
                                <div class="filter-range__label">
                                    <i class="fas fa-venus-mars mr-1" style="color:#e83e8c;"></i>Gender
                                </div>
                                <div class="filter-range__controls">
                                    <select wire:model.live="genderFilter" class="filter-range__select" style="width:100%;">
                                        <option value="">All Genders</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                    </div>

                    {{-- ── Row 2: Diagnosis · IOP · CDR · VA  (4 × col-md-3) --}}
                    <div class="flex flex-wrap -mx-2 items-stretch" style="row-gap:10px;">

                        {{-- Diagnosis --}}
                        <div class="w-full md:w-3/12 px-2">
                            <div class="filter-range h-full">
                                <div class="filter-range__label">
                                    <i class="fas fa-stethoscope mr-1" style="color:#6610f2;"></i>Diagnosis
                                    @if($hasDiag)
                                        <span class="filter-badge ml-1">{{ count($diagnosisFilter) }}</span>
                                    @endif
                                </div>
                                <div class="dropdown diag-multiselect" style="position:relative;">
                                    <button type="button" class="diag-toggle filter-diag-trigger">
                                        <span class="truncate" style="max-width:90%;">
                                            @if($hasDiag)
                                                <i class="fas fa-check-circle text-teal-700 mr-1" style="font-size:.75rem;"></i>
                                                {{ count($diagnosisFilter) }} selected
                                            @else
                                                <span class="text-slate-500">All diagnoses — click to filter</span>
                                            @endif
                                        </span>
                                        <i class="fas fa-chevron-down text-slate-500" style="font-size:.6rem; flex-shrink:0;"></i>
                                    </button>
                                    <div class="diag-panel diag-dropdown-panel">
                                        <div class="diag-search-wrap">
                                            <i class="fas fa-search diag-search-icon"></i>
                                            <input type="text" class="diag-search diag-search-input" placeholder="Search diagnoses…">
                                        </div>
                                        <div class="diag-list" style="max-height:220px; overflow-y:auto;">
                                            @forelse ($diagnoses as $diag)
                                                <label class="diag-opt diag-option" data-name="{{ strtolower($diag->name) }}">
                                                    <input type="checkbox" wire:model.live="diagnosisFilter" value="{{ $diag->id }}" class="diag-checkbox">
                                                    <span>{{ $diag->name }}</span>
                                                </label>
                                            @empty
                                                <p class="text-slate-500 text-center py-4 mb-0 text-sm">No diagnoses available.</p>
                                            @endforelse
                                            <p class="diag-no-results text-slate-500 text-center py-4 mb-0 text-sm" style="display:none;">No match found.</p>
                                        </div>
                                        @if($hasDiag)
                                        <div class="diag-footer">
                                            <small class="text-slate-500">{{ count($diagnosisFilter) }} selected</small>
                                            <button type="button" wire:click="$set('diagnosisFilter', [])"
                                                    class="btn ui-button ui-button-link ui-button-sm text-red-700 p-0" style="font-size:.78rem;">
                                                <i class="fas fa-times mr-1"></i>Clear
                                            </button>
                                        </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- IOP --}}
                        <div class="w-full md:w-3/12 px-2">
                            <div class="filter-range h-full">
                                <div class="filter-range__label">
                                    <i class="fas fa-tachometer-alt mr-1" style="color:#fd7e14;"></i>IOP <span class="text-slate-500">(mmHg)</span>
                                </div>
                                <div class="filter-range__controls">
                                    <input type="number" wire:model.blur="iopMin" class="filter-range__input" placeholder="Min" min="0" max="100" step="0.5">
                                    <span class="filter-range__sep">–</span>
                                    <input type="number" wire:model.blur="iopMax" class="filter-range__input" placeholder="Max" min="0" max="100" step="0.5">
                                </div>
                            </div>
                        </div>

                        {{-- CDR --}}
                        <div class="w-full md:w-3/12 px-2">
                            <div class="filter-range h-full">
                                <div class="filter-range__label">
                                    <i class="fas fa-dot-circle mr-1" style="color:#6f42c1;"></i>CDR <span class="text-slate-500">(0–1)</span>
                                </div>
                                <div class="filter-range__controls">
                                    <input type="number" wire:model.blur="cdrMin" class="filter-range__input" placeholder="0.0" min="0" max="1" step="0.1">
                                    <span class="filter-range__sep">–</span>
                                    <input type="number" wire:model.blur="cdrMax" class="filter-range__input" placeholder="1.0" min="0" max="1" step="0.1">
                                </div>
                            </div>
                        </div>

                        {{-- VA --}}
                        <div class="w-full md:w-3/12 px-2">
                            <div class="filter-range h-full">
                                <div class="filter-range__label">
                                    <i class="fas fa-eye mr-1" style="color:#28a745;"></i>Visual Acuity <span class="text-slate-500">(OD or OS)</span>
                                </div>
                                <div class="filter-range__controls">
                                    <select wire:model.live="vaMin" class="filter-range__select">
                                        <option value="">Best</option>
                                        @foreach($vaOptions as $va)
                                            <option value="{{ $va }}">{{ $va }}</option>
                                        @endforeach
                                    </select>
                                    <span class="filter-range__sep">–</span>
                                    <select wire:model.live="vaMax" class="filter-range__select">
                                        <option value="">Worst</option>
                                        @foreach($vaOptions as $va)
                                            <option value="{{ $va }}">{{ $va }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="filter-range__hint">6/4 = best &rarr; NPL = worst</div>
                            </div>
                        </div>

                    </div>

                    {{-- Clear clinical button (only when clinical filters active) --}}
                    @if($hasAge || $hasIop || $hasCdr || $hasVa)
                    <div class="mt-2 text-right">
                        <button wire:click="clearClinicalFilters"
                                class="btn ui-button ui-button-sm ui-button-danger rounded-full px-4">
                            <i class="fas fa-times mr-1"></i>Clear Clinical Filters
                        </button>
                    </div>
                    @endif

                    {{-- ── Active filter chips ──────────────────────────── --}}
                    @if($filterCount)
                    <div class="flex flex-wrap mt-4 pt-4" style="border-top:1px dashed #e9ecef; gap:6px; align-items:center;">
                        <span class="text-sm text-slate-500 font-semibold mr-1">
                            <i class="fas fa-filter mr-1"></i>Active:
                        </span>
                        @if($hasSearch)
                        <span class="filter-chip filter-chip--blue">
                            <i class="fas fa-search fa-xs"></i>
                            "{{ \Illuminate\Support\Str::limit($searchTerm, 18) }}"
                            <button wire:click="$set('searchTerm', null)">×</button>
                        </span>
                        @endif
                        @if($hasGender)
                        <span class="filter-chip filter-chip--pink">
                            <i class="fas fa-venus-mars fa-xs"></i>
                            {{ $genderFilter }}
                            <button wire:click="$set('genderFilter', '')">×</button>
                        </span>
                        @endif
                        @if($hasDiag)
                        <span class="filter-chip filter-chip--teal">
                            <i class="fas fa-stethoscope fa-xs"></i>
                            {{ count($diagnosisFilter) }} diagnosis
                            <button wire:click="$set('diagnosisFilter', [])">×</button>
                        </span>
                        @endif
                        @if($hasAge)
                        <span class="filter-chip filter-chip--cyan">
                            <i class="fas fa-user-alt fa-xs"></i>
                            Age: {{ $ageMin !== '' ? $ageMin : '0' }}–{{ $ageMax !== '' ? $ageMax : '∞' }} yrs
                            <button wire:click="$set('ageMin', '')" onclick="@this.set('ageMax', '')">×</button>
                        </span>
                        @endif
                        @if($hasIop)
                        <span class="filter-chip filter-chip--orange">
                            <i class="fas fa-tachometer-alt fa-xs"></i>
                            IOP: {{ $iopMin !== '' ? $iopMin : '0' }}–{{ $iopMax !== '' ? $iopMax : '∞' }} mmHg
                            <button wire:click="$set('iopMin', '')" onclick="@this.set('iopMax', '')">×</button>
                        </span>
                        @endif
                        @if($hasCdr)
                        <span class="filter-chip filter-chip--purple">
                            <i class="fas fa-dot-circle fa-xs"></i>
                            CDR: {{ $cdrMin !== '' ? $cdrMin : '0' }}–{{ $cdrMax !== '' ? $cdrMax : '1' }}
                            <button wire:click="$set('cdrMin', '')" onclick="@this.set('cdrMax', '')">×</button>
                        </span>
                        @endif
                        @if($hasVa)
                        <span class="filter-chip filter-chip--green">
                            <i class="fas fa-eye fa-xs"></i>
                            VA: {{ $vaMin ?: 'Best' }} – {{ $vaMax ?: 'Worst' }}
                            <button wire:click="$set('vaMin', '')" onclick="@this.set('vaMax', '')">×</button>
                        </span>
                        @endif
                    </div>
                    @endif

                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════════ --}}
            {{-- RESULTS TABLE                                                --}}
            {{-- ═══════════════════════════════════════════════════════════ --}}

            {{-- Centered overlay spinner — appears after 200ms on any filter/page change --}}
            <div wire:loading.delay.flex
                 wire:target="searchTerm,startDate,endDate,genderFilter,diagnosisFilter,ageMin,ageMax,iopMin,iopMax,cdrMin,cdrMax,vaMin,vaMax,resetFilters,clearClinicalFilters,previousPage,nextPage,gotoPage"
                 class="fixed"
                 style="display:none; top:50%; left:50%; transform:translate(-50%,-50%); z-index:9999; pointer-events:none; flex-direction:column; align-items:center;">
                <div class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-current border-r-transparent text-teal-700" role="status" style="width:3rem; height:3rem;">
                    <span class="sr-only">Loading...</span>
                </div>
                <small class="text-slate-500 mt-2 font-semibold" style="font-size:.8rem;">Searching records…</small>
            </div>

            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0" style="border-radius:10px; overflow:hidden;">

                {{-- Export toolbar (shown always; selection count updates dynamically) --}}
                <div class="card-header border-b bg-white border-slate-200 flex justify-between items-center py-2 px-4">
                    <div class="flex items-center" style="gap:8px;">
                        <span class="text-sm text-slate-500">
                            @if(count($selectedIds))
                                <span class="font-semibold text-slate-900">{{ count($selectedIds) }}</span> selected
                            @else
                                <span class="text-slate-500">Select rows to export</span>
                            @endif
                        </span>
                        @if(count($selectedIds))
                        <span class="text-slate-500">|</span>
                        <button wire:click="$set('selectedIds', [])" class="btn ui-button ui-button-link ui-button-sm text-red-700 p-0"
                                style="font-size:.78rem;">
                            <i class="fas fa-times mr-1"></i>Deselect all
                        </button>
                        @endif
                    </div>
                    <div class="flex items-center" style="gap:6px;">
                        <span class="text-sm text-slate-500 mr-1">
                            @if(count($selectedIds) === 0) Export all filtered @else Export selected @endif
                        </span>
                        <a wire:click="exportCsv" href="#"
                           wire:loading.class="disabled" wire:target="exportCsv"
                           class="btn ui-button ui-button-sm ui-button-secondary rounded-full px-4"
                           style="font-size:.78rem;">
                            <span wire:loading.remove wire:target="exportCsv">
                                <i class="fas fa-file-csv mr-1"></i>CSV
                            </span>
                            <span wire:loading wire:target="exportCsv">
                                <i class="fas fa-spinner fa-spin mr-1"></i>Exporting…
                            </span>
                        </a>
                        <a wire:click="exportPdf" href="#"
                           wire:loading.class="disabled" wire:target="exportPdf"
                           class="btn ui-button ui-button-sm ui-button-danger rounded-full px-4"
                           style="font-size:.78rem;">
                            <span wire:loading.remove wire:target="exportPdf">
                                <i class="fas fa-file-pdf mr-1"></i>PDF
                            </span>
                            <span wire:loading wire:target="exportPdf">
                                <i class="fas fa-spinner fa-spin mr-1"></i>Generating…
                            </span>
                        </a>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0" style="font-size:.875rem;">
                            <thead style="background:linear-gradient(135deg,#2c3e50 0%,#3d5166 100%);">
                                <tr>
                                    <th class="px-4 border-0" style="width:36px;">
                                        <input type="checkbox" wire:model.live="selectAll"
                                               class="export-checkbox export-checkbox--all"
                                               title="Select all filtered records">
                                    </th>
                                    <th class="px-2 text-white border-0" style="width:36px;">#</th>
                                    <th class="text-white border-0">Patient</th>
                                    <th class="text-white border-0">Age / Gender</th>
                                    <th class="text-white border-0">Date</th>
                                    <th class="text-white border-0">Chief Complaint</th>
                                    <th class="text-white border-0">Diagnoses</th>
                                    <th class="text-white border-0 text-center">
                                        IOP <small style="opacity:.7; font-weight:400;">(OD/OS)</small>
                                    </th>
                                    <th class="text-white border-0 text-center">
                                        VA <small style="opacity:.7; font-weight:400;">(OD/OS)</small>
                                    </th>
                                    <th class="text-white border-0 text-right px-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody wire:loading.class="opacity-50">
                                @forelse ($allrecords as $record)
                                @php
                                    $patient   = $record->patient;
                                    $age       = $patient?->dob
                                                    ? \Carbon\Carbon::parse($patient->dob)->age
                                                    : null;
                                    $gender    = $patient?->gender ?? null;
                                    $iopOD     = $record->IOPOD;
                                    $iopOS     = $record->IOPOS;
                                    $vaOD      = $record->vaOD6m;
                                    $vaOS      = $record->vaOS6m;
                                @endphp
                                <tr class="{{ in_array((string)$record->id, array_map('strval', $selectedIds)) ? 'table-row--selected' : '' }}">
                                    <td class="px-4 align-middle">
                                        <input type="checkbox"
                                               wire:model.live="selectedIds"
                                               value="{{ $record->id }}"
                                               class="export-checkbox">
                                    </td>
                                    <td class="px-2 text-slate-500 align-middle">
                                        {{ $allrecords->firstItem() + $loop->index }}
                                    </td>

                                    <td class="align-middle">
                                        <div class="font-semibold text-slate-900" style="line-height:1.3;">
                                            {{ $patient?->name ?? '—' }}
                                        </div>
                                        <div class="flex items-center mt-1" style="gap:6px;">
                                            <code class="text-slate-500" style="font-size:.72rem; background:#f8f9fa; padding:1px 5px; border-radius:4px;">
                                                {{ $patient?->pxnumber ?? '—' }}
                                            </code>
                                            @if($patient?->contact)
                                            <span class="text-slate-500" style="font-size:.75rem;">
                                                {{ $patient->contact }}
                                            </span>
                                            @endif
                                        </div>
                                    </td>

                                    <td class="align-middle" style="white-space:nowrap;">
                                        @if($age !== null)
                                        <span class="font-semibold text-slate-900">{{ $age }}</span>
                                        <span class="text-slate-500" style="font-size:.75rem;"> yrs</span>
                                        @else
                                        <span class="text-slate-500">—</span>
                                        @endif
                                        @if($gender)
                                        <br>
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold mt-1"
                                              style="font-size:.68rem;
                                                     background:{{ $gender === 'Male' ? '#dbeafe' : ($gender === 'Female' ? '#fce7f3' : '#f3f4f6') }};
                                                     color:{{ $gender === 'Male' ? '#1d4ed8' : ($gender === 'Female' ? '#9d174d' : '#374151') }};">
                                            {{ $gender }}
                                        </span>
                                        @endif
                                    </td>

                                    <td class="align-middle" style="white-space:nowrap;">
                                        <div class="font-semibold" style="font-size:.83rem;">
                                            {{ $record->created_at->format('d M Y') }}
                                        </div>
                                        <div class="text-slate-500" style="font-size:.75rem;">
                                            {{ $record->created_at->format('g:i A') }}
                                        </div>
                                    </td>

                                    <td class="align-middle text-slate-500" style="max-width:155px; font-size:.82rem;">
                                        <span title="{{ $record->chiefComplaint }}">
                                            {{ \Illuminate\Support\Str::limit($record->chiefComplaint, 42) ?: '—' }}
                                        </span>
                                    </td>

                                    <td class="align-middle" style="max-width:180px;">
                                        @forelse($record->diagnoses as $diag)
                                            <span class="diag-badge">{{ $diag->name }}</span>
                                        @empty
                                            <span class="text-slate-500" style="font-size:.8rem;">—</span>
                                        @endforelse
                                    </td>

                                    <td class="text-center align-middle" style="white-space:nowrap;">
                                        @if($iopOD !== null || $iopOS !== null)
                                        <span class="clinical-val {{ ($iopOD > 21) ? 'clinical-val--high' : '' }}">
                                            {{ $iopOD ?? '—' }}
                                        </span>
                                        <span class="text-slate-500" style="font-size:.7rem;">/</span>
                                        <span class="clinical-val {{ ($iopOS > 21) ? 'clinical-val--high' : '' }}">
                                            {{ $iopOS ?? '—' }}
                                        </span>
                                        @else
                                        <span class="text-slate-500" style="font-size:.8rem;">—</span>
                                        @endif
                                    </td>

                                    <td class="text-center align-middle" style="white-space:nowrap;">
                                        @if($vaOD || $vaOS)
                                        <span class="clinical-val">{{ $vaOD ?? '—' }}</span>
                                        <span class="text-slate-500" style="font-size:.7rem;">/</span>
                                        <span class="clinical-val">{{ $vaOS ?? '—' }}</span>
                                        @else
                                        <span class="text-slate-500" style="font-size:.8rem;">—</span>
                                        @endif
                                    </td>

                                    <td class="text-right px-4 align-middle">
                                        <a href="{{ route('doctor.patient-records', $record->clearance) }}"
                                           class="btn ui-button ui-button-sm ui-button-primary rounded-full px-4 shadow-sm"
                                           style="font-size:.78rem;">
                                            <i class="fas fa-folder-open mr-1"></i>Open
                                        </a>
                                        <a href="{{ route('doctor.patient-timeline', $record->patient) }}"
                                           class="btn ui-button ui-button-sm ui-button-secondary rounded-full px-2 shadow-sm ml-1"
                                           title="Clinical Timeline" style="font-size:.78rem;">
                                            <i class="fas fa-stream"></i>
                                        </a>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="10" class="text-center py-12">
                                        <div style="color:#dee2e6;">
                                            <i class="fas fa-search fa-3x mb-4 block"></i>
                                        </div>
                                        <p class="text-slate-500 mb-1 font-semibold">No records found</p>
                                        <p class="text-slate-500 text-sm mb-0">Try adjusting your filters or expanding the date range.</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="border-t border-slate-200 bg-white border-t-0 flex justify-between items-center py-4 px-6">
                    <span class="text-sm text-slate-500">
                        @if($allrecords->total())
                            Showing
                            <span class="font-semibold text-slate-900">{{ $allrecords->firstItem() }}</span>
                            –
                            <span class="font-semibold text-slate-900">{{ $allrecords->lastItem() }}</span>
                            of
                            <span class="font-semibold text-slate-900">{{ number_format($allrecords->total()) }}</span>
                        @else
                            No records
                        @endif
                    </span>
                    {{ $allrecords->links() }}
                </div>
            </div>

        </div>
    </div>

    {{-- ── Styles ──────────────────────────────────────────────────────── --}}
    <style>
    /* ── Filter Card ──────────────────────────────────────────────────── */
    .filter-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e8ecef;
        border-top: 3px solid #007bff;
        box-shadow: 0 2px 12px rgba(0,0,0,.06);
        overflow: visible;
    }
    .filter-card__header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 20px;
        border-bottom: 1px solid #f0f3f7;
        font-weight: 700;
        font-size: .88rem;
        color: #2c3e50;
        background: #f8faff;
        border-radius: 9px 9px 0 0;
    }
    .filter-card__body {
        padding: 20px;
    }

    /* ── Reset button ─────────────────────────────────────────────────── */
    .btn-reset {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 20px;
        padding: 4px 14px;
        font-size: .78rem;
        font-weight: 600;
        color: #6c757d;
        cursor: pointer;
        transition: all .15s;
    }
    .btn-reset:hover { background: #f8f9fa; color: #343a40; border-color: #adb5bd; }

    /* ── Active filter badge ──────────────────────────────────────────── */
    .filter-badge {
        background: #007bff;
        color: #fff;
        border-radius: 20px;
        padding: 2px 8px;
        font-size: .68rem;
        font-weight: 700;
    }

    /* ── Search inline wrapper ────────────────────────────────────────── */
    .filter-input-wrap--inline { position: relative; }
    .filter-input-wrap--inline .filter-input-icon {
        position: absolute;
        left: 2px;
        top: 50%;
        transform: translateY(-50%);
        color: #adb5bd;
        font-size: .75rem;
        pointer-events: none;
    }

    /* ── Date sub-labels ──────────────────────────────────────────────── */
    .filter-date-label {
        font-size: .6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #adb5bd;
        margin-bottom: 1px;
        display: block;
    }

    /* ── Diagnosis dropdown ───────────────────────────────────────────── */
    .filter-diag-trigger {
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: none;
        border-bottom: 2px solid #dee2e6;
        border-radius: 0;
        background: transparent;
        font-size: .85rem;
        padding: 2px 0;
        cursor: pointer;
        color: #343a40;
        text-align: left;
    }
    .filter-diag-trigger:focus { outline: none; border-bottom-color: #007bff; }
    .diag-dropdown-panel {
        display: none;
        position: absolute;
        z-index: 1060;
        width: 100%;
        top: calc(100% + 4px);
        left: 0;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(0,0,0,.12);
        overflow: hidden;
    }
    .diag-search-wrap {
        position: relative;
        padding: 10px 12px;
        border-bottom: 1px solid #f0f3f7;
        background: #f8faff;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .diag-search-icon {
        position: absolute;
        left: 22px;
        top: 50%;
        transform: translateY(-50%);
        color: #adb5bd;
        font-size: .75rem;
    }
    .diag-search-input {
        width: 100%;
        padding: 6px 10px 6px 28px;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        font-size: .83rem;
        outline: none;
        background: #fff;
    }
    .diag-search-input:focus { border-color: #80bdff; box-shadow: 0 0 0 2px rgba(0,123,255,.1); }
    .diag-option {
        display: flex;
        align-items: center;
        padding: 9px 14px;
        margin: 0;
        cursor: pointer;
        font-size: .85rem;
        border-bottom: 1px solid #f8f9fa;
        transition: background .1s;
        color: #343a40;
    }
    .diag-option:hover { background: #f0f7ff; }
    .diag-checkbox {
        width: 15px;
        height: 15px;
        margin-right: 10px;
        cursor: pointer;
        flex-shrink: 0;
    }
    .diag-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 14px;
        border-top: 1px solid #f0f3f7;
        background: #fafafa;
    }

    /* ── Range filter boxes ───────────────────────────────────────────── */
    .filter-range {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 10px;
        padding: 10px 14px 12px;
    }
    .filter-range__label {
        font-size: .68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #6c757d;
        margin-bottom: 8px;
    }
    .filter-range__controls {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .filter-range__input {
        flex: 1;
        min-width: 0;
        border: none;
        border-bottom: 2px solid #dee2e6;
        border-radius: 0;
        font-size: .85rem;
        text-align: center;
        background: transparent;
        padding: 3px 4px;
        outline: none;
        color: #343a40;
        transition: border-color .15s;
        -moz-appearance: textfield;
    }
    .filter-range__input::-webkit-outer-spin-button,
    .filter-range__input::-webkit-inner-spin-button { -webkit-appearance: none; }
    .filter-range__input:focus { border-bottom-color: #007bff; }
    .filter-range__input::placeholder { color: #ced4da; }
    .filter-range__select {
        flex: 1;
        min-width: 0;
        border: none;
        border-bottom: 2px solid #dee2e6;
        border-radius: 0;
        font-size: .8rem;
        background: transparent;
        padding: 3px 0;
        outline: none;
        cursor: pointer;
        color: #343a40;
        transition: border-color .15s;
    }
    .filter-range__select:focus { border-bottom-color: #007bff; }
    .filter-range__sep {
        color: #ced4da;
        font-size: .95rem;
        font-weight: 700;
        flex-shrink: 0;
    }
    .filter-range__hint {
        margin-top: 5px;
        font-size: .65rem;
        color: #adb5bd;
    }

    /* ── Active filter chips ──────────────────────────────────────────── */
    .filter-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border-radius: 20px;
        padding: 3px 10px 3px 8px;
        font-size: .74rem;
        font-weight: 600;
    }
    .filter-chip button {
        background: none;
        border: none;
        cursor: pointer;
        font-size: .9rem;
        line-height: 1;
        padding: 0;
        margin-left: 2px;
        opacity: .7;
    }
    .filter-chip button:hover { opacity: 1; }
    .filter-chip--blue   { background:#dbeafe; color:#1e40af; }
    .filter-chip--pink   { background:#fce7f3; color:#9d174d; }
    .filter-chip--teal   { background:#ccfbf1; color:#0f766e; }
    .filter-chip--cyan   { background:#cffafe; color:#0e7490; }
    .filter-chip--orange { background:#ffedd5; color:#c2410c; }
    .filter-chip--purple { background:#ede9fe; color:#6d28d9; }
    .filter-chip--green  { background:#dcfce7; color:#15803d; }

    /* ── Export checkboxes ───────────────────────────────────────────── */
    .export-checkbox {
        width: 15px;
        height: 15px;
        cursor: pointer;
        accent-color: #007bff;
    }
    .export-checkbox--all { accent-color: #fff; }
    .table-row--selected { background: #eff6ff !important; }

    /* ── Results table ────────────────────────────────────────────────── */
    .diag-badge {
        display: inline-block;
        background: #f0f4ff;
        color: #3b5bdb;
        border-radius: 4px;
        padding: 2px 7px;
        font-size: .71rem;
        font-weight: 600;
        margin: 1px 2px 1px 0;
        white-space: normal;
    }
    .clinical-val {
        font-size: .83rem;
        font-weight: 600;
        color: #343a40;
    }
    .clinical-val--high {
        color: #dc3545;
    }
    </style>

    {{-- ── Diagnosis search JS (event delegation — Livewire-safe) ──────── --}}
    <script>
    (function () {
        if (window.__diagInit) return;
        window.__diagInit = true;

        function filterDiag(wrapper, term) {
            var opts    = wrapper.querySelectorAll('.diag-opt');
            var noRes   = wrapper.querySelector('.diag-no-results');
            var visible = 0;
            opts.forEach(function (opt) {
                var match = !term || (opt.dataset.name || '').includes(term);
                opt.style.display = match ? 'flex' : 'none';
                if (match) visible++;
            });
            if (noRes) noRes.style.display = (visible === 0 && term) ? 'block' : 'none';
        }

        document.addEventListener('click', function (e) {
            if (e.target.closest('.diag-panel')) return;
            var toggle = e.target.closest('.diag-toggle');
            if (toggle) {
                e.preventDefault();
                var wrapper = toggle.closest('.diag-multiselect');
                var panel   = wrapper.querySelector('.diag-panel');
                var search  = wrapper.querySelector('.diag-search');
                var isOpen  = panel.style.display === 'block';
                document.querySelectorAll('.diag-panel').forEach(function (p) { p.style.display = 'none'; });
                if (!isOpen) {
                    panel.style.display = 'block';
                    if (search) {
                        search.value = '';
                        filterDiag(wrapper, '');
                        setTimeout(function () { search.focus(); }, 20);
                    }
                }
                return;
            }
            document.querySelectorAll('.diag-panel').forEach(function (p) { p.style.display = 'none'; });
        });

        document.addEventListener('input', function (e) {
            if (!e.target.classList.contains('diag-search')) return;
            var wrapper = e.target.closest('.diag-multiselect');
            if (wrapper) filterDiag(wrapper, e.target.value.toLowerCase().trim());
        });
    })();
    </script>
</div>
