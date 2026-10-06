<div class="referral-wrap">
<div class="p-6">

    {{-- ── PAGE HEADER ── --}}
    <div class="flex justify-between items-center mb-6">
        <div>
            <h4 class="font-semibold mb-0"><i class="fas fa-file-medical mr-2 text-teal-700"></i>Clinical Letters</h4>
            <p class="text-slate-500 text-sm mb-0">Referrals, Medical Reports &amp; Excuse Duty Letters</p>
        </div>
        <div class="flex" style="gap:8px;">
            <button wire:click="openCreate('referral')" class="btn ui-button ui-button-primary ui-button-sm">
                <i class="fas fa-paper-plane mr-1"></i>Referral
            </button>
            <button wire:click="openCreate('medical_report')" class="btn ui-button ui-button-primary ui-button-sm">
                <i class="fas fa-file-medical-alt mr-1"></i>Medical Report
            </button>
            <button wire:click="openCreate('excuse_duty')" class="btn ui-button ui-button-secondary ui-button-sm">
                <i class="fas fa-calendar-times mr-1"></i>Excuse Duty
            </button>
        </div>
    </div>

    {{-- ── FILTER BAR ── --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mb-6">
        <div class="card-body p-4 py-4">
            <div class="flex flex-wrap -mx-2 items-center">
                <div class="w-full md:w-3/12 px-2 mb-2 md:mb-0">
                    <div class="flex items-stretch">
                        <div class="flex">
                            <span class="flex items-center border border-slate-300 px-2 text-sm text-slate-600 bg-white border-r-0"><i class="fas fa-search text-slate-500"></i></span>
                        </div>
                        <input type="text" wire:model.live.debounce.400ms="searchQuery"
                            class="form-control ui-input border-l-0"
                            placeholder="Patient, referral to, diagnosis…">
                    </div>
                </div>
                <div class="w-full md:w-2/12 px-2 mb-2 md:mb-0">
                    <select wire:model.live="typeFilter" class="form-control ui-input ui-input-sm">
                        <option value="">All Types</option>
                        <option value="referral">Referral</option>
                        <option value="medical_report">Medical Report</option>
                        <option value="excuse_duty">Excuse Duty</option>
                    </select>
                </div>
                <div class="w-full md:w-2/12 px-2 mb-2 md:mb-0">
                    <select wire:model.live="statusFilter" class="form-control ui-input ui-input-sm">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="w-full md:w-4/12 px-2 mb-2 md:mb-0"><x-date-range from="fromDate" to="toDate" presets="activity" /></div>
                <div class="w-full md:w-1/12 px-2">
                    <select wire:model.live="perPage" class="form-control ui-input ui-input-sm">
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- ── LETTERS TABLE ── --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm">
        <div class="card-header border-b border-slate-200 px-4 bg-white border-0 py-4 flex justify-between items-center">
            <h6 class="mb-0 font-semibold">Letter Records</h6>
            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">{{ $referrals->total() }} total</span>
        </div>
        <div class="card-body p-0">
            <div class="ui-table-wrap">
                <table class="table ui-table mb-0 referral-table">
                    <thead class="">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Patient</th>
                            <th>Details</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">By</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($referrals as $ref)
                        <tr>
                            <td class="whitespace-nowrap">
                                <div class="font-semibold text-sm">{{ $ref->referral_date->format('M d, Y') }}</div>
                                <small class="text-slate-500">{{ $ref->created_at->diffForHumans() }}</small>
                            </td>
                            <td>
                                <span class="type-badge type-badge--{{ $ref->letter_type }}">
                                    @if($ref->letter_type === 'referral')
                                        <i class="fas fa-paper-plane fa-xs mr-1"></i>Referral
                                    @elseif($ref->letter_type === 'medical_report')
                                        <i class="fas fa-file-medical-alt fa-xs mr-1"></i>Medical Report
                                    @else
                                        <i class="fas fa-calendar-times fa-xs mr-1"></i>Excuse Duty
                                    @endif
                                </span>
                            </td>
                            <td>
                                <div class="font-semibold">{{ $ref->patient_name }}</div>
                                @if($ref->patient_age_sex)
                                    <small class="text-slate-500">{{ $ref->patient_age_sex }}</small>
                                @endif
                            </td>
                            <td>
                                @if($ref->letter_type === 'referral')
                                    <small class="text-teal-700">To: {{ Str::limit($ref->referral_to, 35) }}</small><br>
                                    <small class="text-slate-500">{{ Str::limit($ref->diagnosis_display, 35) }}</small>
                                @elseif($ref->letter_type === 'medical_report')
                                    <small class="text-slate-500">{{ Str::limit($ref->diagnosis_display, 50) ?: '—' }}</small>
                                @else
                                    <small class="text-slate-500">
                                        @if($ref->excuse_from_date && $ref->excuse_to_date)
                                            {{ $ref->excuse_from_date->format('M d') }} – {{ $ref->excuse_to_date->format('M d, Y') }}
                                        @else —
                                        @endif
                                    </small>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="relative inline-block text-left" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                                    <button type="button" class="status-badge status-badge--{{ $ref->status }}" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="menu">
                                        {{ ucfirst($ref->status) }} <i class="fas fa-caret-down ml-1" aria-hidden="true"></i>
                                    </button>
                                    <div x-show="open" x-cloak role="menu" class="absolute right-0 z-20 mt-1 w-36 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-left shadow-lg">
                                        <button type="button" role="menuitem" class="block w-full px-3 py-1.5 text-sm hover:bg-slate-50" x-on:click="open = false" wire:click="updateStatus({{ $ref->id }}, 'pending')"><span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 mr-1">●</span>Pending</button>
                                        <button type="button" role="menuitem" class="block w-full px-3 py-1.5 text-sm hover:bg-slate-50" x-on:click="open = false" wire:click="updateStatus({{ $ref->id }}, 'completed')"><span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800 mr-1">●</span>Completed</button>
                                        <button type="button" role="menuitem" class="block w-full px-3 py-1.5 text-sm hover:bg-slate-50" x-on:click="open = false" wire:click="updateStatus({{ $ref->id }}, 'cancelled')"><span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700 mr-1">●</span>Cancelled</button>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <small class="text-slate-500">{{ $ref->referredBy->name ?? '—' }}</small>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('doctor.referral.pdf', $ref->id) }}" target="_blank"
                                   class="btn ui-button ui-button-sm ui-button-danger" title="Print Letter">
                                    <i class="fas fa-print"></i>
                                </a>
                                <button wire:click="openEdit({{ $ref->id }})" class="btn ui-button ui-button-sm ui-button-secondary ml-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                @if(auth()->user()->hasRole('Super Admin'))
                                <button wire:click="confirmDelete({{ $ref->id }})" class="btn ui-button ui-button-sm ui-button-danger ml-1" title="Delete">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                @else
                                <button class="btn ui-button ui-button-sm ui-button-secondary ml-1" disabled title="Only Super Admin can delete">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-12">
                                <i class="fas fa-file-medical fa-3x text-slate-500 mb-4 block"></i>
                                <p class="text-slate-500 mb-4">No letters found.</p>
                                <div class="flex justify-center" style="gap:8px;">
                                    <button wire:click="openCreate('referral')" class="btn ui-button ui-button-sm ui-button-primary">
                                        <i class="fas fa-paper-plane mr-1"></i>New Referral
                                    </button>
                                    <button wire:click="openCreate('medical_report')" class="btn ui-button ui-button-sm ui-button-primary">
                                        <i class="fas fa-file-medical-alt mr-1"></i>Medical Report
                                    </button>
                                    <button wire:click="openCreate('excuse_duty')" class="btn ui-button ui-button-sm ui-button-secondary">
                                        <i class="fas fa-calendar-times mr-1"></i>Excuse Duty
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($referrals->hasPages())
        <div class="border-t border-slate-200 px-4 bg-white border-0 py-4">{{ $referrals->links() }}</div>
        @endif
    </div>

</div>

{{-- ═══════════════════════ MODAL ═══════════════════════ --}}
@if($showModal)
@php
    $fl = 'mb-1 block text-xs font-semibold text-slate-600';
    $req = '<span class="text-red-700">*</span>';
    $sectionTitle = 'mb-3 flex items-center gap-2 border-b border-slate-200 pb-2 text-xs font-bold uppercase tracking-wide text-slate-500';
    $accent = [
        'referral' => ['icon' => 'fa-paper-plane', 'title' => 'Referral Letter', 'chip' => 'bg-sky-100 text-sky-700', 'active' => 'border-sky-500 bg-sky-50 text-sky-800'],
        'medical_report' => ['icon' => 'fa-file-medical-alt', 'title' => 'Medical Report', 'chip' => 'bg-emerald-100 text-emerald-700', 'active' => 'border-emerald-500 bg-emerald-50 text-emerald-800'],
        'excuse_duty' => ['icon' => 'fa-calendar-times', 'title' => 'Excuse Duty Letter', 'chip' => 'bg-amber-100 text-amber-700', 'active' => 'border-amber-500 bg-amber-50 text-amber-800'],
    ];
    $current = $accent[$letterType] ?? $accent['referral'];
    $locked = $selectedPatientId ? 'readonly' : '';
    $lockedClass = $selectedPatientId ? '!bg-slate-100 !text-slate-500 cursor-not-allowed' : '';
    $vaOptions = \App\Livewire\Doctor\PatientRecordsComponent::vaLogMarTable($vaNotation);
@endphp
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:py-8" wire:click.self="closeModal">
    <div class="flex max-h-[calc(100vh-4rem)] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="letter-dialog-title">

        {{-- Header --}}
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $current['chip'] }}"><i class="fas {{ $current['icon'] }}" aria-hidden="true"></i></span>
                <div>
                    <h2 id="letter-dialog-title" class="!mb-0 text-base font-semibold text-slate-900">{{ $editingId ? 'Edit' : 'New' }} {{ $current['title'] }}</h2>
                    <p class="!mb-0 text-xs text-slate-500">{{ $appSettings->clinic_name ?? \App\Models\Setting::DEFAULT_CLINIC_NAME }}</p>
                </div>
            </div>
            <button type="button" wire:click="closeModal" class="flex h-8 w-8 items-center justify-center rounded-full text-xl leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Close dialog">&times;</button>
        </div>

        {{-- Body --}}
        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-5 py-5">

            {{-- Letter type (only on create) --}}
            @if(!$editingId)
            <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Letter type">
                @foreach($accent as $type => $info)
                    <button type="button" wire:click="$set('letterType','{{ $type }}')" role="radio" aria-checked="{{ $letterType === $type ? 'true' : 'false' }}"
                        class="flex items-center justify-center gap-2 rounded-lg border-2 px-3 py-2.5 text-sm font-semibold transition {{ $letterType === $type ? $info['active'] : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50' }}">
                        <i class="fas {{ $info['icon'] }}" aria-hidden="true"></i><span>{{ $info['title'] }}</span>
                    </button>
                @endforeach
            </div>
            @endif

            {{-- Letter details --}}
            <section>
                <h3 class="{{ $sectionTitle }}"><i class="fas fa-info-circle text-teal-700" aria-hidden="true"></i>Letter details</h3>
                <div class="grid gap-4 {{ $letterType === 'referral' ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
                    <div>
                        <label for="letter-date" class="{{ $fl }}">Date {!! $req !!}</label>
                        <input id="letter-date" type="date" wire:model.live="referralDate" class="ui-input @error('referralDate') is-invalid @enderror">
                        @error('referralDate')<p class="ui-error">{{ $message }}</p>@enderror
                    </div>
                    @if($letterType === 'referral')
                    <div class="sm:col-span-2">
                        <label for="letter-referral-to" class="{{ $fl }}">Referral to {!! $req !!}</label>
                        <input id="letter-referral-to" type="text" wire:model.live.debounce.400ms="referralTo" class="ui-input @error('referralTo') is-invalid @enderror" placeholder="e.g. KATH – Ophthalmology Dept.">
                        @error('referralTo')<p class="ui-error">{{ $message }}</p>@enderror
                    </div>
                    @endif
                </div>
            </section>

            {{-- Patient --}}
            <section>
                <h3 class="{{ $sectionTitle }}"><i class="fas fa-user text-teal-700" aria-hidden="true"></i>Patient information</h3>
                <div class="mb-4">
                    <label for="letter-patient-search" class="{{ $fl }}">Search existing patient</label>
                    <div class="relative">
                        <i class="fas fa-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                        <input id="letter-patient-search" type="text" wire:model.live.debounce.300ms="patientSearch" class="ui-input !pl-9 {{ $selectedPatientId ? '!pr-10' : '' }}" placeholder="Type patient name or PX number…" autocomplete="off">
                        @if($selectedPatientId)
                            <button type="button" wire:click="clearPatient" class="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Unlink patient"><i class="fas fa-times" aria-hidden="true"></i></button>
                        @endif
                        @if(count($patientSuggestions) > 0)
                        <div class="absolute left-0 right-0 top-full z-10 mt-1 max-h-52 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                            @foreach($patientSuggestions as $p)
                            <button type="button" wire:click="selectPatient({{ $p['id'] }})" class="block w-full border-b border-slate-100 px-4 py-2 text-left hover:bg-teal-50">
                                <span class="block text-sm font-semibold">{{ $p['name'] }}</span>
                                <span class="text-xs text-slate-500">PX#{{ $p['pxnumber'] }}
                                    @if($p['gender']) · {{ ucfirst($p['gender']) }} @endif
                                    @if($p['contact']) · {{ $p['contact'] }} @endif
                                </span>
                            </button>
                            @endforeach
                        </div>
                        @endif
                    </div>
                    @if($selectedPatientId)
                        <p class="mt-1 text-xs text-green-700"><i class="fas fa-check-circle mr-1" aria-hidden="true"></i>Patient linked from records</p>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label for="letter-patient-name" class="{{ $fl }}">
                            Patient name {!! $req !!}
                            @if($selectedPatientId)
                                <span class="ml-1 inline-flex items-center rounded-full border border-green-200 bg-green-50 px-2 text-xs font-semibold text-green-700"><i class="fas fa-lock fa-xs mr-1" aria-hidden="true"></i>Linked</span>
                            @endif
                        </label>
                        <input id="letter-patient-name" type="text" wire:model.live.debounce.400ms="patientName" class="ui-input {{ $lockedClass }} @error('patientName') is-invalid @enderror" placeholder="Full name" {{ $locked }}>
                        @error('patientName')<p class="ui-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="letter-patient-age" class="{{ $fl }}">Age / sex</label>
                        <input id="letter-patient-age" type="text" wire:model.live.debounce.400ms="patientAgeSex" class="ui-input {{ $lockedClass }}" placeholder="e.g. 34yrs / M" {{ $locked }}>
                    </div>
                    <div>
                        <label for="letter-patient-contact" class="{{ $fl }}">Contact</label>
                        <input id="letter-patient-contact" type="text" wire:model.live.debounce.400ms="patientContact" class="ui-input {{ $lockedClass }}" placeholder="Phone" {{ $locked }}>
                    </div>
                </div>
            </section>

            @if($letterType === 'referral')
            <section>
                <h3 class="{{ $sectionTitle }}"><i class="fas fa-stethoscope text-teal-700" aria-hidden="true"></i>Clinical findings</h3>
                <div class="space-y-4">
                    <div>
                        <label for="letter-complaint" class="{{ $fl }}">Chief complaint</label>
                        <input id="letter-complaint" type="text" wire:model.live.debounce.400ms="complaint" class="ui-input" placeholder="Patient's main complaint">
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label for="letter-va-od" class="{{ $fl }}">VA — OD (right)</label>
                            <select id="letter-va-od" wire:model.live="vaOd" class="ui-input">
                                <option value="">Select…</option>
                                @foreach($vaOptions as $label => $logmar)
                                    <option value="{{ $label }}">{{ $label }}{{ $logmar !== null ? ' ('.($logmar >= 0 ? '+' : '').$logmar.')' : ' (NM)' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="letter-va-os" class="{{ $fl }}">VA — OS (left)</label>
                            <select id="letter-va-os" wire:model.live="vaOs" class="ui-input">
                                <option value="">Select…</option>
                                @foreach($vaOptions as $label => $logmar)
                                    <option value="{{ $label }}">{{ $label }}{{ $logmar !== null ? ' ('.($logmar >= 0 ? '+' : '').$logmar.')' : ' (NM)' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="letter-iop" class="{{ $fl }}">IOP</label>
                            <input id="letter-iop" type="text" wire:model.live.debounce.400ms="iop" class="ui-input" placeholder="e.g. OD 16 / OS 18">
                        </div>
                        <div>
                            <label for="letter-refraction" class="{{ $fl }}">Refraction</label>
                            <input id="letter-refraction" type="text" wire:model.live.debounce.400ms="refraction" class="ui-input" placeholder="e.g. -2.00 DS">
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="letter-anterior" class="{{ $fl }}">Anterior segment</label>
                            <input id="letter-anterior" type="text" wire:model.live.debounce.400ms="anteriorSegment" class="ui-input" placeholder="Anterior findings">
                        </div>
                        <div>
                            <label for="letter-posterior" class="{{ $fl }}">Posterior segment</label>
                            <input id="letter-posterior" type="text" wire:model.live.debounce.400ms="posteriorSegment" class="ui-input" placeholder="Posterior findings">
                        </div>
                    </div>
                </div>
            </section>
            <section>
                <h3 class="{{ $sectionTitle }}"><i class="fas fa-clipboard-list text-teal-700" aria-hidden="true"></i>Diagnosis &amp; management</h3>
                <div class="space-y-4">
                    <div>
                        <label class="{{ $fl }}">Diagnosis</label>
                        <x-ui.multi-select model="selectedDiagnoses" :options="$diagnoses->pluck('name')" placeholder="Search and select diagnosis…" />
                        @error('selectedDiagnoses')<p class="ui-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="letter-reason" class="{{ $fl }}">Reason for referral</label>
                        <textarea id="letter-reason" wire:model.live.debounce.400ms="reasonForReferral" class="ui-input" rows="2" placeholder="Why is this patient being referred?"></textarea>
                    </div>
                    <div>
                        <label for="letter-management" class="{{ $fl }}">Management given / notes</label>
                        <textarea id="letter-management" wire:model.live.debounce.400ms="management" class="ui-input" rows="2" placeholder="Treatment already given…"></textarea>
                    </div>
                </div>
            </section>
            @endif

            @if($letterType === 'medical_report')
            <section>
                <h3 class="{{ $sectionTitle }}"><i class="fas fa-notes-medical text-teal-700" aria-hidden="true"></i>Clinical details</h3>
                <div class="space-y-4">
                    <div>
                        <label for="letter-findings" class="{{ $fl }}">Clinical findings</label>
                        <textarea id="letter-findings" wire:model.live.debounce.400ms="clinicalFindings" class="ui-input" rows="3" placeholder="Describe clinical findings on examination…"></textarea>
                    </div>
                    <div>
                        <label class="{{ $fl }}">Diagnosis</label>
                        <x-ui.multi-select model="selectedDiagnoses" :options="$diagnoses->pluck('name')" placeholder="Search and select diagnosis…" />
                        @error('selectedDiagnoses')<p class="ui-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="letter-treatment" class="{{ $fl }}">Management / treatment</label>
                        <textarea id="letter-treatment" wire:model.live.debounce.400ms="treatment" class="ui-input" rows="2" placeholder="Treatment and management plan"></textarea>
                    </div>
                    <div>
                        <label for="letter-recommendation" class="{{ $fl }}">Recommendation</label>
                        <textarea id="letter-recommendation" wire:model.live.debounce.400ms="recommendation" class="ui-input" rows="2" placeholder="Recommendations for the patient"></textarea>
                    </div>
                </div>
            </section>
            @endif

            @if($letterType === 'excuse_duty')
            <section>
                <h3 class="{{ $sectionTitle }}"><i class="fas fa-calendar-times text-teal-700" aria-hidden="true"></i>Excuse period</h3>
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="letter-excuse-from" class="{{ $fl }}">Excused from {!! $req !!}</label>
                            <input id="letter-excuse-from" type="date" wire:model.live="excuseFromDate" class="ui-input @error('excuseFromDate') is-invalid @enderror">
                            @error('excuseFromDate')<p class="ui-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="letter-excuse-to" class="{{ $fl }}">Excused until {!! $req !!}</label>
                            <input id="letter-excuse-to" type="date" wire:model.live="excuseToDate" class="ui-input @error('excuseToDate') is-invalid @enderror">
                            @error('excuseToDate')<p class="ui-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div>
                        <label for="letter-excuse-notes" class="{{ $fl }}">Letter notes</label>
                        <textarea id="letter-excuse-notes" wire:model="excuseNotes" rows="6" class="ui-input @error('excuseNotes') is-invalid @enderror" placeholder="Enter the wording to print on the letter"></textarea>
                        <p class="mt-1 text-xs text-slate-500">Prefilled and fully editable. Date placeholders are filled automatically on the letter.</p>
                        @error('excuseNotes')<p class="ui-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="{{ $fl }}">Reason / diagnosis <span class="font-normal text-slate-500">(optional, not printed on the letter)</span></label>
                        <x-ui.multi-select model="selectedDiagnoses" :options="$diagnoses->pluck('name')" placeholder="Search and select diagnosis…" />
                    </div>
                </div>
            </section>
            @endif

            {{-- Status (shared) --}}
            <section>
                <h3 class="{{ $sectionTitle }}"><i class="fas fa-flag text-teal-700" aria-hidden="true"></i>Record status</h3>
                <select wire:model.live="status" class="ui-input sm:max-w-xs" aria-label="Record status">
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </section>

        </div>

        {{-- Footer --}}
        <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
            <button type="button" wire:click="closeModal" class="ui-button ui-button-secondary"><i class="fas fa-times" aria-hidden="true"></i>Cancel</button>
            <button type="button" wire:click="save" wire:loading.attr="disabled" class="ui-button ui-button-primary">
                <span wire:loading.remove wire:target="save"><i class="fas fa-save mr-1" aria-hidden="true"></i>{{ $editingId ? 'Update' : 'Save & create' }} letter</span>
                <span wire:loading wire:target="save"><span class="mr-1 inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-r-transparent"></span>Saving…</span>
            </button>
        </div>

    </div>
</div>
@endif

{{-- ── DELETE CONFIRM ── --}}
<div wire:ignore.self id="deleteConfirmModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-sm overflow-hidden rounded-xl bg-white shadow-xl" role="alertdialog" aria-modal="true" aria-labelledby="delete-letter-title">
        <div class="flex items-center justify-between bg-red-600 px-4 py-3 text-white">
            <h2 id="delete-letter-title" class="text-sm font-semibold"><i class="fas fa-exclamation-triangle mr-2" aria-hidden="true"></i>Delete letter</h2>
            <button type="button" class="text-xl leading-none text-white/80 hover:text-white" wire:click="cancelDelete" aria-label="Close dialog">&times;</button>
        </div>
        <div class="px-4 py-6 text-center">
            <p class="mb-4">Delete this letter permanently?</p>
            <button type="button" wire:click="deleteReferral" class="ui-button ui-button-danger mr-2"><i class="fas fa-trash" aria-hidden="true"></i>Delete</button>
            <button type="button" wire:click="cancelDelete" class="ui-button ui-button-secondary">Cancel</button>
        </div>
    </div>
</div>

<script>
(function () {
    function deleteDialog(open) {
        var dialog = document.getElementById('deleteConfirmModal');
        if (!dialog) return;
        dialog.classList.toggle('hidden', !open);
        dialog.classList.toggle('flex', open);
    }
    window.addEventListener('show-delete-confirm', function () { deleteDialog(true); });
    window.addEventListener('hide-delete-confirm', function () { deleteDialog(false); });
})();
</script>

<style>
/* ── Table ── */
.referral-table th { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6c757d; border-top:none; padding:.75rem 1rem; }
.referral-table td { padding:.75rem 1rem; vertical-align:middle; font-size:.875rem; }
.referral-table tbody tr:hover { background:#f8f9fa; }

/* ── Type badges ── */
.type-badge { display:inline-block; font-size:.72rem; font-weight:600; border-radius:20px; padding:.25rem .7rem; }
.type-badge--referral       { background:#e7f0ff; color:#0048c0; }
.type-badge--medical_report { background:#e6f9ef; color:#1a6e40; }
.type-badge--excuse_duty    { background:#fff3cd; color:#856404; }

/* ── Status badges ── */
.status-badge { font-size:.75rem; font-weight:600; border-radius:20px; padding:.3rem .8rem; border:none; cursor:pointer; }
.status-badge--pending   { background:#fff3cd; color:#856404; }
.status-badge--completed { background:#d4edda; color:#155724; }
.status-badge--cancelled { background:#e2e3e5; color:#383d41; }

</style>
</div>
