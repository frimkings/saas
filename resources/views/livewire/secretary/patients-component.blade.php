<div data-livewire-root>
<div class="p-4 bg-light min-vh-100">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="text-primary font-weight-bold mb-0">Registry Hub</h2>
            <p class="text-muted small text-uppercase font-weight-bold mb-0">Clinic Administration Dashboard</p>
        </div>
        <div class="btn-group shadow-sm">
            <button wire:click="exportRegistry" class="btn btn-info font-weight-bold px-3">
                <i class="fas fa-file-export mr-1"></i> EXPORT CSV
            </button>
            <button wire:click="$toggle('showImportPanel')" class="btn btn-primary font-weight-bold px-3">
                <i class="fas fa-file-import mr-1"></i> IMPORT CSV
            </button>
            <button wire:click="downloadTemplate" class="btn btn-light border font-weight-bold px-3">
                <i class="fas fa-file-download mr-1"></i> TEMPLATE
            </button>
        </div>
    </div>

    @if($showImportPanel)
        <div class="card border-0 shadow-sm rounded-lg mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0 font-weight-bold text-primary">
                    <i class="fas fa-file-import mr-2"></i>Import Patients from CSV
                </h5>
                <button type="button" class="btn btn-sm btn-light border" wire:click="clearImport">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                @if($importResults)
                    <div class="row">
                        <div class="col-md-4">
                            <div class="alert alert-success mb-2">
                                <strong class="d-block h4 mb-0">{{ $importResults['imported'] }}</strong>
                                Imported
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-secondary mb-2">
                                <strong class="d-block h4 mb-0">{{ $importResults['skipped'] }}</strong>
                                Skipped
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-warning mb-2">
                                <strong class="d-block h4 mb-0">{{ count($importResults['errors']) }}</strong>
                                Errors
                            </div>
                        </div>
                    </div>
                    @if(count($importResults['errors']) > 0)
                        <div class="alert alert-warning">
                            <strong>Rows needing attention:</strong>
                            <ul class="mb-0 mt-2">
                                @foreach($importResults['errors'] as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <button type="button" class="btn btn-primary btn-sm" wire:click="clearImport">
                        <i class="fas fa-check mr-1"></i>Done
                    </button>
                @else
                    <div class="row align-items-start">
                        <div class="col-md-7">
                            <p class="text-muted mb-2">
                                Required columns: <code>name</code>, <code>contact</code>, <code>dob</code>,
                                <code>gender</code>, and <code>address</code>.
                            </p>
                            <p class="text-muted mb-0">
                                Optional columns: <code>email</code>, <code>civil_status</code>, and <code>occupation</code>.
                                Dates should be <code>YYYY-MM-DD</code>. Gender accepts Male/Female/Other or M/F/O.
                            </p>
                        </div>
                        <div class="col-md-5">
                            <div class="custom-file">
                                <input type="file"
                                       class="custom-file-input @error('importFile') is-invalid @enderror"
                                       id="patientImportFile"
                                       accept=".csv,text/csv,text/plain"
                                       wire:model.live="importFile">
                                <label class="custom-file-label" for="patientImportFile">
                                    {{ $importFile ? $importFile->getClientOriginalName() : 'Choose CSV file' }}
                                </label>
                                @error('importFile') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="mt-3">
                                <button class="btn btn-primary"
                                        wire:click="importCsv"
                                        wire:loading.attr="disabled"
                                        wire:target="importCsv,importFile"
                                        {{ !$importFile ? 'disabled' : '' }}>
                                    <span wire:loading.remove wire:target="importCsv">
                                        <i class="fas fa-upload mr-1"></i>Run Import
                                    </span>
                                    <span wire:loading wire:target="importCsv">
                                        <i class="fas fa-spinner fa-spin mr-1"></i>Importing...
                                    </span>
                                </button>
                                <button class="btn btn-light border" wire:click="downloadTemplate">
                                    <i class="fas fa-file-download mr-1"></i>Template
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-lg mb-4 sticky-top" style="top: 20px;">
                <div class="card-header {{ $isEditing ? 'bg-info' : 'bg-primary' }} text-white py-3 border-0">
                    <h5 class="mb-0 font-weight-bold"><i class="fas {{ $isEditing ? 'fa-user-edit' : 'fa-user-plus' }} mr-2"></i> {{ $isEditing ? 'Update Profile' : 'Registration' }}</h5>
                </div>
                <div class="card-body">
                    @if($formMessage)
                        <div class="alert alert-{{ $formMessageType }} py-2 mb-3 small font-weight-bold">
                            <i class="fas {{ $formMessageType === 'success' ? 'fa-check-circle' : ($formMessageType === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle') }} mr-1"></i>
                            {{ $formMessage }}
                        </div>
                    @endif

                    <form wire:submit="saveEntry">
                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">PX NUMBER</label>
                            <input type="text" wire:model="state.pxnumber" class="form-control bg-light border-0" placeholder="Auto-generated" disabled>
                        </div>

                        <div class="form-group position-relative">
                            <label class="small font-weight-bold text-muted">FULL NAME</label>
                            <input type="text" wire:model.live.debounce.300ms="nameSearch" class="form-control bg-light border-0 @error('name') is-invalid @enderror" placeholder="Enter patient name...">
                            @error('name') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                            @if(!empty($suggestions))
                                <div class="list-group position-absolute w-100 shadow-lg mt-1" style="z-index: 1050;">
                                    @foreach($suggestions as $s)
                                        <button type="button" wire:click="selectPatient({{ $s['id'] }})" class="list-group-item list-group-item-action font-weight-bold">{{ $s['name'] }}</button>
                                    @endforeach
                                </div>
                            @endif
                            @if(!empty($duplicatePatients) && !$isEditing)
                                <div class="alert alert-warning py-2 mt-2 mb-0 small">
                                    <strong><i class="fas fa-exclamation-triangle mr-1"></i>Possible duplicate:</strong>
                                    @foreach($duplicatePatients as $duplicate)
                                        <button type="button" wire:click="selectPatient({{ $duplicate['id'] }})" class="btn btn-link btn-sm p-0 ml-1">{{ $duplicate['name'] }} ({{ $duplicate['pxnumber'] }})</button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">EMAIL ADDRESS</label>
                            <input type="email" wire:model="state.email" class="form-control bg-light border-0 @error('email') is-invalid @enderror">
                            @error('email') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                        </div>

                        <div class="form-group">
                            <label class="small font-weight-bold text-muted d-block">MESSAGE PREFERENCES</label>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="smsOptOut" wire:model="state.sms_opt_out">
                                <label class="custom-control-label small" for="smsOptOut">No SMS</label>
                            </div>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="whatsappOptOut" wire:model="state.whatsapp_opt_out">
                                <label class="custom-control-label small" for="whatsappOptOut">No WhatsApp</label>
                            </div>
                            <div class="custom-control custom-checkbox custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="marketingOptOut" wire:model="state.marketing_opt_out">
                                <label class="custom-control-label small" for="marketingOptOut">No birthday, recall or promotional messages</label>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6 form-group">
                                <label class="small font-weight-bold text-muted">CONTACT</label>
                                <input type="text" wire:model="state.contact" class="form-control bg-light border-0 @error('contact') is-invalid @enderror">
                                @error('contact') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-6 form-group">
                                <label class="small font-weight-bold text-muted">CIVIL STATUS</label>
                                <select wire:model="state.civil_status" class="form-control bg-light border-0 @error('civil_status') is-invalid @enderror">
                                    <option value="">Select</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Divorced">Divorced</option>
                                </select>
                                @error('civil_status') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6 form-group">
                                <label class="small font-weight-bold text-muted">BIRTHDAY</label>
                                <div class="input-group">
                                    <input id="registry-dob" type="text" wire:model.change="dobDisplay"
                                           class="form-control bg-light border-0 registry-date-picker @error('dob') is-invalid @enderror"
                                           data-trigger="registry-dob-trigger" placeholder="dd/mm/yy" inputmode="numeric" maxlength="8" autocomplete="off">
                                    <div class="input-group-append">
                                        <button id="registry-dob-trigger" type="button" class="btn btn-light border-0" title="Choose birthday">
                                            <i class="far fa-calendar-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                @error('dob') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                                @if($dobAge !== null)<small class="text-muted font-weight-bold">Age: {{ $dobAge }} years</small>@endif
                            </div>
                            <div class="col-6 form-group">
                                <label class="small font-weight-bold text-muted">GENDER</label>
                                <select wire:model="state.gender" class="form-control bg-light border-0 @error('gender') is-invalid @enderror">
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                                @error('gender') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">OCCUPATION</label>
                            <input type="text" wire:model="state.occupation" class="form-control bg-light border-0 @error('occupation') is-invalid @enderror">
                            @error('occupation') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                        </div>

                        <div class="form-group">
                            <label class="small font-weight-bold text-muted">ADDRESS</label>
                            <textarea wire:model="state.address" class="form-control bg-light border-0 @error('address') is-invalid @enderror" rows="3"></textarea>
                            @error('address') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                        </div>

                        <div class="border rounded p-2 mb-3" style="background:#f8f9fa;">
                            <div class="small font-weight-bold text-muted mb-2">
                                <i class="fas fa-wallet mr-1 text-primary"></i>PAYMENT TYPE
                            </div>
                            <div class="btn-group btn-group-sm w-100 shadow-sm" role="group">
                                <button type="button"
                                        wire:click="choosePaymentType('cash')"
                                        class="btn {{ $paymentType === 'cash' ? 'btn-success' : 'btn-light border' }} font-weight-bold">
                                    <i class="fas fa-money-bill-wave mr-1"></i>Cash
                                </button>
                                <button type="button"
                                        wire:click="openInsuranceModal"
                                        class="btn {{ $paymentType === 'insurance' ? 'btn-primary' : 'btn-light border' }} font-weight-bold">
                                    <i class="fas fa-shield-alt mr-1"></i>Insurance
                                </button>
                            </div>

                            @if($paymentType === 'insurance')
                                <div class="mt-2 p-2 rounded bg-white border small">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="font-weight-bold text-dark">
                                                {{ optional($insurers->firstWhere('id', (int) ($state['insurer_id'] ?? 0)))->name ?? 'Insurance details pending' }}
                                            </div>
                                            <div class="text-muted">
                                                {{ $state['insurance_member_id'] ?: 'No member ID' }}
                                                @if($state['insurance_policy_number'])
                                                    &middot; {{ $state['insurance_policy_number'] }}
                                                @endif
                                            </div>
                                        </div>
                                        <button type="button" wire:click="openInsuranceModal" class="btn btn-xs btn-outline-primary">
                                            Edit
                                        </button>
                                    </div>
                                    @error('insurer_id') <span class="text-danger d-block mt-1">{{ $message }}</span> @enderror
                                    @error('insurance_member_id') <span class="text-danger d-block mt-1">{{ $message }}</span> @enderror
                                </div>
                            @endif
                        </div>

                        @if($showInsuranceModal)
                        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background:rgba(0,0,0,.45);">
                            <div class="modal-dialog modal-dialog-centered" role="document">
                                <div class="modal-content border-0 shadow">
                                    <div class="modal-header bg-primary text-white py-2">
                                        <h6 class="modal-title mb-0 font-weight-bold">
                                            <i class="fas fa-shield-alt mr-1"></i>Insurance Details
                                        </h6>
                                        <button type="button" wire:click="closeInsuranceModal" class="close text-white"><span>&times;</span></button>
                                    </div>
                                    <div class="modal-body">
                        {{-- Insurance Details --}}
                        <div class="border rounded p-2 mb-3" style="background:#f8f9fa;">
                            <div class="small font-weight-bold text-muted mb-2">
                                <i class="fas fa-shield-alt mr-1 text-primary"></i>INSURANCE DETAILS
                                <span class="font-weight-normal text-muted">(optional)</span>
                            </div>
                            {{-- Insurer: type to search the clinic's active insurers --}}
                            <div class="form-group mb-2 position-relative"
                                 x-data="{
                                     open: false,
                                     q: '',
                                     options: @js($insurers->map(fn ($ins) => ['id' => $ins->id, 'name' => $ins->name, 'scheme' => $ins->scheme_type])->values()),
                                     selected: $wire.entangle('state.insurer_id'),
                                     get current() { return this.options.find(o => String(o.id) === String(this.selected)) },
                                     get matches() {
                                         const term = this.q.trim().toLowerCase();
                                         return this.options.filter(o => !term || o.name.toLowerCase().includes(term) || (o.scheme || '').toLowerCase().includes(term)).slice(0, 8);
                                     },
                                     pick(id) { this.selected = id; this.q = ''; this.open = false; },
                                 }"
                                 @click.outside="open = false">
                                <label class="small text-muted">Insurer</label>
                                <input type="text" x-model="q" @focus="open = true" @input="open = true"
                                       @keydown.escape="open = false"
                                       @keydown.enter.prevent="matches.length && pick(matches[0].id)"
                                       :placeholder="current ? 'Search to change insurer…' : 'Type to search insurers…'"
                                       class="form-control form-control-sm bg-light border-0 @error('insurer_id') is-invalid @enderror"
                                       autocomplete="off">
                                <div x-show="open" class="list-group position-absolute w-100 shadow-sm" style="display:none;z-index:1060;max-height:220px;overflow-y:auto;">
                                    <button type="button" class="list-group-item list-group-item-action py-1 small text-muted" @click="pick('')">
                                        — None / Cash Patient —
                                    </button>
                                    <template x-for="o in matches" :key="o.id">
                                        <button type="button" class="list-group-item list-group-item-action py-1 small d-flex justify-content-between"
                                                :class="{ 'active': String(o.id) === String(selected) }" @click="pick(o.id)">
                                            <span x-text="o.name"></span>
                                            <span class="text-muted" x-text="o.scheme"></span>
                                        </button>
                                    </template>
                                    <div x-show="!matches.length" class="list-group-item py-1 small text-muted">No insurer matches "<span x-text="q"></span>"</div>
                                </div>
                                <div class="small mt-1" x-show="current" style="display:none;">
                                    <i class="fas fa-check-circle text-success mr-1"></i>
                                    <strong x-text="current?.name"></strong>
                                    <span class="text-muted" x-text="current?.scheme"></span>
                                    <a href="#" class="ml-2 text-danger" @click.prevent="pick('')">Clear</a>
                                </div>
                                @error('insurer_id') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                            </div>
                            <div class="row">
                                <div class="col-4 form-group mb-0">
                                    <label class="small text-muted">Member ID</label>
                                    <input type="text" wire:model="state.insurance_member_id"
                                           class="form-control form-control-sm bg-light border-0 @error('insurance_member_id') is-invalid @enderror"
                                           placeholder="e.g. NHIS-123456">
                                    @error('insurance_member_id') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-4 form-group mb-0">
                                    <label class="small text-muted">Member Name</label>
                                    <input type="text" wire:model="state.insurance_member_name"
                                           class="form-control form-control-sm bg-light border-0 @error('insurance_member_name') is-invalid @enderror"
                                           placeholder="As on card">
                                    @error('insurance_member_name') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-4 form-group mb-0">
                                    <label class="small text-muted">Policy Number</label>
                                    <input type="text" wire:model="state.insurance_policy_number"
                                           class="form-control form-control-sm bg-light border-0 @error('insurance_policy_number') is-invalid @enderror"
                                           placeholder="Policy #">
                                    @error('insurance_policy_number') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                        </div>
                                    </div>
                                    <div class="modal-footer py-2">
                                        <button type="button" wire:click="choosePaymentType('cash')" class="btn btn-light border">
                                            Use Cash
                                        </button>
                                        <button type="button" wire:click="closeInsuranceModal" class="btn btn-primary">
                                            Done
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        <button type="submit" class="btn {{ $isEditing ? 'btn-info' : 'btn-primary' }} btn-block py-2 font-weight-bold shadow-sm mt-3">
                            {{ $isEditing ? 'UPDATE RECORD' : 'SAVE PATIENT' }}
                        </button>
                        @if($isEditing)
                            <button type="button" wire:click="resetForm" class="btn btn-light btn-block mt-2 border">CANCEL</button>
                        @endif
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            @if(count($selectedPatients) > 0)
            <div class="alert alert-dark shadow-lg border-0 d-flex justify-content-between align-items-center py-2 mb-3" style="background: #2d3436;">
                <span class="text-white font-weight-bold ml-2">
                    <i class="fas fa-check-double mr-2 text-success"></i> {{ count($selectedPatients) }} Records Selected
                </span>
                <div>
                    <button wire:click="exportSelected" class="btn btn-sm btn-info font-weight-bold mr-2"><i class="fas fa-file-csv mr-1"></i> EXPORT</button>
                    <button wire:click="clearSelection" class="btn btn-sm btn-light font-weight-bold mr-2"><i class="fas fa-times mr-1"></i> CLEAR</button>
                    @if($activeTab === 'archived')
                        <button wire:click="restoreSelected" class="btn btn-sm btn-success font-weight-bold"><i class="fas fa-undo mr-1"></i> RESTORE</button>
                    @else
                        <button wire:click="archiveSelected" wire:confirm="Archive selected patients?" class="btn btn-sm btn-danger font-weight-bold"><i class="fas fa-trash-alt mr-1"></i> ARCHIVE</button>
                    @endif
                </div>
            </div>
            @endif

            <div class="card border-0 shadow-sm rounded-lg overflow-hidden">
                <div class="card-header bg-white p-0 border-0">
                  <ul class="nav nav-tabs nav-fill border-0">
    <li class="nav-item">
        <a class="nav-link py-3 {{ $activeTab == 'today' ? 'active font-weight-bold border-bottom-primary text-primary' : 'text-muted' }}" href="#" wire:click.prevent="$set('activeTab', 'today')">
            PATIENT LIST 
            <span class="ml-2">
                <small class="badge badge-light border text-dark">M: {{ $this->genderStats['male'] }}</small>
                <small class="badge badge-light border text-dark">F: {{ $this->genderStats['female'] }}</small>
            </span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link py-3 {{ $activeTab == 'birthdays' ? 'active font-weight-bold border-bottom-warning text-warning' : 'text-muted' }}" href="#" wire:click.prevent="$set('activeTab', 'birthdays')">
            BIRTHDAYS 
            @if($birthdaysTodayCount > 0) 
                <span class="badge badge-warning ml-1">{{ $birthdaysTodayCount }}</span> 
            @endif
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link py-3 {{ $activeTab == 'archived' ? 'active font-weight-bold border-bottom-secondary text-secondary' : 'text-muted' }}" href="#" wire:click.prevent="$set('activeTab', 'archived')">
            <i class="fas fa-archive mr-1"></i> ARCHIVED
        </a>
    </li>
</ul>
                </div>
                <div class="card-body">
                    <div class="row mb-4 align-items-end">
                        <div class="col-md-3">
                            <label class="small font-weight-bold text-muted text-uppercase mb-1">Search Profile</label>
                            <div class="input-group">
                                <input type="text" wire:model.live.debounce.500ms="pxSearch" class="form-control bg-light border-0" placeholder="Name or PX Number...">
                                @if($pxSearch)
                                    <div class="input-group-append">
                                        <button class="btn btn-light border-0 bg-light text-muted" wire:click="$set('pxSearch', '')">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="small font-weight-bold text-muted text-uppercase mb-1">Date Registered Range</label>
                            <div class="small text-muted mb-1">From <span class="float-right mr-5">To</span></div>
                            <div class="d-flex" style="gap:4px;">
                                <div class="input-group" style="min-width:0;flex:1;">
                                    <input id="registry-from-date" type="text" wire:model.live.debounce.350ms="fromDateDisplay"
                                           class="form-control bg-light border-0 registry-date-picker" data-trigger="registry-from-date-trigger"
                                           placeholder="dd/mm/yy" inputmode="numeric" maxlength="8" autocomplete="off">
                                    <div class="input-group-append">
                                        <button id="registry-from-date-trigger" type="button" class="btn btn-light border-0" title="Choose start date">
                                            <i class="far fa-calendar-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="input-group" style="min-width:0;flex:1;">
                                    <input id="registry-to-date" type="text" wire:model.live.debounce.350ms="toDateDisplay"
                                           class="form-control bg-light border-0 registry-date-picker" data-trigger="registry-to-date-trigger"
                                           placeholder="dd/mm/yy" inputmode="numeric" maxlength="8" autocomplete="off">
                                    <div class="input-group-append">
                                        <button id="registry-to-date-trigger" type="button" class="btn btn-light border-0" title="Choose end date">
                                            <i class="far fa-calendar-alt"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            @if($dateRangeError)<small class="text-danger font-weight-bold d-block">{{ $dateRangeError }}</small>@endif
                            <div class="btn-group btn-group-sm mt-2 w-100">
                                <button type="button" wire:click="setDatePreset('today')" class="btn btn-light border">Today</button>
                                <button type="button" wire:click="setDatePreset('this_month')" class="btn btn-light border">This Month</button>
                                <button type="button" wire:click="setDatePreset('last_30_days')" class="btn btn-light border">30 Days</button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="small font-weight-bold text-muted text-uppercase mb-1">
                                <i class="fas fa-shield-alt mr-1 text-primary"></i>Insurer
                            </label>
                            <select wire:model.live="insurerFilter" class="form-control bg-light border-0">
                                <option value="">All Patients</option>
                                @foreach($insurers as $ins)
                                    <option value="{{ $ins->id }}">{{ $ins->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 text-right">
                            <button wire:click="resetFilters" class="btn btn-light border shadow-sm font-weight-bold w-100">
                                <i class="fas fa-undo-alt mr-1"></i> RESET
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <div wire:loading.delay wire:target="pxSearch,fromDateDisplay,toDateDisplay,insurerFilter,setDatePreset,resetFilters" class="small text-primary font-weight-bold mb-2">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Updating registry results...
                        </div>
                        <table class="table table-hover align-middle">
                            <thead class="bg-light text-muted small text-uppercase font-weight-bold">
                                <tr>
                                    <th style="width: 40px;">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" wire:model.live="selectAll" class="custom-control-input" id="selectAll">
                                            <label class="custom-control-label" for="selectAll"></label>
                                        </div>
                                    </th>
                                    <th>Patient Profile</th>
                                    <th>Social Info</th>
                                    <th class="text-right pr-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($patients as $px)
                                    @php $isBday = \Carbon\Carbon::parse($px->dob)->isBirthday(); @endphp
                                    <tr>
                                        <td>
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" wire:model.live="selectedPatients" value="{{ $px->id }}" class="custom-control-input" id="px-{{ $px->id }}">
                                                <label class="custom-control-label" for="px-{{ $px->id }}"></label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold text-dark mb-0">{{ $px->name }} @if($isBday) 🎂 @endif</div>
                                            <small class="text-muted font-weight-bold">{{ $px->pxnumber }} | {{ $px->contact }}</small>
                                            <div class="small text-muted">DOB: {{ $px->dob ? \Carbon\Carbon::parse($px->dob)->format('d/m/y') : 'N/A' }} | Registered: {{ $px->created_at->format('d/m/y') }}</div>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold text-dark">{{ \Carbon\Carbon::parse($px->dob)->age }} yrs ({{ $px->gender }})</div>
                                            <small class="text-muted text-uppercase font-weight-bold">{{ $px->civil_status ?? 'N/A' }} | {{ $px->occupation }}</small>
                                        </td>
                                        <td class="text-right pr-4">
                                            <div class="btn-group">
                                                <a href="{{ $this->generateWhatsAppLink($px->name, $px->contact) }}"
                                                   target="_blank"
                                                   rel="noopener"
                                                   class="btn btn-sm btn-white border shadow-sm"
                                                   title="WhatsApp patient">
                                                    <i class="fab fa-whatsapp text-success"></i>
                                                </a>
                                                <a href="{{ $this->generateCallLink($px->contact) }}"
                                                   class="btn btn-sm btn-white border shadow-sm"
                                                   title="Call patient">
                                                    <i class="fas fa-phone text-info"></i>
                                                </a>
                                                @if($isBday)
                                                    <a href="{{ $this->generateBirthdayWhatsAppLink($px->name, $px->contact) }}"
                                                       target="_blank"
                                                       rel="noopener"
                                                       class="btn btn-sm btn-white border shadow-sm"
                                                       title="Send birthday WhatsApp">
                                                        <i class="fas fa-birthday-cake text-warning"></i>
                                                    </a>
                                                @endif
                                                @if($activeTab === 'archived')
                                                <button type="button"
                                                        wire:click="$set('selectedPatients', ['{{ $px->id }}'])"
                                                        class="btn btn-sm btn-white border shadow-sm"
                                                        title="Select this patient for restore">
                                                    <i class="fas fa-undo text-success"></i>
                                                </button>
                                                @else
                                                <button type="button"
                                                        wire:click="edit({{ $px->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="edit({{ $px->id }})"
                                                        class="btn btn-sm btn-white border shadow-sm"
                                                        title="Edit patient">
                                                    <i class="fas fa-edit text-primary"></i>
                                                </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center py-5 text-muted small font-weight-bold">No records found for the current selection.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $patients->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        function parseRegistryDate(value, isBirthday) {
            var match = /^(\d{2})\/(\d{2})\/(\d{2})$/.exec(value.trim());
            if (!match) {
                return new Date(NaN);
            }

            var day = Number(match[1]);
            var month = Number(match[2]);
            var year = 2000 + Number(match[3]);
            var date = new Date(year, month - 1, day);

            if (
                date.getFullYear() !== year
                || date.getMonth() !== month - 1
                || date.getDate() !== day
            ) {
                return new Date(NaN);
            }

            if (isBirthday && date > new Date()) {
                date.setFullYear(year - 100);
            }

            return date;
        }

        function formatRegistryDate(date) {
            var pad = function (value) { return String(value).padStart(2, '0'); };
            return pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + '/' + pad(date.getFullYear() % 100);
        }

        function initializeRegistryDatePickers() {
            if (typeof Pikaday === 'undefined') {
                return;
            }

            document.querySelectorAll('.registry-date-picker').forEach(function (field) {
                if (!field.dataset.maskReady) {
                    field.addEventListener('input', function () {
                        var digits = field.value.replace(/\D/g, '').slice(0, 6);
                        field.value = digits.replace(/^(\d{2})(\d)/, '$1/$2').replace(/^(\d{2}\/\d{2})(\d)/, '$1/$2');
                    });
                    field.dataset.maskReady = '1';
                }

                if (field._registryDatePicker) {
                    return;
                }

                var trigger = document.getElementById(field.dataset.trigger);
                var isBirthday = field.id === 'registry-dob';
                field._registryDatePicker = new Pikaday({
                    field: field,
                    trigger: trigger || field,
                    format: 'DD/MM/YY',
                    parse: function (value) {
                        return parseRegistryDate(value, isBirthday);
                    },
                    toString: function (date) {
                        return formatRegistryDate(date);
                    },
                    maxDate: new Date(),
                    yearRange: [1900, new Date().getFullYear()],
                    onSelect: function () {
                        field.value = formatRegistryDate(this.getDate());
                        field.dispatchEvent(new Event('input', { bubbles: true }));
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                        field.dispatchEvent(new Event('blur', { bubbles: true }));
                    }
                });
            });
        }

        initializeRegistryDatePickers();
        document.addEventListener('DOMContentLoaded', initializeRegistryDatePickers);
        document.addEventListener('livewire:init', initializeRegistryDatePickers);

        if (window.Livewire && typeof Livewire.hook === 'function') {
            Livewire.hook('morph.updated', initializeRegistryDatePickers);
        }
    })();
</script>
</div>
