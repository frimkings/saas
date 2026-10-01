<div data-livewire-root>
<div class="emr-root">

    {{-- Premium Patient Profile Header --}}
    <div class="px-header">
        <div class="px-header__inner">

            {{-- LEFT: Avatar + Identity --}}
            <div class="px-header__identity">
                <div class="px-avatar">{{ strtoupper(substr($patient->name, 0, 1)) }}</div>
                <div class="px-header__name-block">
                    <div class="px-header__name">{{ $patient->name }}</div>
                    <div class="px-header__meta">
                        <span class="px-id">{{ $patient->pxnumber }}</span>
                        <span class="px-divider">·</span>
                        @if($patient->gender === 'Male')
                            <span class="px-badge px-badge--gender"><i class="fas fa-mars"></i> Male</span>
                        @elseif($patient->gender === 'Female')
                            <span class="px-badge px-badge--gender-f"><i class="fas fa-venus"></i> Female</span>
                        @else
                            <span class="px-badge">{{ $patient->gender }}</span>
                        @endif
                        <span class="px-badge px-badge--age">{{ \Carbon\Carbon::parse($patient->dob)->age }} yrs</span>
                        @php
                            $hasUnusedClearance = \App\Models\CashierPatientClearance::where('patient_id', $patient->id)
                                ->where('payment_status', 'Paid')
                                ->where('doctor_status', false)
                                ->exists();
                        @endphp
                        @if($hasUnusedClearance)
                            <span class="px-badge px-badge--ready"><i class="fas fa-check-circle"></i> Ready</span>
                        @elseif($clearance && $clearance->payment_status === 'Paid')
                            <span class="px-badge px-badge--used"><i class="fas fa-check-double"></i> Used</span>
                        @elseif($clearance)
                            <span class="px-badge px-badge--warn"><i class="fas fa-exclamation-triangle"></i> Unpaid</span>
                        @else
                            <span class="px-badge px-badge--danger"><i class="fas fa-times-circle"></i> No Clearance</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- CENTER: Demographics --}}
            <div class="px-header__demographics">
                <div class="px-demo-item">
                    <span class="px-demo-label">DOB</span>
                    <span class="px-demo-value">{{ \Carbon\Carbon::parse($patient->dob)->format('d M, Y') }}</span>
                </div>
                <div class="px-demo-sep"></div>
                <div class="px-demo-item">
                    <span class="px-demo-label">Occupation</span>
                    <span class="px-demo-value"><i class="fas fa-briefcase" style="color:#22C55E;font-size:10px;"></i> {{ $patient->occupation}}</span>
                </div>
                <div class="px-demo-sep"></div>
                <div class="px-demo-item">
                    <span class="px-demo-label">Total Visits</span>
                    <span class="px-demo-value px-demo-value--highlight">{{ $consultationCount }}</span>
                </div>
            </div>

            {{-- RIGHT: Actions --}}
            <div class="px-header__actions">
                @if($this->canStartConsultation)
                    <button wire:click="startNewConsultation" class="px-btn px-btn--primary">
                        <i class="fas fa-plus"></i> New Visit
                    </button>
                @else
                    <button disabled class="px-btn px-btn--ghost"
                        title="{{ !$clearance ? 'No clearance' : ($clearance->payment_status !== 'Paid' ? 'Payment required' : 'Clearance used') }}">
                        <i class="fas fa-ban"></i> {{ !$clearance ? 'No Clearance' : ($clearance->payment_status !== 'Paid' ? 'Unpaid' : 'Used') }}
                    </button>
                @endif
                @if($clearance)
                    <a href="{{ route('doctor.medical-record.pdf', ['patient' => $patient, 'clearance' => $clearance]) }}"
                        class="px-btn px-btn--ghost" title="Download Medical Record PDF">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                @else
                    <button disabled class="px-btn px-btn--ghost" title="No clearance available">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                @endif
                <a href="{{ route('doctor.patient-timeline', $patient) }}"
                    class="px-btn px-btn--icon" title="View Clinical Timeline">
                    <i class="fas fa-stream"></i>
                </a>
            </div>

        </div>
    </div>

    {{-- Main Content Card --}}
    <div class="emr-main-card">
        <div class="emr-tab-bar">
            <button class="emr-tab {{ $activeTab === 'history' ? 'emr-tab--active' : '' }}"
                wire:click.prevent="switchTab('history')">
                <i class="fas fa-history"></i> History
                <span class="emr-tab-badge">{{ $consultationCount }}</span>
            </button>
            <button class="emr-tab {{ $activeTab === 'consultation' ? 'emr-tab--active' : '' }}"
                wire:click.prevent="switchTab('consultation')">
                <i class="fas fa-stethoscope"></i> Consultation
                @if($isEditMode)
                    <span class="emr-tab-dot"></span>
                @endif
            </button>
            <button class="emr-tab {{ $activeTab === 'prescription' ? 'emr-tab--active' : '' }}"
                wire:click.prevent="switchTab('prescription')">
                <i class="fas fa-prescription"></i> Prescription
                @if(count($productsList) > 0)
                    <span class="emr-tab-badge emr-tab-badge--red">{{ count($productsList) }}</span>
                @endif
            </button>
            <button class="emr-tab {{ $activeTab === 'refraction' ? 'emr-tab--active' : '' }}"
                wire:click.prevent="switchTab('refraction')">
                <i class="fas fa-glasses"></i> Refraction
            </button>
            <button class="emr-tab {{ $activeTab === 'bills' ? 'emr-tab--active' : '' }}"
                wire:click.prevent="switchTab('bills')">
                <i class="fas fa-folder-open"></i> Clinical Documents
                @if($patientDocumentCount > 0)
                    <span class="emr-tab-badge">{{ $patientDocumentCount }}</span>
                @endif
            </button>
            @if($activeTab === 'consultation')
                <div class="consultation-tab-context"><div><strong><i class="fas fa-stethoscope"></i> {{ $isEditMode ? 'Edit Consultation' : 'New Consultation' }}</strong><small>{{ $isEditMode && $consultation ? 'Created by '.($consultation->user->name ?? 'N/A').' · '.$consultation->created_at->format('d M Y h:i A') : 'Clinical record in progress' }}</small></div><button type="button" onclick="if (window.consultationDirty) { event.stopImmediatePropagation(); appConfirm('Discard unsaved consultation changes?').then(ok => ok &amp;&amp; Livewire.find(this.closest('[wire\\:id]').getAttribute('wire:id')).cancelAndGoBack()) }" wire:click="cancelAndGoBack"><i class="fas fa-arrow-left"></i> Cancel</button></div>
            @endif
        </div>

        <div class="emr-tab-content">
            <div class="emr-tab-loading" wire:loading.flex wire:target="switchTab" aria-live="polite">
                <div class="emr-tab-loading__spinner"><i class="fas fa-circle-notch fa-spin"></i></div>
                <div><strong>Loading section</strong><span>Fetching only the records needed for this tab…</span></div>
            </div>
            {{-- TAB 1: CONSULTATION HISTORY --}}
            @if($activeTab === 'history')
                <div class="history-tab history-prototype" x-data="{historyView:'overview'}">

                    {{-- Header + Search --}}
                    <div class="history-header">
                        <div class="history-header__title">
                            <i class="fas fa-clipboard-list" style="color:#2563EB;"></i>
                            Patient Consultation History
                        </div>
                        <div style="position:relative;">
                            <div class="search-box">
                                <i class="fas fa-search search-box__icon"></i>
                                <input type="text" wire:model.live.debounce.300ms="searchTerm" class="search-box__input" placeholder="Search records..." @focus="historyView='records'">
                            </div>
                        </div>
                    </div>

                    <div id="clinical-trend-data" data-trends='@json($clinicalTrendData)' style="display:none;"></div>

                    @include('livewire.doctor.partials.history-health-summary')
                    <nav class="history-subnav" aria-label="Patient history views">
                        <button type="button" :class="{'is-active':historyView==='overview'}" :aria-pressed="historyView==='overview'" @click="historyView='overview'; $nextTick(()=>window.dispatchEvent(new Event('resize')))"><i class="fas fa-chart-line"></i> Charts & Refraction History</button>
                        <button type="button" :class="{'is-active':historyView==='scans'}" :aria-pressed="historyView==='scans'" @click="historyView='scans'"><i class="fas fa-images"></i> Diagnostic Scans <span>{{ $patientDocumentCount }}</span></button>
                        <button type="button" :class="{'is-active':historyView==='records'}" :aria-pressed="historyView==='records'" @click="historyView='records'"><i class="fas fa-history"></i> Past Encounters & Records <span>{{ $consultationCount }}</span></button>
                    </nav>
