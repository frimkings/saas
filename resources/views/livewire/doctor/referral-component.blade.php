<div class="referral-wrap" style="background:#f0f2f5; min-height:100vh;">
<div class="p-4">

    {{-- ── PAGE HEADER ── --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="font-weight-bold mb-0"><i class="fas fa-file-medical mr-2 text-primary"></i>Clinical Letters</h4>
            <p class="text-muted small mb-0">Referrals, Medical Reports &amp; Excuse Duty Letters</p>
        </div>
        <div class="d-flex" style="gap:8px;">
            <button wire:click="openCreate('referral')" class="btn btn-primary btn-sm">
                <i class="fas fa-paper-plane mr-1"></i>Referral
            </button>
            <button wire:click="openCreate('medical_report')" class="btn btn-success btn-sm">
                <i class="fas fa-file-medical-alt mr-1"></i>Medical Report
            </button>
            <button wire:click="openCreate('excuse_duty')" class="btn btn-warning btn-sm">
                <i class="fas fa-calendar-times mr-1"></i>Excuse Duty
            </button>
        </div>
    </div>

    {{-- ── FILTER BAR ── --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="row align-items-center">
                <div class="col-md-3 mb-2 mb-md-0">
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
                        </div>
                        <input type="text" wire:model.live.debounce.400ms="searchQuery"
                            class="form-control border-left-0"
                            placeholder="Patient, referral to, diagnosis…">
                    </div>
                </div>
                <div class="col-md-2 mb-2 mb-md-0">
                    <select wire:model.live="typeFilter" class="form-control form-control-sm">
                        <option value="">All Types</option>
                        <option value="referral">Referral</option>
                        <option value="medical_report">Medical Report</option>
                        <option value="excuse_duty">Excuse Duty</option>
                    </select>
                </div>
                <div class="col-md-2 mb-2 mb-md-0">
                    <select wire:model.live="statusFilter" class="form-control form-control-sm">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-4 mb-2 mb-md-0"><x-date-range from="fromDate" to="toDate" presets="activity" /></div>
                <div class="col-md-1">
                    <select wire:model.live="perPage" class="form-control form-control-sm">
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
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 font-weight-bold">Letter Records</h6>
            <span class="badge badge-secondary">{{ $referrals->total() }} total</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 referral-table">
                    <thead class="thead-light">
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
                            <td class="text-nowrap">
                                <div class="font-weight-bold small">{{ $ref->referral_date->format('M d, Y') }}</div>
                                <small class="text-muted">{{ $ref->created_at->diffForHumans() }}</small>
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
                                <div class="font-weight-bold">{{ $ref->patient_name }}</div>
                                @if($ref->patient_age_sex)
                                    <small class="text-muted">{{ $ref->patient_age_sex }}</small>
                                @endif
                            </td>
                            <td>
                                @if($ref->letter_type === 'referral')
                                    <small class="text-primary">To: {{ Str::limit($ref->referral_to, 35) }}</small><br>
                                    <small class="text-muted">{{ Str::limit($ref->diagnosis_display, 35) }}</small>
                                @elseif($ref->letter_type === 'medical_report')
                                    <small class="text-muted">{{ Str::limit($ref->diagnosis_display, 50) ?: '—' }}</small>
                                @else
                                    <small class="text-muted">
                                        @if($ref->excuse_from_date && $ref->excuse_to_date)
                                            {{ $ref->excuse_from_date->format('M d') }} – {{ $ref->excuse_to_date->format('M d, Y') }}
                                        @else —
                                        @endif
                                    </small>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="dropdown">
                                    <button class="btn btn-sm status-badge status-badge--{{ $ref->status }} dropdown-toggle" data-toggle="dropdown">
                                        {{ ucfirst($ref->status) }}
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right shadow-sm border-0">
                                        <button class="dropdown-item small" wire:click="updateStatus({{ $ref->id }}, 'pending')"><span class="badge badge-warning mr-1">●</span>Pending</button>
                                        <button class="dropdown-item small" wire:click="updateStatus({{ $ref->id }}, 'completed')"><span class="badge badge-success mr-1">●</span>Completed</button>
                                        <button class="dropdown-item small" wire:click="updateStatus({{ $ref->id }}, 'cancelled')"><span class="badge badge-secondary mr-1">●</span>Cancelled</button>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <small class="text-muted">{{ $ref->referredBy->name ?? '—' }}</small>
                            </td>
                            <td class="text-right text-nowrap">
                                <a href="{{ route('doctor.referral.pdf', $ref->id) }}" target="_blank"
                                   class="btn btn-sm btn-outline-danger" title="Print Letter">
                                    <i class="fas fa-print"></i>
                                </a>
                                <button wire:click="openEdit({{ $ref->id }})" class="btn btn-sm btn-outline-primary ml-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                @if(auth()->user()->hasRole('Super Admin'))
                                <button wire:click="confirmDelete({{ $ref->id }})" class="btn btn-sm btn-outline-danger ml-1" title="Delete">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                @else
                                <button class="btn btn-sm btn-outline-secondary ml-1" disabled title="Only Super Admin can delete">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <i class="fas fa-file-medical fa-3x text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-3">No letters found.</p>
                                <div class="d-flex justify-content-center" style="gap:8px;">
                                    <button wire:click="openCreate('referral')" class="btn btn-sm btn-primary">
                                        <i class="fas fa-paper-plane mr-1"></i>New Referral
                                    </button>
                                    <button wire:click="openCreate('medical_report')" class="btn btn-sm btn-success">
                                        <i class="fas fa-file-medical-alt mr-1"></i>Medical Report
                                    </button>
                                    <button wire:click="openCreate('excuse_duty')" class="btn btn-sm btn-warning">
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
        <div class="card-footer bg-white border-0 py-3">{{ $referrals->links() }}</div>
        @endif
    </div>

</div>

{{-- ═══════════════════════ MODAL ═══════════════════════ --}}
@if($showModal)
<div class="modal-backdrop-custom" wire:click.self="closeModal">
    <div class="referral-modal">

        {{-- Header ── colour-coded by type --}}
        <div class="referral-modal__header referral-modal__header--{{ $letterType }}">
            <div>
                <h5 class="mb-0 font-weight-bold">
                    @if($letterType === 'referral')
                        <i class="fas fa-paper-plane mr-2"></i>{{ $editingId ? 'Edit' : 'New' }} Referral Letter
                    @elseif($letterType === 'medical_report')
                        <i class="fas fa-file-medical-alt mr-2"></i>{{ $editingId ? 'Edit' : 'New' }} Medical Report
                    @else
                        <i class="fas fa-calendar-times mr-2"></i>{{ $editingId ? 'Edit' : 'New' }} Excuse Duty Letter
                    @endif
                </h5>
                <p class="mb-0 small opacity-75">{{ $appSettings->clinic_name ?? \App\Models\Setting::DEFAULT_CLINIC_NAME }}</p>
            </div>
            <button wire:click="closeModal" class="btn-close-modal">&times;</button>
        </div>

        {{-- Body --}}
        <div class="referral-modal__body">

            {{-- ── Letter Type Selector (only on create) ── --}}
            @if(!$editingId)
            <div class="type-selector mb-4">
                <button wire:click="$set('letterType','referral')"
                    class="type-selector__btn {{ $letterType === 'referral' ? 'type-selector__btn--active-blue' : '' }}">
                    <i class="fas fa-paper-plane fa-lg mb-1 d-block"></i>Referral Letter
                </button>
                <button wire:click="$set('letterType','medical_report')"
                    class="type-selector__btn {{ $letterType === 'medical_report' ? 'type-selector__btn--active-green' : '' }}">
                    <i class="fas fa-file-medical-alt fa-lg mb-1 d-block"></i>Medical Report
                </button>
                <button wire:click="$set('letterType','excuse_duty')"
                    class="type-selector__btn {{ $letterType === 'excuse_duty' ? 'type-selector__btn--active-orange' : '' }}">
                    <i class="fas fa-calendar-times fa-lg mb-1 d-block"></i>Excuse Duty Letter
                </button>
            </div>
            @endif

            {{-- ── SECTION: Date & Header fields ── --}}
            <div class="form-section">
                <div class="form-section__title"><i class="fas fa-info-circle mr-2"></i>Letter Details</div>
                <div class="row">
                    <div class="col-md-{{ $letterType === 'referral' ? '6' : '12' }}">
                        <div class="form-group">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" wire:model.live="referralDate"
                                class="form-control @error('referralDate') is-invalid @enderror">
                            @error('referralDate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    @if($letterType === 'referral')
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Referral To <span class="text-danger">*</span></label>
                            <input type="text" wire:model.live.debounce.400ms="referralTo"
                                class="form-control @error('referralTo') is-invalid @enderror"
                                placeholder="e.g. KATH – Ophthalmology Dept.">
                            @error('referralTo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            {{-- ── SECTION: Patient ── --}}
            <div class="form-section">
                <div class="form-section__title"><i class="fas fa-user mr-2"></i>Patient Information</div>

                <div class="form-group mb-3">
                    <label class="form-label">Search Existing Patient</label>
                    <div class="patient-search-wrap">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            </div>
                            <input type="text" wire:model.live.debounce.300ms="patientSearch"
                                class="form-control border-left-0"
                                placeholder="Type patient name or PX number…">
                            @if($selectedPatientId)
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" wire:click="clearPatient"><i class="fas fa-times"></i></button>
                            </div>
                            @endif
                        </div>
                        @if(count($patientSuggestions) > 0)
                        <div class="patient-suggestions shadow">
                            @foreach($patientSuggestions as $p)
                            <button wire:click="selectPatient({{ $p['id'] }})" class="patient-suggestions__item">
                                <div class="font-weight-bold small">{{ $p['name'] }}</div>
                                <small class="text-muted">PX#{{ $p['pxnumber'] }}
                                    @if($p['gender']) · {{ ucfirst($p['gender']) }} @endif
                                    @if($p['contact']) · {{ $p['contact'] }} @endif
                                </small>
                            </button>
                            @endforeach
                        </div>
                        @endif
                    </div>
                    @if($selectedPatientId)
                        <small class="text-success mt-1 d-block"><i class="fas fa-check-circle mr-1"></i>Patient linked from records</small>
                    @endif
                </div>

                <div class="row {{ $selectedPatientId ? 'patient-fields--locked' : '' }}">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                Patient Name <span class="text-danger">*</span>
                                @if($selectedPatientId)
                                    <span class="linked-badge"><i class="fas fa-lock fa-xs mr-1"></i>Linked</span>
                                @endif
                            </label>
                            <input type="text" wire:model.live.debounce.400ms="patientName"
                                class="form-control @error('patientName') is-invalid @enderror"
                                placeholder="Full name"
                                {{ $selectedPatientId ? 'readonly' : '' }}>
                            @error('patientName')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">Age / Sex</label>
                            <input type="text" wire:model.live.debounce.400ms="patientAgeSex" class="form-control"
                                placeholder="e.g. 34yrs / M"
                                {{ $selectedPatientId ? 'readonly' : '' }}>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">Contact</label>
                            <input type="text" wire:model.live.debounce.400ms="patientContact" class="form-control"
                                placeholder="Phone"
                                {{ $selectedPatientId ? 'readonly' : '' }}>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ════════════════════════════════════════ --}}
            {{-- REFERRAL-SPECIFIC FIELDS                  --}}
            {{-- ════════════════════════════════════════ --}}
            @if($letterType === 'referral')
            <div class="form-section">
                <div class="form-section__title"><i class="fas fa-stethoscope mr-2"></i>Clinical Findings</div>
                <div class="form-group">
                    <label class="form-label">Chief Complaint</label>
                    <input type="text" wire:model.live.debounce.400ms="complaint" class="form-control" placeholder="Patient's main complaint">
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">VA — OD (Right)</label>
                            <select wire:model.live="vaOd" class="form-control">
                                <option value="">-- select --</option>
                                @foreach(\App\Livewire\Doctor\PatientRecordsComponent::vaLogMarTable($vaNotation) as $label => $logmar)
                                    <option value="{{ $label }}">{{ $label }}{{ $logmar !== null ? ' ('.($logmar >= 0 ? '+' : '').$logmar.')' : ' (NM)' }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">VA — OS (Left)</label>
                            <select wire:model.live="vaOs" class="form-control">
                                <option value="">-- select --</option>
                                @foreach(\App\Livewire\Doctor\PatientRecordsComponent::vaLogMarTable($vaNotation) as $label => $logmar)
                                    <option value="{{ $label }}">{{ $label }}{{ $logmar !== null ? ' ('.($logmar >= 0 ? '+' : '').$logmar.')' : ' (NM)' }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">IOP</label>
                            <input type="text" wire:model.live.debounce.400ms="iop" class="form-control" placeholder="e.g. OD 16 / OS 18">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">Refraction</label>
                            <input type="text" wire:model.live.debounce.400ms="refraction" class="form-control" placeholder="e.g. -2.00 DS">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Anterior Segment</label>
                            <input type="text" wire:model.live.debounce.400ms="anteriorSegment" class="form-control" placeholder="Anterior findings">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Posterior Segment</label>
                            <input type="text" wire:model.live.debounce.400ms="posteriorSegment" class="form-control" placeholder="Posterior findings">
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section__title"><i class="fas fa-clipboard-list mr-2"></i>Diagnosis &amp; Management</div>
                <div class="form-group">
                    <label class="form-label">Diagnosis</label>
                    <div wire:ignore>
                        <select id="diagnosis-select" multiple class="form-control diagnosis-select2">
                            @foreach($diagnoses as $d)
                                <option value="{{ $d->name }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('selectedDiagnoses')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Reason for Referral</label>
                    <textarea wire:model.live.debounce.400ms="reasonForReferral" class="form-control" rows="2" placeholder="Why is this patient being referred?"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Management Given / Notes</label>
                    <textarea wire:model.live.debounce.400ms="management" class="form-control" rows="2" placeholder="Treatment already given…"></textarea>
                </div>
            </div>
            @endif

            {{-- ════════════════════════════════════════ --}}
            {{-- MEDICAL REPORT-SPECIFIC FIELDS            --}}
            {{-- ════════════════════════════════════════ --}}
            @if($letterType === 'medical_report')
            <div class="form-section">
                <div class="form-section__title"><i class="fas fa-notes-medical mr-2"></i>Clinical Details</div>
                <div class="form-group">
                    <label class="form-label">Clinical Findings</label>
                    <textarea wire:model.live.debounce.400ms="clinicalFindings" class="form-control" rows="3"
                        placeholder="Describe clinical findings on examination…"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Diagnosis</label>
                    <div wire:ignore>
                        <select id="diagnosis-select" multiple class="form-control diagnosis-select2">
                            @foreach($diagnoses as $d)
                                <option value="{{ $d->name }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('selectedDiagnoses')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Management / Treatment</label>
                    <textarea wire:model.live.debounce.400ms="treatment" class="form-control" rows="2" placeholder="Treatment and management plan"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Recommendation</label>
                    <textarea wire:model.live.debounce.400ms="recommendation" class="form-control" rows="2" placeholder="Recommendations for the patient"></textarea>
                </div>
            </div>
            @endif

            {{-- ════════════════════════════════════════ --}}
            {{-- EXCUSE DUTY-SPECIFIC FIELDS               --}}
            {{-- ════════════════════════════════════════ --}}
            @if($letterType === 'excuse_duty')
            <div class="form-section">
                <div class="form-section__title"><i class="fas fa-calendar-times mr-2"></i>Excuse Period</div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Excused From <span class="text-danger">*</span></label>
                            <input type="date" wire:model.live="excuseFromDate"
                                class="form-control @error('excuseFromDate') is-invalid @enderror">
                            @error('excuseFromDate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Excused Until <span class="text-danger">*</span></label>
                            <input type="date" wire:model.live="excuseToDate"
                                class="form-control @error('excuseToDate') is-invalid @enderror">
                            @error('excuseToDate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Letter Notes</label>
                    <textarea wire:model="excuseNotes" rows="6"
                        class="form-control @error('excuseNotes') is-invalid @enderror"
                        placeholder="Enter the wording to print on the letter"></textarea>
                    <small class="form-text text-muted">Prefilled and fully editable. Date placeholders are filled automatically on the letter.</small>
                    @error('excuseNotes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Reason / Diagnosis <small class="text-muted">(optional — not printed on letter)</small></label>
                    <div wire:ignore>
                        <select id="diagnosis-select" multiple class="form-control diagnosis-select2">
                            @foreach($diagnoses as $d)
                                <option value="{{ $d->name }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            @endif

            {{-- ── Status (shared) ── --}}
            <div class="form-section">
                <div class="form-section__title"><i class="fas fa-flag mr-2"></i>Record Status</div>
                <div class="form-group mb-0">
                    <select wire:model.live="status" class="form-control">
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

        </div>{{-- end body --}}

        {{-- Footer --}}
        <div class="referral-modal__footer">
            <button wire:click="closeModal" class="btn btn-light mr-2"><i class="fas fa-times mr-1"></i>Cancel</button>
            <button wire:click="save" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading.remove wire:target="save">
                    <i class="fas fa-save mr-1"></i>{{ $editingId ? 'Update' : 'Save &amp; Create' }} Letter
                </span>
                <span wire:loading wire:target="save">
                    <span class="spinner-border spinner-border-sm mr-1"></span>Saving…
                </span>
            </button>
        </div>

    </div>
</div>
@endif

{{-- ── DELETE CONFIRM ── --}}
<div wire:ignore.self class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title"><i class="fas fa-exclamation-triangle mr-2"></i>Delete Letter</h6>
                <button type="button" class="close text-white" wire:click="cancelDelete"><span>&times;</span></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="mb-3">Delete this letter permanently?</p>
                <button wire:click="deleteReferral" class="btn btn-danger mr-2"><i class="fas fa-trash mr-1"></i>Delete</button>
                <button wire:click="cancelDelete" class="btn btn-secondary">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('livewire:init', function () {
    window.addEventListener('show-delete-confirm', () => $('#deleteConfirmModal').modal('show'));
    window.addEventListener('hide-delete-confirm', () => $('#deleteConfirmModal').modal('hide'));
    window.addEventListener('notify', e => {
        const cls = e.detail.type === 'success' ? 'alert-success' : 'alert-danger';
        const el  = document.createElement('div');
        el.className = `alert ${cls} alert-dismissible fade show position-fixed`;
        el.style.cssText = 'top:20px;right:20px;z-index:99999;min-width:280px;box-shadow:0 4px 16px rgba(0,0,0,.15);border-radius:10px;';
        el.innerHTML = `<strong>${e.detail.type === 'success' ? 'Success!' : 'Error!'}</strong> ${e.detail.message}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>`;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    });

    window.addEventListener('init-diagnosis-select2', function (e) {
        var selected = (e.detail && e.detail.selected) ? e.detail.selected : [];
        setTimeout(function () {
            var $select = $('#diagnosis-select');
            if (!$select.length) return;

            if ($select.hasClass('select2-hidden-accessible')) {
                $select.select2('destroy');
            }

            $select.select2({
                placeholder: 'Search and select diagnosis...',
                allowClear: true,
                width: '100%',
                dropdownParent: $('body'),
            });

            if (selected.length) {
                $select.val(selected).trigger('change.select2');
            }

            $select.off('change.livewire').on('change.livewire', function () {
                var values = $(this).val() || [];
                @this.set('selectedDiagnoses', values);
            });
        }, 150);
    });
});
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

/* ── Modal backdrop ── */
.modal-backdrop-custom {
    position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1050;
    display:flex; align-items:flex-start; justify-content:center;
    overflow-y:auto; padding:32px 16px;
}

/* ── Referral modal ── */
.referral-modal { background:#fff; border-radius:16px; width:100%; max-width:820px; box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden; }
.referral-modal__header { color:#fff; padding:1.2rem 1.5rem; display:flex; justify-content:space-between; align-items:center; }
.referral-modal__header--referral       { background:linear-gradient(135deg,#0062cc,#0096ff); }
.referral-modal__header--medical_report { background:linear-gradient(135deg,#1a7a3c,#28a745); }
.referral-modal__header--excuse_duty    { background:linear-gradient(135deg,#e0870a,#ffc107); color:#1a1a1a; }
.referral-modal__header .opacity-75 { opacity:.75; }
.btn-close-modal { background:rgba(255,255,255,.18); border:none; color:inherit; width:32px; height:32px; border-radius:50%; font-size:1.2rem; display:flex; align-items:center; justify-content:center; cursor:pointer; }
.btn-close-modal:hover { background:rgba(255,255,255,.3); }
.referral-modal__body { padding:1.5rem; max-height:72vh; overflow-y:auto; }
.referral-modal__footer { padding:1rem 1.5rem; border-top:1px solid #e9ecef; background:#f8f9fa; display:flex; justify-content:flex-end; }

/* ── Type selector ── */
.type-selector { display:flex; gap:12px; }
.type-selector__btn {
    flex:1; padding:.9rem .5rem; border:2px solid #dee2e6; border-radius:12px;
    background:#fff; font-size:.8rem; font-weight:600; color:#495057;
    cursor:pointer; text-align:center; transition:all .15s;
}
.type-selector__btn:hover { border-color:#adb5bd; background:#f8f9fa; }
.type-selector__btn--active-blue   { border-color:#007bff; background:#e7f0ff; color:#0048c0; }
.type-selector__btn--active-green  { border-color:#28a745; background:#e6f9ef; color:#1a6e40; }
.type-selector__btn--active-orange { border-color:#ffc107; background:#fff8e1; color:#856404; }

/* ── Form sections ── */
.form-section { margin-bottom:1.4rem; }
.form-section:last-child { margin-bottom:0; }
.form-section__title { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#0062cc; margin-bottom:.9rem; padding-bottom:.4rem; border-bottom:2px solid #e7f0ff; }
.form-label { font-size:.82rem; font-weight:600; color:#495057; margin-bottom:.3rem; }

/* ── Patient locked fields ── */
.patient-fields--locked .form-control[readonly] { background:#f0f4f8; color:#6c757d; cursor:not-allowed; border-color:#d0dbe6; }
.patient-fields--locked .form-control[readonly]:focus { box-shadow:none; border-color:#d0dbe6; }
.linked-badge { display:inline-block; font-size:.68rem; font-weight:600; background:#e7f5ec; color:#1a7a3c; border:1px solid #b6dfc6; border-radius:20px; padding:1px 7px; margin-left:6px; vertical-align:middle; }

/* ── Patient autocomplete ── */
.patient-search-wrap { position:relative; }
.patient-suggestions { position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #dee2e6; border-radius:8px; z-index:999; max-height:200px; overflow-y:auto; margin-top:2px; }
.patient-suggestions__item { display:block; width:100%; text-align:left; padding:.55rem 1rem; border:none; background:transparent; cursor:pointer; border-bottom:1px solid #f0f0f0; }
.patient-suggestions__item:hover { background:#f0f7ff; }
.patient-suggestions__item:last-child { border-bottom:none; }

/* ── Select2 inside modal ── */
.select2-container { z-index: 99999 !important; }
</style>
</div>