<div x-show="historyView==='overview'">
<div class="history-charts-grid">
                        {{-- VA Trend Chart --}}
                        <div class="metrics-card">
                            <div class="metrics-card__header">
                                <div class="metrics-card__title">
                                    <i class="fas fa-chart-line" style="color:#2563EB;"></i>
                                    Visual Acuity Trend
                                </div>
                                <span class="metrics-card__subtitle">6m decimal equivalent · LogMAR scale</span>
                            </div>
                            <div class="metrics-card__body--chart">
                                @if($clinicalTrendData['summary']['hasVaData'] ?? false)
                                    <canvas id="visualAcuityTrendChart" wire:ignore></canvas>
                                @else
                                    <div class="chart-empty">
                                        <i class="fas fa-eye"></i>
                                        <span>No visual acuity data recorded yet</span>
                                    </div>
                                @endif
                            </div>
                        <div class="history-chart-stats"><div class="history-eye-od">Latest OD VA<strong>{{ $clinicalTrendData['summary']['latestVaOd'] ?? '—' }}</strong></div><div class="history-eye-os">Latest OS VA<strong>{{ $clinicalTrendData['summary']['latestVaOs'] ?? '—' }}</strong></div></div></div>                    {{-- IOP Trend --}}
                    <div class="metrics-card">
                        <div class="metrics-card__header">
                            <div class="metrics-card__title">
                                <i class="fas fa-chart-area" style="color:#22C55E;"></i>
                                IOP Trend
                            </div>
                            <span class="metrics-card__subtitle">mmHg · Normal &lt; 21</span>
                        </div>
                        <div class="metrics-card__body--chart">
                            @if($clinicalTrendData['summary']['hasIopData'] ?? false)
                                <canvas id="iopTrendChart" wire:ignore></canvas>
                            @else
                                <div class="chart-empty">
                                    <i class="fas fa-chart-line"></i>
                                    <span>No IOP data recorded yet</span>
                                </div>
                            @endif
                        </div>
                    <div class="history-chart-stats"><div class="history-eye-od">Latest OD IOP<strong>{{ $clinicalTrendData['summary']['latestIopOd'] ?? '—' }} <small>mmHg</small></strong></div><div class="history-eye-os">Latest OS IOP<strong>{{ $clinicalTrendData['summary']['latestIopOs'] ?? '—' }} <small>mmHg</small></strong></div></div></div></div>@include('livewire.doctor.partials.history-refraction-table')
                    {{-- Eye Disease Risk Flags --}}
                    <div class="section-card mb-section">
                        <div class="section-card__header">
                            <div class="section-card__title">
                                <i class="fas fa-flag" style="color:#EF4444;"></i>
                                Eye Disease Risk Flags
                            </div>
                            <span class="metrics-card__subtitle">Calculated from recent visits</span>
                        </div>
                        <div class="section-card__body">
                            <div class="risk-grid">
                                @foreach($eyeDiseaseRiskFlags as $flag)
                                    <div class="risk-card risk-card--{{ $flag['level'] }}">
                                        <div class="risk-card__head">
                                            <span class="risk-card__name">
                                                @if($flag['name'] === 'Glaucoma')
                                                    <i class="fas fa-eye"></i>
                                                @elseif($flag['name'] === 'Cataract')
                                                    <i class="fas fa-circle-notch"></i>
                                                @else
                                                    <i class="fas fa-tint"></i>
                                                @endif
                                                {{ $flag['name'] }}
                                            </span>
                                            @if($flag['level'] === 'warning')
                                                <span class="risk-badge risk-badge--review">Review</span>
                                            @elseif($flag['level'] === 'urgent')
                                                <span class="risk-badge risk-badge--urgent">Urgent</span>
                                            @else
                                                <span class="risk-badge risk-badge--clear">No flag</span>
                                            @endif
                                        </div>
                                        @if(!empty($flag['reasons']))
                                            <ul class="risk-card__reasons">
                                                @foreach($flag['reasons'] as $reason)
                                                    <li>{{ $reason }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="risk-card__empty">No obvious risk marker found in recorded data.</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>


</div>
<div x-show="historyView==='scans'" x-cloak>
@include('livewire.doctor.partials.history-scans')
</div>
<div x-show="historyView==='records'" x-cloak>
                    {{-- Consultation Visits --}}
                    <div class="section-card mb-section">
                        <div class="section-card__header">
                            <div class="section-card__title">
                                <i class="fas fa-list" style="color:#2563EB;"></i>
                                Consultation Visits
                                <span class="section-badge">{{ $patientRecords->total() }}</span>
                            </div>
                            <div class="section-card__actions">
                                @php $selectedSummaryIds = collect($selectedVisitSummaries)->filter()->implode(','); @endphp
                                <a href="{{ $selectedSummaryIds ? route('doctor.visit-summaries.download', ['ids' => $selectedSummaryIds]) : '#' }}"
                                    class="px-btn px-btn--ghost {{ $selectedSummaryIds ? '' : 'disabled' }}"
                                    title="Download selected visit summaries">
                                    <i class="fas fa-file-download"></i> Download Selected
                                    @if(count($selectedVisitSummaries) > 0)
                                        <span class="section-badge">{{ count($selectedVisitSummaries) }}</span>
                                    @endif
                                </a>

                            </div>
                        </div>
                        <div id="consultationHistoryCollapse">
                            @include('livewire.doctor.partials.history-encounters')
                        </div>
                    </div>

                    {{-- Audit Trail --}}
                    <div class="section-card" x-data="{auditOpen:false}">
                        <div class="section-card__header">
                            <div class="section-card__title">
                                <i class="fas fa-history" style="color:#F59E0B;"></i>
                                Recent Audit Trail
                                <span class="section-badge">{{ $auditTrails->count() }}</span>
                            </div>
                            <button class="px-btn px-btn--ghost" type="button" @click="auditOpen=!auditOpen" :aria-expanded="auditOpen">
                                <i class="fas" :class="auditOpen?'fa-chevron-up':'fa-chevron-down'"></i> <span x-text="auditOpen?'Hide':'Show'"></span>
                            </button>
                        </div>
                        <template x-if="auditOpen"><div id="recentAuditTrailCollapse">
                            <div class="audit-timeline">
                                @forelse($auditTrails as $audit)
                                    <div class="audit-item">
                                        <div class="audit-icon">
                                            @if($audit->event === 'created') <i class="fas fa-plus"></i>
                                            @elseif($audit->event === 'updated') <i class="fas fa-pen"></i>
                                            @elseif($audit->event === 'deleted') <i class="fas fa-trash"></i>
                                            @else <i class="fas fa-circle" style="font-size:6px;"></i>
                                            @endif
                                        </div>
                                        <div class="audit-body">
                                            <div class="audit-meta">
                                                <span class="audit-user">{{ $audit->user->name ?? 'System' }}</span>
                                                <span class="audit-event">{{ $audit->event }}</span>
                                                <span class="audit-time">{{ $audit->created_at->format('d M Y · h:i A') }}</span>
                                            </div>
                                            <div class="audit-desc">{{ $audit->description }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="audit-empty">No audit activity recorded yet.</div>
                                @endforelse
                                @if($auditLimit < 25 && $auditTrails->count() >= $auditLimit)
                                    <div class="text-center p-2"><button type="button" wire:click="loadMoreAuditTrail" class="px-btn px-btn--ghost"><i class="fas fa-plus"></i> Load more activity</button></div>
                                @endif
                            </div>
                        </div></template>
                    </div>
                    </div>

                </div>
            @endif

            {{-- TAB 2: CONSULTATION FORM --}}
            @if($activeTab === 'consultation')
                @php
                    $consultationFieldsLocked = $this->consultationFieldsLocked;
                    $consultationEditLockReason = $this->consultationEditLockReason;
                @endphp
                <div class="tab-content-wrapper">
                    {{-- Consultation Form (NEW or EDIT) - ALWAYS ACCESSIBLE --}}
                    <div class="consultation-titlebar d-none">
                        <div>
                            <h5 class="mb-1 text-primary font-weight-bold">
                                <i class="fas fa-stethoscope"></i>
                                {{ $isEditMode ? 'Edit Consultation Record' : 'New Consultation Record' }}
                            </h5>
                            @if($isEditMode && $consultation)
                                <small class="text-muted">
                                    Created by {{ $consultation->user->name ?? 'N/A' }} on {{ $consultation->created_at->format('d M Y h:i A') }}
                                </small>
                            @else
                                <small class="text-muted">Capture complaint, exam findings, diagnosis, and management plan.</small>
                            @endif
                        </div>
                        <button wire:click="cancelAndGoBack" class="btn btn-light">
                            <i class="fas fa-arrow-left"></i> Cancel
                        </button>
                    </div>

                    @if($consultationFieldsLocked)
                        <div class="consultation-lock-panel mb-3">
                            <div class="d-flex align-items-start">
                                <div class="consultation-lock-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div>
                                    <div class="font-weight-bold">Limited editing mode</div>
                                    <div>{{ $consultationEditLockReason }}</div>
                                    <small>Original clinical fields stay unchanged. Add a signed clinical addendum instead.</small>
                                </div>
                            </div>
                        </div>
                    @endif

                    @php
                        $urgentReferralReasons = $this->urgentReferralReasons;
                    @endphp

                    @if(!empty($urgentReferralReasons))
                        <div class="urgent-referral-panel mb-3">
                            <div class="d-flex justify-content-between align-items-start flex-wrap">
                                <div class="mb-2">
                                    <div class="font-weight-bold">
                                        <i class="fas fa-exclamation-triangle"></i> Urgent referral red flag detected
                                    </div>
                                    <div class="small">Detected: {{ implode(', ', $urgentReferralReasons) }}</div>
                                </div>
                                <button type="button"
                                    wire:click="createUrgentReferralDraft"
                                    wire:loading.attr="disabled"
                                    wire:target="createUrgentReferralDraft"
                                    class="btn btn-danger btn-sm">
                                    <span wire:loading.remove wire:target="createUrgentReferralDraft">
                                        <i class="fas fa-paper-plane"></i> Create Urgent Referral Draft
                                    </span>
                                    <span wire:loading wire:target="createUrgentReferralDraft">
                                        <i class="fas fa-spinner fa-spin"></i> Creating
                                    </span>
                                </button>
                            </div>
                        </div>
                    @endif

                    @php
                        $historyHasErrors = $errors->has('chiefComplaint') || $errors->has('state.chiefComplaint');
                        $examinationHasErrors = $errors->has('IOPOD') || $errors->has('IOPOS') || $errors->has('state.IOPOD') || $errors->has('state.IOPOS');
                        $managementHasErrors = $errors->has('diagnoses') || $errors->has('selectedDiagnoses');
                        $consultationErrorCount = $errors->count();
                    @endphp
                    <form wire:submit="{{ $isEditMode ? 'updateConsultation' : 'createConsultation' }}" x-data="{dirty:false,saving:false,destroy(){window.consultationDirty=false}}" @consultation-form-clean.window="dirty=false; saving=false; window.consultationDirty=false" @input.capture="dirty=true" @change.capture="dirty=true" @submit="saving=true" x-init="$watch('dirty',v=>window.consultationDirty=v); window.consultationDirty=false">
                        <nav class="consultation-step-nav" aria-label="Consultation sections">
                            <a href="#consultation-section-history" class="{{ $historyHasErrors ? 'has-error' : '' }}"><span>1</span> History @if($historyHasErrors)<i class="fas fa-exclamation-circle"></i>@endif</a>
                            <a href="#consultation-section-examination" class="{{ $examinationHasErrors ? 'has-error' : '' }}"><span>2</span> Examination @if($examinationHasErrors)<i class="fas fa-exclamation-circle"></i>@endif</a>
                            <a href="#consultation-section-management" class="{{ $managementHasErrors ? 'has-error' : '' }}"><span>3</span> Diagnosis & Plan @if($managementHasErrors)<i class="fas fa-exclamation-circle"></i>@endif</a>
                            <a href="#consultation-section-review" class="{{ $consultationErrorCount ? 'has-error' : '' }}"><span>4</span> Review @if($consultationErrorCount)<b>{{ $consultationErrorCount }}</b>@endif</a>
                            <strong x-show="dirty" x-cloak><i class="fas fa-circle"></i> Unsaved changes</strong>
                        </nav>
                        {{-- Chief Complaint & History --}}
                        <div id="consultation-section-history" class="card border mb-3 consultation-section {{ $consultationFieldsLocked ? 'consultation-section-locked' : '' }}">
                            <div class="card-header bg-light">
                                <h6 class="mb-0 font-weight-bold">
                                    Patient History
                                    @if($consultationFieldsLocked)
                                        <span class="badge badge-secondary ml-2"><i class="fas fa-lock"></i> Locked</span>
                                    @endif
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row consultation-history-grid">
                                    <div class="col-md-6 mb-3 order-1 consultation-history-chief">
                                        <label class="font-weight-bold">Chief Complaint <span
                                                class="text-danger">*</span></label>
                                        <textarea id="consultation-chief-complaint" wire:model="state.chiefComplaint" aria-describedby="chief-complaint-error"
                                            class="form-control {{ $historyHasErrors ? 'is-invalid' : '' }}"
                                            rows="3" placeholder="Enter patient's main complaint"
                                            {{ $consultationFieldsLocked ? 'disabled' : '' }}></textarea>
                                        @if($historyHasErrors)<div id="chief-complaint-error" class="invalid-feedback d-block"><i class="fas fa-exclamation-circle mr-1"></i>{{ $errors->first('chiefComplaint') ?: $errors->first('state.chiefComplaint') }}</div>@endif
                                    </div>
                                    <div class="col-md-6 mb-3 order-3 consultation-history-other">
                                        <label class="font-weight-bold">Other History</label>
                                        <textarea wire:model="state.others" class="form-control" rows="3"
                                            placeholder="Additional medical history"
                                            {{ $consultationFieldsLocked ? 'disabled' : '' }}></textarea>
                                    </div>
                                    <div class="col-md-6 mb-3 order-2 consultation-history-odq" wire:ignore>
                                        @php
                                            $odqOptions = [
                                                'Discharge',
                                                'Tearing',
                                                'Itching',
                                                'Eye Pain',
                                                'Redness',
                                                'Photophobia',
                                                'Blurred Vision',
                                                'Sudden Vision Loss',
                                                'Trauma',
                                                'Headache',
                                                'Floaters',
                                                'Flashes',
                                                'Double Vision',
                                                'Foreign Body Sensation',
                                                'Dry Eyes',
                                            ];
                                            $selectedOdq = collect($state['odq'] ?? [])->filter()->values()->toArray();
                                            $allOdqOptions = collect($odqOptions)->merge($selectedOdq)->unique()->values();
                                            $odqDetailKeys = $allOdqOptions->mapWithKeys(fn($option) => [$option => md5(mb_strtolower($option))]);
                                        @endphp
                                        <div x-data="{selected:$wire.entangle('state.odq'),details:$wire.entangle('state.odq_details'),options:@js($allOdqOptions->all()),keys:@js($odqDetailKeys->all()),custom:'',locked:@js($consultationFieldsLocked),init(){if(!Array.isArray(this.selected))this.selected=[];if(!this.details||Array.isArray(this.details))this.details={};this.selected.forEach(s=>this.ensure(s))},key(s){return this.keys[s]||('custom_'+encodeURIComponent(s.toLowerCase()).replaceAll('.','%2E'))},ensure(s){const k=this.key(s);if(!this.details[k])this.details[k]={symptom:s,severity:'',eye:''};this.details[k].symptom=s},toggle(s,on){if(on){if(!this.selected.includes(s))this.selected.push(s);this.ensure(s)}else this.selected=this.selected.filter(v=>v!==s)},add(){const s=this.custom.trim();if(!s||s.length>100)return;const found=this.options.find(v=>v.toLowerCase()===s.toLowerCase());const value=found||s;if(!found)this.options.push(value);if(!this.selected.includes(value))this.selected.push(value);this.ensure(value);this.custom=''}}" x-cloak>
                                            <label class="font-weight-bold">Ocular Symptoms <span class="text-muted font-weight-normal">(ODQ)</span></label>
                                            <div class="odq-chip-picker"><template x-for="option in options" :key="option"><label class="odq-chip" :class="{'is-selected':selected.includes(option)}"><input type="checkbox" :checked="selected.includes(option)" @change="toggle(option,$event.target.checked)" :disabled="locked"><span x-text="option"></span></label></template></div>
                                            <div class="odq-detail-list mt-2" x-show="selected.length"><template x-for="symptom in selected" :key="key(symptom)"><div class="odq-detail-row"><strong x-text="symptom"></strong><select x-model="details[key(symptom)].severity" class="form-control form-control-sm" :disabled="locked"><option value="">No grade</option><option value="+">+ Mild</option><option value="++">++ Moderate</option><option value="+++">+++ Severe</option></select><select x-model="details[key(symptom)].eye" class="form-control form-control-sm" :disabled="locked"><option value="">Eye not specified</option><option value="OD">OD — Right</option><option value="OS">OS — Left</option><option value="OU">OU — Both</option></select></div></template></div>
                                            <div class="input-group input-group-sm mt-2 odq-custom-entry" x-show="!locked"><input type="text" x-model="custom" @keydown.enter.prevent="add()" class="form-control" maxlength="100" placeholder="Add another ocular symptom"><div class="input-group-append"><button type="button" @click="add()" class="btn btn-outline-primary"><i class="fas fa-plus mr-1"></i>Add</button></div></div>
                                            <small class="text-muted">Select symptoms, then optionally record severity and eye. Changes save with the consultation.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Examination --}}
                        <div class="row consultation-exam-management">
                        <div class="col-lg-6">
                        <div id="consultation-section-examination" class="card border mb-3 consultation-section {{ $consultationFieldsLocked ? 'consultation-section-locked' : '' }}" x-data="{
                            exam: $wire.entangle('state'), normals:{lids:'Normal',conjunctiva:'White and quiet',cornea:'Clear',iris:'Normal pattern',pupil:'Round, regular and reactive',ac:'Deep and quiet',lens:'Clear',vitreous:'Clear',fundus:'Normal'},
                            suffixes(eye){return eye==='od'?['OD']:(eye==='os'?['OS']:['OD','OS'])}, fill(eye){this.suffixes(eye).forEach(s=>Object.entries(this.normals).forEach(([k,v])=>{const key=k+s;if(!String(this.exam[key]||'').trim())this.exam[key]=v}))}, clear(eye){this.suffixes(eye).forEach(s=>Object.keys(this.normals).forEach(k=>this.exam[k+s]=''))},
                            findingClass(key){const value=String(this.exam[key]??'').trim();if(!value)return '';const base=key.replace(/OD$|OS$/,'');return this.normals[base]&&value.toLowerCase()===this.normals[base].toLowerCase()?'exam-field--normal':'exam-field--recorded'}
                        }">
                            <div class="card-header bg-light d-flex align-items-center justify-content-between flex-wrap">
                                <h6 class="mb-0 font-weight-bold">
                                    Examination
                                    @if($consultationFieldsLocked)
                                        <span class="badge badge-secondary ml-2"><i class="fas fa-lock"></i> Locked</span>
                                    @endif
                                </h6>
                                @unless($consultationFieldsLocked)
                                    <div class="d-flex flex-wrap mt-1 mt-sm-0" style="gap:.5rem;">
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Fill normal examination findings">
                                            <button type="button" class="btn exam-action exam-action--ou"
                                                @click="fill('both')" title="Fill empty normal findings for both eyes">
                                                <i class="fas fa-check-double mr-1"></i> Fill Normal
                                            </button>
                                            <button type="button" class="btn exam-action exam-action--od"
                                                @click="fill('od')" title="Fill empty normal findings for the right eye">OD</button>
                                            <button type="button" class="btn exam-action exam-action--os"
                                                @click="fill('os')" title="Fill empty normal findings for the left eye">OS</button>
                                        </div>
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Clear examination findings">
                                            <button type="button" class="btn btn-outline-danger"
                                                @click="appConfirm('Clear examination findings for both eyes?').then(ok => ok && clear('both'))" title="Clear descriptive findings for both eyes">
                                                <i class="fas fa-eraser mr-1"></i> Clear
                                            </button>
                                            <button type="button" class="btn btn-outline-danger"
                                                @click="appConfirm('Clear OD examination findings?').then(ok => ok && clear('od'))" title="Clear descriptive findings for the right eye">OD</button>
                                            <button type="button" class="btn btn-outline-danger"
                                                @click="appConfirm('Clear OS examination findings?').then(ok => ok && clear('os'))" title="Clear descriptive findings for the left eye">OS</button>
                                        </div>
                                    </div>
                                @endunless
                            </div>
                            <div class="card-body">
                                <div class="examination-legend"><span><i class="exam-legend-dot exam-legend-dot--od"></i> OD — Right</span><span><i class="exam-legend-dot exam-legend-dot--os"></i> OS — Left</span><span><i class="exam-legend-dot exam-legend-dot--normal"></i> Normal preset</span><span><i class="exam-legend-dot exam-legend-dot--attention"></i> Review finding</span><span><i class="exam-legend-dot exam-legend-dot--urgent"></i> Validation/urgent</span></div>
                                <div class="row mb-2 font-weight-bold examination-eye-headings">
                                    <div class="col-md-2"></div>
                                    <div class="col-md-5 text-center examination-eye-heading examination-eye-heading--od">● OD — Right Eye</div>
                                    <div class="col-md-5 text-center examination-eye-heading examination-eye-heading--os">● OS — Left Eye</div>
                                </div>

                                @php
                                    $examMap = [
                                        'vaOD6m' => 'vaOS6m',
                                        'IOPOD' => 'IOPOS',
                                        'lidsOD' => 'lidsOS',
                                        'conjunctivaOD' => 'conjunctivaOS',
                                        'corneaOD' => 'corneaOS',
                                        'irisOD' => 'irisOS',
                                        'pupilOD' => 'pupilOS',
                                        'acOD' => 'acOS',
                                        'lensOD' => 'lensOS',
                                        'vitreousOD' => 'vitreousOS',
                                        'fundusOD' => 'fundusOS',
                                        'cdrOD' => 'cdrOS'
                                    ];
                                    $examLabels = [
                                        'vaOD6m' => 'V/A (6m)',
                                        'lidsOD' => 'Lids',
                                        'conjunctivaOD' => 'Conjunctiva',
                                        'corneaOD' => 'Cornea',
                                        'irisOD' => 'Iris',
                                        'pupilOD' => 'Pupil',
                                        'acOD' => 'Anterior chamber',
                                        'lensOD' => 'Lens',
                                        'vitreousOD' => 'Vitreous',
                                        'fundusOD' => 'Fundus',
                                        'cdrOD' => 'C.D.R',
                                        'IOPOD' => 'IOP'
                                    ];
                                    $examGroups = ['vaOD6m' => ['Vision & Pressure','Visual acuity and intraocular pressure'], 'lidsOD' => ['Anterior Segment','External eye and anterior chamber'], 'vitreousOD' => ['Posterior Segment','Vitreous, fundus and optic disc']];
                                @endphp
                                @foreach($examMap as $odKey => $osKey)
                                    @if(isset($examGroups[$odKey]))<div class="examination-group-heading"><strong>{{ $examGroups[$odKey][0] }}</strong><span>{{ $examGroups[$odKey][1] }}</span></div>@endif
                                    <div class="row mb-2">
                                        <div class="col-md-2 text-right d-flex align-items-center justify-content-end">
                                            <small class="font-weight-bold">{{ $examLabels[$odKey] }}</small>
                                        </div>

                                        {{-- Right Eye (OD) --}}
                                        <div class="col-md-5 examination-eye-cell examination-eye-cell--od">
                                            @if(Str::contains($odKey, 'IOP'))
                                                <input type="number" step="0.1" min="0" max="80"
                                                    id="consultation-iop-od"
                                                    x-model="exam['{{ $odKey }}']" :class="findingClass('{{ $odKey }}')"
                                                    class="form-control form-control-sm {{ ($odKey === 'IOPOD' && $examinationHasErrors) ? 'is-invalid' : '' }}"
                                                    placeholder="mmHg"
                                                    {{ $consultationFieldsLocked ? 'disabled' : '' }}>
                                            @elseif(Str::startsWith($odKey, 'va'))
                                                <select x-model="exam['{{ $odKey }}']" :class="findingClass('{{ $odKey }}')"
                                                    class="form-control form-control-sm"
                                                    {{ $consultationFieldsLocked ? 'disabled' : '' }}>
                                                    <option value="">— select —</option>
                                                    @foreach(\App\Livewire\Doctor\PatientRecordsComponent::vaLogMarTable($vaNotation) as $label => $logmar)
                                                        <option value="{{ $label }}">{{ $label }}{{ $logmar !== null ? ' ('.($logmar >= 0 ? '+' : '').$logmar.')' : ' (NM)' }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" x-model="exam['{{ $odKey }}']" :class="findingClass('{{ $odKey }}')"
                                                    class="form-control form-control-sm"
                                                    {{ $consultationFieldsLocked ? 'disabled' : '' }}>
                                            @endif
                                        </div>

                                        {{-- Left Eye (OS) --}}
                                        <div class="col-md-5 examination-eye-cell examination-eye-cell--os">
                                            @if(Str::contains($osKey, 'IOP'))
                                                <input type="number" step="0.1" min="0" max="80"
                                                    id="consultation-iop-os"
                                                    x-model="exam['{{ $osKey }}']" :class="findingClass('{{ $osKey }}')"
                                                    class="form-control form-control-sm {{ ($osKey === 'IOPOS' && $examinationHasErrors) ? 'is-invalid' : '' }}"
                                                    placeholder="mmHg"
                                                    {{ $consultationFieldsLocked ? 'disabled' : '' }}>
                                            @elseif(Str::startsWith($osKey, 'va'))
                                                <select x-model="exam['{{ $osKey }}']" :class="findingClass('{{ $osKey }}')"
                                                    class="form-control form-control-sm"
                                                    {{ $consultationFieldsLocked ? 'disabled' : '' }}>
                                                    <option value="">— select —</option>
                                                    @foreach(\App\Livewire\Doctor\PatientRecordsComponent::vaLogMarTable($vaNotation) as $label => $logmar)
                                                        <option value="{{ $label }}">{{ $label }}{{ $logmar !== null ? ' ('.($logmar >= 0 ? '+' : '').$logmar.')' : ' (NM)' }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" x-model="exam['{{ $osKey }}']" :class="findingClass('{{ $osKey }}')"
                                                    class="form-control form-control-sm"
                                                    {{ $consultationFieldsLocked ? 'disabled' : '' }}>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        </div>
                        <div class="col-lg-6">
<div id="consultation-section-management" class="card border mb-3 consultation-section">
    <div class="card-header bg-light">
        <h6 class="mb-0 font-weight-bold">Diagnosis & Management</h6>
    </div>
    <div class="card-body">
        <div class="row">

            {{-- Diagnosis Search, Selected Tags & Clinical Notes --}}
            <div class="col-12">
                {{-- Diagnosis Section --}}
                <div id="consultation-diagnosis-picker" class="mb-3 {{ $managementHasErrors ? 'consultation-field-error' : '' }} {{ $consultationFieldsLocked ? 'consultation-section-locked rounded p-2' : '' }}" x-data="{selected:$wire.entangle('selectedDiagnoses'),open:true,add(id,name){if(!this.selected.some(d=>Number(d.id)===Number(id)))this.selected.push({id:id,name:name});this.open=false},remove(i){this.selected.splice(i,1)}}">
                    <label class="font-weight-bold">
                        Final Diagnoses <span class="text-danger">*</span>
                        @if($consultationFieldsLocked)
                            <span class="badge badge-secondary ml-2"><i class="fas fa-lock"></i> Locked</span>
                        @endif
                    </label>

                    <div class="input-group mb-2">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                        </div>
                        <input type="text" class="form-control" placeholder="Search for a diagnosis..."
                            wire:model.live.debounce.400ms="diagnosisSearch"
                            @input="open=true"
                            {{ $consultationFieldsLocked ? 'disabled' : '' }}>
                    </div>

                    {{-- Search Results Dropdown (Floating) --}}
                    @if(!empty($diagnosisSearch) && !$consultationFieldsLocked)
                        <div x-show="open" class="list-group position-absolute w-100 shadow-lg"
                            style="z-index: 1000; max-height: 200px; overflow-y: auto; left: 15px; width: calc(100% - 30px);">
                            @forelse($this->diagnosisResults as $diag)
                                <button type="button"
                                    @click="add({{ $diag->id }}, @js($diag->name))"
                                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                    {{ $consultationFieldsLocked ? 'disabled' : '' }}>
                                    {{ $diag->name }}
                                    <i class="fas fa-plus-circle text-success"></i>
                                </button>
                            @empty
                                <div class="list-group-item text-muted">No diagnosis found.</div>
                            @endforelse
                        </div>
                    @endif

                    {{-- Selected Diagnoses Tags Area --}}
                    <div class="mt-2 d-flex flex-wrap" style="gap: 5px;">
                        <template x-for="(diag,index) in selected" :key="diag.id"><span class="badge badge-info p-2 shadow-sm"><i class="fas fa-stethoscope mr-1"></i><span x-text="diag.name"></span><button type="button" @click="remove(index)" class="btn btn-xs text-white ml-2 p-0" style="line-height:1" {{ $consultationFieldsLocked ? 'disabled' : '' }}><i class="fas fa-times-circle"></i></button></span></template>
                    </div>
                    @if($managementHasErrors)<small class="consultation-inline-error"><i class="fas fa-exclamation-circle mr-1"></i>{{ $errors->first('diagnoses') ?: $errors->first('selectedDiagnoses') }}</small>@endif
                </div>

                {{-- Clinical Notes - Under Diagnosis --}}
                <div class="form-group mb-0 clinical-notes-active">
                    <label class="font-weight-bold">
                        Clinical Notes
                        @if($consultationFieldsLocked)
                            <span class="badge badge-info ml-2"><i class="fas fa-plus-circle"></i> Addendum</span>
                        @endif
                    </label>
                    @if($consultationFieldsLocked)
                        @if($consultation && $consultation->addenda->count() > 0)
                            <div class="clinical-addenda-list mb-3">
                                @foreach($this->groupedClinicalAddenda as $addendumGroup)
                                    <div class="clinical-addendum-item">
                                        <div class="clinical-addendum-item__meta">
                                            <span><i class="fas fa-user-md"></i> {{ $addendumGroup['user']->name ?? 'Unknown user' }}</span>
                                            <span>
                                                {{ $addendumGroup['started_at']->format('d M Y h:i A') }}
                                                @if($addendumGroup['started_at']->ne($addendumGroup['ended_at']))
                                                    &ndash; {{ $addendumGroup['ended_at']->format('d M Y h:i A') }}
                                                @endif
                                            </span>
                                        </div>
                                        <div class="clinical-addendum-group">
                                            @foreach($addendumGroup['addenda'] as $addendum)
                                                <div class="clinical-addendum-item__note">{{ $addendum->note }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <textarea wire:model="clinicalAddendum"
                            class="form-control clinical-notes-textarea @error('clinicalAddendum') is-invalid @enderror"
                            rows="5" placeholder="Add a signed addendum, management update, or follow-up note..."></textarea>
                        @error('clinicalAddendum') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-success">Stored separately with the author and timestamp; the original note cannot be overwritten.</small>
                    @else
                        <textarea wire:model="state.notes" class="form-control clinical-notes-textarea" rows="8"
                            placeholder="Enter additional clinical observations, management plans, or notes..."></textarea>
                    @endif
                </div>
            </div>

            {{-- Next Visit Date & Appointment Booking --}}
            <div class="col-12">
             

                {{-- ⭐ APPOINTMENT BOOKING SECTION - Right Column ⭐ --}}
                <div class="card border mb-3">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0 font-weight-bold">
                            <i class="fas fa-calendar-check text-primary"></i> Upcoming Appointment
                        </h6>
                    </div>
                    <div class="card-body py-3">
                        @if($upcomingAppointment)
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="font-weight-bold text-dark">{{ $upcomingAppointment->title }}</div>
                                    <div class="small text-muted">
                                        {{ $upcomingAppointment->scheduled_at->format('M d, Y') }} at {{ $upcomingAppointment->scheduled_at->format('h:i A') }}
                                    </div>
                                    @if($upcomingAppointment->notes)
                                        <div class="small text-muted mt-2">{{ $upcomingAppointment->notes }}</div>
                                    @endif
                                </div>
                                <span class="badge badge-info">{{ $upcomingAppointment->status }}</span>
                            </div>
                        @else
                            <div class="text-muted small">No upcoming appointment booked for this patient.</div>
                        @endif
                    </div>
                </div>

                {{-- Next routine eye exam: the recall SMS goes out ahead of it (when the clinic switches it on) --}}
                <div class="card border mb-3">
                    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 font-weight-bold"><i class="fas fa-calendar-alt text-primary"></i> Next Routine Eye Exam</h6>
                        @if($patient->next_exam_due_on)
                            <span class="badge badge-info">Due {{ $patient->next_exam_due_on->format('d M Y') }}</span>
                        @endif
                    </div>
                    <div class="card-body py-3">
                        <div class="d-flex flex-wrap align-items-center">
                            <input type="date" wire:model="nextExamDueOn" min="{{ today()->addDay()->toDateString() }}"
                                   class="form-control form-control-sm mr-2 mb-2 @error('nextExamDueOn') is-invalid @enderror" style="max-width:170px" aria-label="Next eye exam due">
                            <button type="button" wire:click="saveNextExamDue" class="btn btn-sm btn-primary mr-2 mb-2"><i class="fas fa-save"></i> Save</button>
                            <span class="small text-muted mr-2 mb-2">or in</span>
                            @foreach([6 => '6 months', 12 => '1 year', 24 => '2 years'] as $months => $label)
                                <button type="button" wire:click="setNextExamIn({{ $months }})" class="btn btn-sm btn-outline-secondary mr-1 mb-2">{{ $label }}</button>
                            @endforeach
                            @if($patient->next_exam_due_on)
                                <button type="button" wire:click="clearNextExamDue" class="btn btn-sm btn-link text-danger mb-2">Clear</button>
                            @endif
                        </div>
                        @error('nextExamDueOn') <div class="text-danger small">{{ $message }}</div> @enderror
                        <div class="small text-muted">
                            @if($patient->next_exam_due_on && $patient->clinical_recall_sent_for?->equalTo($patient->next_exam_due_on))
                                <i class="fas fa-check text-success"></i> Recall text sent for this date.
                            @else
                                The patient is texted before this date if the clinic has "Eye Exam Due" switched on in SMS templates, unless they are already booked.
                            @endif
                        </div>
                    </div>
                </div>

                <div class="border-top pt-3" x-data="{showAppointment:@js($showAppointmentSection)}">
                    <div class="mb-2">
                        <button type="button" @click="showAppointment=!showAppointment" :class="showAppointment?'btn-warning':'btn-outline-primary'" class="btn btn-sm btn-block font-weight-bold shadow-sm">
                            <i class="fas" :class="showAppointment?'fa-minus-circle':'fa-calendar-plus'"></i><span x-text="showAppointment?'Hide Appointment Form':'Schedule Follow-up Appointment'"></span>
                        </button>
                    </div>

                        <template x-if="showAppointment"><div class="border rounded p-3 bg-light" style="animation:slideDown .3s ease-out;">
                                @include('components.appointment-quick-followup')
                        </div></template>
                </div>
            </div>

        </div>
    </div>
</div>

                        <div id="consultation-section-review" class="card border mb-3 consultation-review consultation-section" x-data="{ state:$wire.entangle('state'), diagnoses:$wire.entangle('selectedDiagnoses'), filled(v){return String(v??'').trim().length>0}, get historyReady(){return this.filled(this.state.chiefComplaint)}, get examinationReady(){return ['vaOD6m','vaOS6m','lidsOD','lidsOS','corneaOD','corneaOS','IOPOD','IOPOS'].some(k=>this.filled(this.state[k]))}, get diagnosisReady(){return Array.isArray(this.diagnoses)&&this.diagnoses.length>0}, get ready(){return this.historyReady&&this.diagnosisReady} }">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center"><h6 class="mb-0 font-weight-bold"><i class="fas fa-clipboard-check text-primary mr-1"></i> Clinical Review</h6><span class="badge" :class="ready?'badge-success':'badge-warning'" x-text="ready?'Ready to save':'Needs attention'"></span></div>
                            <div class="card-body py-3"><div class="consultation-review-grid">
                                <a href="#consultation-section-history" :class="historyReady?'is-complete':'is-missing'"><i class="fas" :class="historyReady?'fa-check-circle':'fa-exclamation-circle'"></i><span><strong>History</strong><small x-text="historyReady?'Chief complaint recorded':'Chief complaint required'"></small></span></a>
                                <a href="#consultation-section-examination" :class="examinationReady?'is-complete':'is-optional'"><i class="fas" :class="examinationReady?'fa-check-circle':'fa-info-circle'"></i><span><strong>Examination</strong><small x-text="examinationReady?'Findings recorded':'No findings recorded'"></small></span></a>
                                <a href="#consultation-section-management" :class="diagnosisReady?'is-complete':'is-missing'"><i class="fas" :class="diagnosisReady?'fa-check-circle':'fa-exclamation-circle'"></i><span><strong>Diagnosis & Plan</strong><small x-text="diagnosisReady?diagnoses.length+' selected':'Diagnosis required'"></small></span></a>
                            </div></div>
                            <div class="consultation-review-alerts" x-show="{{ !empty($urgentReferralReasons) ? 'true' : 'false' }}">
                                @if(!empty($urgentReferralReasons))<a href="#consultation-section-history" class="is-urgent"><i class="fas fa-ambulance"></i> Urgent: {{ implode(', ', $urgentReferralReasons) }}</a>@endif
                            </div>
                            @if($consultationErrorCount)
                                <div class="consultation-review-errors" role="alert">
                                    <strong><i class="fas fa-exclamation-circle"></i> Required corrections</strong>
                                    @if($historyHasErrors)<a href="#consultation-chief-complaint">Chief complaint is required <span>Go to History <i class="fas fa-arrow-right"></i></span></a>@endif
                                    @if($examinationHasErrors)<a href="#consultation-section-examination">Check the recorded eye pressure <span>Go to Examination <i class="fas fa-arrow-right"></i></span></a>@endif
                                    @if($managementHasErrors)<a href="#consultation-diagnosis-picker">Select at least one diagnosis <span>Go to Diagnosis <i class="fas fa-arrow-right"></i></span></a>@endif
                                </div>
                            @endif
                        </div>

                        </div>
                        </div>

                        @if($consultationErrorCount)
                            <aside class="consultation-error-toast" role="alert" aria-live="assertive" x-data="{open:true}" x-show="open" x-cloak x-init="setTimeout(()=>window.focusFirstConsultationError?.(),150)">
                                <div class="consultation-error-toast__icon"><i class="fas fa-exclamation-triangle"></i></div>
                                <div><strong>Consultation not saved</strong><span>{{ $consultationErrorCount }} {{ Str::plural('item', $consultationErrorCount) }} need attention.</span><button type="button" @click="window.focusFirstConsultationError()">Review errors</button></div>
                                <button type="button" class="consultation-error-toast__close" @click="open=false" aria-label="Dismiss validation notification"><i class="fas fa-times"></i></button>
                            </aside>
                        @endif
                        <div class="consultation-actions text-right">
                            <button type="button" onclick="if (window.consultationDirty) { event.stopImmediatePropagation(); appConfirm('Discard unsaved consultation changes?').then(ok => ok &amp;&amp; Livewire.find(this.closest('[wire\\:id]').getAttribute('wire:id')).cancelAndGoBack()) }" wire:click="cancelAndGoBack" class="btn btn-secondary px-4">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn {{ $consultationFieldsLocked ? 'btn-success' : 'btn-primary' }} px-5" wire:loading.attr="disabled">
                                <span wire:loading.remove
                                    wire:target="{{ $isEditMode ? 'updateConsultation' : 'createConsultation' }}">
                                    <i class="fas fa-save"></i>
                                    {{ $consultationFieldsLocked ? 'Add Clinical Addendum' : ($isEditMode ? 'Update' : 'Save') . ' Consultation' }}
                                </span>
                                <span wire:loading
                                    wire:target="{{ $isEditMode ? 'updateConsultation' : 'createConsultation' }}">
                                    <i class="fas fa-spinner fa-spin"></i> Saving...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            @endif


   {{-- TAB 3: PRESCRIPTION WITH EYE LATERALITY --}}
@if($activeTab === 'prescription')
    <div class="tab-content-wrapper">
        {{-- Compact Header --}}
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h6 class="mb-0 text-primary font-weight-bold">
                    <i class="fas fa-prescription"></i> Prescription Management
                </h6>
                @if($consultationID)
                    <small class="text-success" style="font-size: 11px;">
                        <i class="fas fa-check-circle"></i> Linked to Consultation #{{ $consultationID }}
                    </small>
                @else
                    <small class="text-muted" style="font-size: 11px;">
                        <i class="fas fa-info-circle"></i> Create or select a consultation to link prescription
                    </small>
                @endif
            </div>
            @if(count($productsList) > 0 && $consultationID)
                <button wire:click="clearPrescription" wire:confirm="Clear all prescription items?"
                    class="btn btn-xs btn-outline-danger">
                    <i class="fas fa-trash"></i> Clear All
                </button>
            @endif
        </div>

        {{-- 2-COLUMN LAYOUT: Compact Search Left, List Right --}}
        <div class="row">
            {{-- LEFT COLUMN: Compact Search Bar (25%) --}}
            <div class="col-md-3">
                <div class="card border shadow-xs sticky-top" style="top: 10px;">
                    <div class="card-header bg-primary text-white py-1 px-3">
                        <h6 class="mb-0 font-weight-bold" style="font-size: 13px;">
                            <i class="fas fa-search"></i> Quick Add Product
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        {{-- Product Search --}}
                        <div class="position-relative mb-2">
                            <input type="text" wire:model.live.debounce.400ms="productSearch"
                                class="form-control form-control-sm" placeholder="Type product name..."
                                autocomplete="off">
                            <i class="fas fa-search position-absolute"
                                style="right: 10px; top: 8px; color: #999; font-size: 12px;"></i>

                            {{-- Search Results --}}
                            @if($productSearch && strlen($productSearch) >= 2)
                                <div class="search-results-dropdown">
                                    @if($searchResults->count() > 0)
                                        <ul class="list-group shadow-sm">
                                            @foreach($searchResults as $product)
                                                <li class="list-group-item list-group-item-action py-1 px-2"
                                                    wire:click="selectProduct({{ $product->id }})" style="cursor: pointer;">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div class="flex-grow-1">
                                                            <strong class="d-block" style="font-size: 12px;">{{ $product->name }}</strong>
                                                            <small class="text-muted" style="font-size: 10px;">
                                                                <i class="fas fa-warehouse"></i> Stock:
                                                                <strong>{{ $product->made_to_order ? 'Made to order' : $product->quantity }}</strong>
                                                            </small>
                                                        </div>
                                                        <span class="badge badge-primary badge-pill px-2 py-1" style="font-size: 10px;">
                                                            {{ currency() }} {{ number_format($product->selling_price, 2) }}
                                                        </span>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <div class="search-no-results">
                                            <p class="text-muted mb-0 text-center py-2" style="font-size: 12px;">
                                                <i class="fas fa-search"></i> No products found
                                            </p>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Compact Hint --}}
                        <small class="text-muted d-block text-center" style="font-size: 10px;">
                            <i class="fas fa-lightbulb text-warning"></i> Search &amp; click to add product
                        </small>
                    </div>
                </div>
            </div>

            {{-- RIGHT COLUMN: Products List with Eye & Frequency (75%) --}}
            <div class="col-md-9">
                <div class="card border shadow-sm">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap">
                        <h6 class="mb-0 font-weight-bold">
                            <i class="fas fa-list-alt"></i> Prescription Items
                            <span class="badge badge-primary ml-2">{{ count($productsList) }}</span>
                        </h6>

                        @if(count($productsList) > 0)
                            <div>
                                @php
                                    $refundedCount  = collect($productsList)->filter(fn($i) => ($i['status'] ?? null) === 'refunded')->count();
                                    $dispensedCount = collect($productsList)->where('is_dispensed', true)->filter(fn($i) => ($i['status'] ?? null) !== 'refunded')->count();
                                    $heldCount = collect($productsList)->where('purchased', true)->where('is_dispensed', false)->filter(fn($i) => ($i['status'] ?? null) !== 'refunded')->count();
                                    $pendingCount = count($productsList) - $dispensedCount - $heldCount - $refundedCount;
                                    $missingData = collect($productsList)->where('is_dispensed', false)->where('purchased', false)->filter(function($item) {
                                        $categoryName = strtolower(trim($item['category_name'] ?? ''));
                                        $isDrug = ($item['is_drug'] ?? false) || in_array($categoryName, ['drug', 'drugs'], true);
                                        $durationValid = ($item['duration_unit'] ?? null) === 'until_finished'
                                            || (!empty($item['duration_value']) && in_array($item['duration_unit'] ?? null, ['days', 'weeks', 'months'], true));
                                        return $isDrug && (empty($item['frequency']) || empty($item['eye']) || !$durationValid);
                                    })->count();
                                @endphp

                                <span class="badge badge-success px-2 py-1">
                                    <i class="fas fa-check"></i> {{ $dispensedCount }} Dispensed
                                </span>
                                <span class="badge badge-warning px-2 py-1 ml-1">
                                    <i class="fas fa-clock"></i> {{ $pendingCount }} Pending
                                </span>
                                @if($heldCount > 0)
                                    <span class="badge badge-info px-2 py-1 ml-1">
                                        <i class="fas fa-pause-circle"></i> {{ $heldCount }} On Hold
                                    </span>
                                @endif
                                @if($refundedCount > 0)
                                    <span class="badge badge-danger px-2 py-1 ml-1">
                                        <i class="fas fa-undo"></i> {{ $refundedCount }} Refunded
                                    </span>
                                @endif
                                @if($missingData > 0)
                                    <span class="badge badge-danger px-2 py-1 ml-1">
                                        <i class="fas fa-exclamation-triangle"></i> {{ $missingData }} Incomplete
                                    </span>
                                @endif

                                {{-- Refresh button --}}
                                <button wire:click="refreshPrescriptionStatus"
                                    class="btn btn-sm btn-outline-info ml-1" title="Refresh status"
                                    wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="refreshPrescriptionStatus">
                                        <i class="fas fa-sync-alt"></i>
                                    </span>
                                    <span wire:loading wire:target="refreshPrescriptionStatus">
                                        <i class="fas fa-spinner fa-spin"></i>
                                    </span>
                                </button>
                            </div>
                        @endif
                    </div>
                          <div class="card-body p-0">
                        @if(count($productsList) > 0)
                            <div class="table-responsive" style="max-height: 260px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0" style="font-size: 12px;">
                                    <thead class="thead-light sticky-top" style="top: 0; z-index: 10;">
                                        <tr>
                                            <th width="3%" class="py-1">#</th>
                                            <th width="26%" class="py-1">Product</th>
                                            <th width="6%" class="py-1">Qty</th>
                                            <th width="14%" class="py-1">Eye <small class="text-muted">(Drugs)</small></th>
                                            <th width="17%" class="py-1">Frequency <small class="text-muted">(Drugs)</small></th>
                                            <th width="15%" class="py-1">Duration <small class="text-muted">(Drugs)</small></th>
                                            <th width="10%" class="py-1">Price</th>
                                            <th width="16%" class="py-1">Total</th>
                                            <th width="5%" class="py-1 text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($productsList as $index => $item)
                                            @php
                                                $isDispensed = $item['is_dispensed'] ?? false;
                                                $isPurchased = $item['purchased'] ?? false;
                                                $isRefunded  = $item['is_refunded'] ?? false;
                                                $isLocked    = $isDispensed || $isPurchased || $isRefunded;
                                                $isEditingFreq = $editingFrequencyIndex === $index;
                                                $categoryName = strtolower(trim($item['category_name'] ?? ''));
                                                $isDrug = ($item['is_drug'] ?? false) || in_array($categoryName, ['drug', 'drugs'], true);
                                                $billableLineTotal = $isLocked ? 0 : (float) ($item['total'] ?? 0);
                                            @endphp
                                            <tr class="prescription-row {{ $isLocked ? 'prescription-row--dispensed table-secondary' : '' }}">
                                                <td class="text-center py-1 px-1 align-middle">{{ $index + 1 }}</td>
                                                
                                                {{-- PRODUCT NAME --}}
                                                <td class="py-1 px-2 align-middle">
                                                    <strong class="{{ $isLocked ? 'text-muted' : '' }} d-block" style="font-size: 12px; line-height: 1.1;">{{ $item['name'] }}</strong>
                                                    @if($item['category_name'] ?? false)
                                                        <span class="badge badge-light border" style="font-size: 9px; padding: 1px 4px;">{{ $item['category_name'] }}</span>
                                                    @endif
                                                </td>
                                                
                                                {{-- QUANTITY --}}
                                                <td class="py-1 px-1 align-middle">
                                                    @if(!$isLocked)
                                                        <input type="number"
                                                            wire:model.blur="productsList.{{ $index }}.quantity"
                                                            wire:change="updateProductQuantity({{ $index }}, $event.target.value)"
                                                            class="form-control form-control-sm text-center py-0 px-1" min="1"
                                                            style="width: 44px; height: 24px; font-size: 11px;">
                                                    @else
                                                        <span class="badge badge-secondary px-1 py-0.5" style="font-size: 10px;">
                                                            {{ $item['quantity'] }}
                                                        </span>
                                                    @endif
                                                </td>
                                                
                                                {{-- ⭐ EYE LATERALITY COLUMN --}}
                                                <td class="py-1 px-1 align-middle">
                                                    @if(!$isDrug)
                                                        <small class="text-muted" style="font-size: 10px;">Not applicable</small>
                                                    @elseif(!$isLocked)
                                                        <select class="form-control form-control-sm py-0 px-1" style="height: 24px; font-size: 11px;"
                                                                wire:change="updateEye({{ $index }}, $event.target.value)">
                                                            <option value="">-- Eye --</option>
                                                            @foreach(\App\Enums\EyeLaterality::cases() as $eye)
                                                                <option value="{{ $eye->value }}" {{ ($item['eye'] ?? '') === $eye->value ? 'selected' : '' }}>
                                                                    {{ $eye->label() }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        @if($item['eye'] ?? false)
                                                            @php
                                                                $eyeEnum = \App\Enums\EyeLaterality::tryFrom($item['eye']);
                                                            @endphp
                                                            @if($eyeEnum)
                                                                <span class="badge {{ $eyeEnum->badgeClass() }} px-1.5 py-0.5" style="font-size: 10px;">
                                                                    {{ $eyeEnum->abbreviation() }}
                                                                </span>
                                                            @else
                                                                <small class="text-muted" style="font-size: 10px;">{{ $item['eye'] }}</small>
                                                            @endif
                                                        @else
                                                            @if(!$isLocked)
                                                                <button type="button" 
                                                                        wire:click="editFrequency({{ $index }})"
                                                                        class="btn btn-xs btn-warning py-0 px-1" style="font-size: 10px;">
                                                                    <i class="fas fa-eye"></i> Set
                                                                </button>
                                                            @else
                                                                <small class="text-muted fst-italic" style="font-size: 10px;">N/A</small>
                                                            @endif
                                                        @endif
                                                    @endif
                                                </td>
                                                
                                                {{-- ⭐ FREQUENCY COLUMN --}}
                                                <td class="py-1 px-1 align-middle">
                                                    @if(!$isDrug)
                                                        <small class="text-muted" style="font-size: 10px;">Not applicable</small>
                                                    @elseif(!$isLocked)
                                                        <div class="d-flex align-items-center">
                                                            <select class="form-control form-control-sm mr-1 py-0 px-1" style="height: 24px; font-size: 11px;"
                                                                    wire:change="updateFrequency({{ $index }}, $event.target.value)">
                                                                <option value="">-- Select --</option>
                                                                @foreach(\App\Enums\ProductFrequency::options() as $value => $label)
                                                                    <option value="{{ $value }}" {{ ($item['frequency'] ?? '') === $value ? 'selected' : '' }}>
                                                                        {{ $label }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                            <button type="button" 
                                                                    wire:click="cancelFrequencyEdit"
                                                                    class="btn btn-xs btn-outline-secondary py-0 px-1">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </div>
                                                    @else
                                                        @if($item['frequency'] ?? false)
                                                            @php
                                                                $freqEnum = \App\Enums\ProductFrequency::tryFrom($item['frequency']);
                                                            @endphp
                                                            @if($freqEnum)
                                                                <span class="badge {{ $freqEnum->badgeClass() }} px-1.5 py-0.5" style="font-size: 10px;">
                                                                    {{ $freqEnum->abbreviation() }}
                                                                </span>
                                                            @else
                                                                <small class="text-muted" style="font-size: 10px;">{{ $item['frequency'] }}</small>
                                                            @endif
                                                        @else
                                                            @if(!$isLocked)
                                                                <button type="button" 
                                                                        wire:click="editFrequency({{ $index }})"
                                                                        class="btn btn-xs btn-warning py-0 px-1" style="font-size: 10px;">
                                                                    <i class="fas fa-plus-circle"></i> Set
                                                                </button>
                                                            @else
                                                                <small class="text-muted fst-italic" style="font-size: 10px;">Not set</small>
                                                            @endif
                                                        @endif
                                                    @endif
                                                </td>

                                                {{-- TREATMENT DURATION --}}
                                                <td class="py-1 px-1 align-middle">
                                                    @if(!$isDrug)
                                                        <small class="text-muted" style="font-size: 10px;">Not applicable</small>
                                                    @elseif($isLocked)
                                                        <small class="text-muted" style="font-size: 10px;">
                                                            {{ ($item['duration_unit'] ?? null) === 'until_finished'
                                                                ? 'Until finished'
                                                                : (($item['duration_value'] ?? null) ? $item['duration_value'].' '.($item['duration_unit'] ?? 'days') : 'Not set') }}
                                                        </small>
                                                    @else
                                                        <div class="d-flex align-items-center" style="gap: 3px;">
                                                            @if(($item['duration_unit'] ?? 'days') !== 'until_finished')
                                                                <input type="number" min="1"
                                                                    wire:model.blur="productsList.{{ $index }}.duration_value"
                                                                    class="form-control form-control-sm text-center px-1"
                                                                    style="width: 45px; height: 24px; font-size: 11px;">
                                                            @endif
                                                            <select wire:model.live="productsList.{{ $index }}.duration_unit"
                                                                class="form-control form-control-sm px-1"
                                                                style="height: 24px; font-size: 10px; min-width: 76px;">
                                                                <option value="days">Days</option>
                                                                <option value="weeks">Weeks</option>
                                                                <option value="months">Months</option>
                                                                <option value="until_finished">Until finished</option>
                                                            </select>
                                                        </div>
                                                    @endif
                                                </td>
                                                
                                                <td class="py-1 px-1 align-middle"><small class="font-weight-bold" style="font-size: 11px;">{{ currency() }} {{ number_format($item['price'], 2) }}</small></td>
                                                <td class="py-1 px-1 align-middle">
                                                    @if($isLocked)
                                                        @if($isRefunded)
                                                            <span class="badge badge-danger px-1.5 py-0.5" style="font-size: 9px;"><i class="fas fa-undo"></i> Refunded</span>
                                                        @elseif($isDispensed)
                                                            <span class="badge badge-success px-1.5 py-0.5" style="font-size: 9px;"><i class="fas fa-check-circle"></i> Paid / Dispensed</span>
                                                        @elseif($isPurchased)
                                                            <span class="badge badge-info px-1.5 py-0.5" style="font-size: 9px;"><i class="fas fa-pause-circle"></i> On Hold</span>
                                                        @endif
                                                        <small class="d-block text-muted" style="font-size: 9px;">({{ currency() }} {{ number_format($item['total'] ?? 0, 2) }})</small>
                                                    @else
                                                        <strong class="text-primary font-weight-bold" style="font-size: 12px;">{{ currency() }} {{ number_format($billableLineTotal, 2) }}</strong>
                                                    @endif
                                                </td>
                                                
                                                {{-- ACTION --}}
                                                <td class="text-center py-1 px-1 align-middle">
                                                    @if(!$isLocked)
                                                        <button wire:click="removeProduct({{ $index }})"
                                                            class="btn btn-xs btn-outline-danger py-0 px-1"
                                                            wire:confirm="Remove this product?" title="Remove item">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    @else
                                                        <i class="fas fa-lock text-muted" style="font-size: 11px;" title="{{ $isRefunded ? 'Refunded' : ($isDispensed ? 'Dispensed' : 'On hold') }}"></i>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="bg-light sticky-bottom" style="bottom: 0;">
                                        <tr>
                                            <th colspan="7" class="text-right py-1 align-middle" style="font-size: 11px;">New Prescription Total:</th>
                                            <th colspan="2" class="py-1 align-middle">
                                                <strong class="text-success font-weight-bold" style="font-size: 13px;">
                                                    {{ currency() }} {{ number_format($this->calculateTotal(), 2) }}
                                                </strong>
                                            </th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-3">
                                <i class="fas fa-prescription-bottle fa-2x text-muted mb-2"></i>
                                <h6 class="text-muted mb-1" style="font-size: 13px;">No prescription items yet</h6>
                                <p class="text-muted mb-0" style="font-size: 11px;">Search for products on the left and click to add</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Compact Action Buttons --}}
                @if(count($productsList) > 0)
                    <div class="mt-2 d-flex justify-content-between align-items-center bg-white p-2 border rounded shadow-xs">
                        <button wire:click="clearPrescription" wire:confirm="Clear all prescription items?"
                            class="btn btn-xs btn-outline-danger font-weight-bold">
                            <i class="fas fa-trash mr-1"></i> Clear Unsaved Items
                        </button>
                        <button wire:click="savePrescription" class="btn btn-sm btn-success px-4 font-weight-bold shadow-xs"
                            wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="savePrescription">
                                <i class="fas fa-paper-plane mr-1"></i> Save &amp; Send to Pharmacy / Cashier
                            </span>
                            <span wire:loading wire:target="savePrescription">
                                <i class="fas fa-spinner fa-spin mr-1"></i> Saving...
                            </span>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif


            {{-- TAB 4: REFRACTION --}}
            @if($activeTab === 'refraction')
                <div class="tab-content-wrapper">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="mb-0 text-primary font-weight-bold">
                            <i class="fas fa-glasses"></i> Refraction Details
                        </h5>
                        <button wire:click="cancelAndGoBack" class="btn btn-light">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                    </div>

                    @if(!$consultationID)
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Tip:</strong> Select a consultation from history to load or save refraction data.
                        </div>
                    @endif

                    <form wire:submit="saveRefraction" novalidate>
                        @include('livewire.doctor.partials.structured-refraction-fields')

                        @if($errors->any())
                            <div class="alert alert-danger" role="alert">
                                <strong>Refraction was not saved. Please correct:</strong>
                                <ul class="mb-0">
                                    @foreach($errors->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <div class="text-right">
                            @if($consultationID)
                                <button type="button" wire:click="resetRefractionChanges"
                                    class="btn btn-outline-secondary px-4"
                                    wire:confirm="Discard unsaved refraction changes and reload the saved values?">
                                    <i class="fas fa-undo"></i> Reset Changes
                                </button>
                            @endif
                            <button type="button" wire:click="cancelAndGoBack" class="btn btn-secondary px-4">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            @if($consultationID)
                                <button type="submit" class="btn btn-primary px-5" wire:loading.attr="disabled" wire:target="saveRefraction">
                                    <span wire:loading.remove wire:target="saveRefraction"><i class="fas fa-save"></i> Save Refraction</span>
                                    <span wire:loading wire:target="saveRefraction"><i class="fas fa-spinner fa-spin"></i> Saving...</span>
                                </button>
                            @else
                                <button type="button" disabled class="btn btn-secondary px-5" title="Select a consultation first">
                                    <i class="fas fa-save"></i> Save Refraction
                                </button>
                            @endif
                        </div>
                    </form>
                </div>
            @endif

            {{-- TAB 5: BILLS --}}
            @if($activeTab === 'bills')
                <div class="tab-content-wrapper">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="mb-1 text-primary font-weight-bold">
                                <i class="fas fa-file-medical"></i> Clinical Documents
                            </h5>
                            <small class="text-muted">Upload and review fundus photos, OCT, visual fields, referral letters, and reports.</small>
                        </div>
                        <span class="badge badge-secondary px-3 py-2">{{ $patientDocumentCount }} files</span>
                    </div>

                    <div class="row">
                        <div class="col-lg-5 mb-3">
                            <div class="card border h-100">
                                <div class="card-header bg-light py-2">
                                    <h6 class="mb-0 font-weight-bold">
                                        <i class="fas fa-paperclip text-primary"></i> Attach Clinical Document 
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form wire:submit="uploadPatientDocument">
                                        <div class="form-row">
                                            <div class="col-md-6 mb-3">
                                                <label class="font-weight-bold small">Document Type</label>
                                                <select wire:model.live="documentType" class="form-control form-control-sm">
                                                    <option value="fundus_photo">Fundus Photo</option>
                                                    <option value="oct">OCT</option>
                                                    <option value="visual_field">Visual Field</option>
                                                    <option value="referral_letter">Referral Letter</option>
                                                    <option value="other">Other</option>
                                                </select>
                                                @error('documentType') <small class="text-danger">{{ $message }}</small> @enderror
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="font-weight-bold small">Visit</label>
                                                <select wire:model.live="documentConsultationId" class="form-control form-control-sm">
                                                    <option value="">General patient file</option>
                                                    @foreach($patientRecords as $record)
                                                        <option value="{{ $record->id }}">{{ $record->created_at->format('d M Y') }}</option>
                                                    @endforeach
                                                </select>
                                                @error('documentConsultationId') <small class="text-danger">{{ $message }}</small> @enderror
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="font-weight-bold small">Title</label>
                                            <input type="text" wire:model="documentTitle" class="form-control form-control-sm"
                                                placeholder="e.g. Left eye OCT macula">
                                            @error('documentTitle') <small class="text-danger">{{ $message }}</small> @enderror
                                        </div>

                                        <div class="mb-3">
                                            <label class="font-weight-bold small">File</label>
                                            <input type="file"
                                                wire:model.live="documentFiles"
                                                wire:key="patient-document-upload-{{ $documentUploadKey }}"
                                                class="form-control form-control-sm"
                                                accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
                                                multiple>
                                            <small class="text-muted">Select one or more JPG, PNG, PDF, DOC, DOCX files. Max 10MB each, 10 files per upload.</small>
                                            @error('documentFiles') <div><small class="text-danger">{{ $message }}</small></div> @enderror
                                            @error('documentFiles.*') <div><small class="text-danger">{{ $message }}</small></div> @enderror
                                        </div>

                                        <div class="mb-3">
                                            <label class="font-weight-bold small">Notes</label>
                                            <textarea wire:model="documentNotes" class="form-control form-control-sm" rows="2"></textarea>
                                            @error('documentNotes') <small class="text-danger">{{ $message }}</small> @enderror
                                        </div>

                                        <button type="submit" class="btn btn-primary btn-sm"
                                            wire:loading.attr="disabled" wire:target="uploadPatientDocument,documentFiles">
                                            <span wire:loading.remove wire:target="uploadPatientDocument,documentFiles">
                                                <i class="fas fa-upload"></i> Upload
                                            </span>
                                            <span wire:loading wire:target="uploadPatientDocument,documentFiles">
                                                <i class="fas fa-spinner fa-spin"></i> Uploading
                                            </span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-7 mb-3">
                            <div class="card border h-100">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                                    <h6 class="mb-0 font-weight-bold">
                                        <i class="fas fa-folder-open text-success"></i> Attached Images & Documents
                                    </h6>
                                    <span class="badge badge-secondary">{{ $patientDocumentCount }}</span>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Title</th>
                                                    <th>Visit</th>
                                                    <th>Uploaded</th>
                                                    <th class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse($patientDocuments as $document)
                                                    <tr>
                                                        <td><span class="badge badge-info">{{ ucwords(str_replace('_', ' ', $document->document_type)) }}</span></td>
                                                        <td>
                                                            <a href="{{ $document->url }}" target="_blank" class="font-weight-bold">
                                                                {{ $document->title }}
                                                            </a>
                                                            <small class="d-block text-muted">{{ $document->original_name }}</small>
                                                        </td>
                                                        <td>
                                                            {{ optional($document->consultation)->created_at ? $document->consultation->created_at->format('d M Y') : 'General' }}
                                                        </td>
                                                        <td>
                                                            <small>{{ $document->created_at->format('d M Y') }}</small>
                                                            <small class="d-block text-muted">{{ $document->uploadedBy->name ?? 'System' }}</small>
                                                        </td>
                                                        <td class="text-center">
                                                            <a href="{{ $document->url }}" target="_blank" class="btn btn-xs btn-outline-primary">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            @if(auth()->user()->hasRole('Super Admin'))
                                                                <button wire:click="deletePatientDocument({{ $document->id }})"
                                                                    wire:confirm="Delete this document?"
                                                                    class="btn btn-xs btn-outline-danger">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            @else
                                                                <button type="button" class="btn btn-xs btn-outline-secondary" disabled
                                                                    title="Only Super Admin can delete uploads">
                                                                    <i class="fas fa-lock"></i>
                                                                </button>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-4">
                                                            No attached documents yet.
                                                        </td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                @if($patientDocuments->hasPages())
                                    <div class="card-footer bg-white py-2">{{ $patientDocuments->links() }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>


{{-- Modern EMR Design System --}}

</div>
