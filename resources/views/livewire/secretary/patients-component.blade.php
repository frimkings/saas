@php
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $input = 'ui-input';
    $invalid = fn ($field) => $errors->has($field) ? 'true' : 'false';
    $alertTone = ['success' => 'border-green-200 bg-green-50 text-green-800', 'warning' => 'border-amber-200 bg-amber-50 text-amber-900', 'danger' => 'border-red-200 bg-red-50 text-red-800'];
    $tabs = [
        'today' => ['Patient list', null],
        'birthdays' => ['Birthdays', $birthdaysTodayCount > 0 ? $birthdaysTodayCount : null],
        'archived' => ['Archived', null],
    ];
    $iconButton = 'inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 bg-white text-sm no-underline shadow-sm hover:bg-slate-50 disabled:opacity-50';
@endphp
<div data-livewire-root>
<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading">
        <div>
            <h1>Registry Hub</h1>
            <p class="ui-muted">Register patients, update their details and find records.</p>
        </div>
        <div class="ui-actions">
            <button type="button" wire:click="exportRegistry" class="ui-button ui-button-secondary"><i class="fas fa-file-export" aria-hidden="true"></i>Export CSV</button>
            <button type="button" wire:click="$toggle('showImportPanel')" class="ui-button ui-button-secondary" aria-expanded="{{ $showImportPanel ? 'true' : 'false' }}"><i class="fas fa-file-import" aria-hidden="true"></i>Import CSV</button>
            <button type="button" wire:click="downloadTemplate" class="ui-button ui-button-secondary"><i class="fas fa-file-download" aria-hidden="true"></i>Template</button>
        </div>
    </div>

    @if($showImportPanel)
        <section class="ui-panel">
            <div class="ui-panel-heading">
                <h2>Import patients from CSV</h2>
                <button type="button" class="ui-button ui-button-secondary" wire:click="clearImport" aria-label="Close import">Close</button>
            </div>
            <div class="p-4">
                @if($importResults)
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-green-800"><p class="text-2xl font-semibold">{{ $importResults['imported'] }}</p>Imported</div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-slate-700"><p class="text-2xl font-semibold">{{ $importResults['skipped'] }}</p>Skipped</div>
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900"><p class="text-2xl font-semibold">{{ count($importResults['errors']) }}</p>Errors</div>
                    </div>
                    @if(count($importResults['errors']) > 0)
                        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            <strong>Rows needing attention:</strong>
                            <ul class="mt-2 list-disc pl-5">
                                @foreach($importResults['errors'] as $error)<li>{{ $error }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                    <button type="button" class="ui-button ui-button-primary mt-3" wire:click="clearImport">Done</button>
                @else
                    <div class="grid gap-4 md:grid-cols-[7fr_5fr]">
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Required columns: <code>name</code>, <code>contact</code>, <code>dob</code>, <code>gender</code>, and <code>address</code>.</p>
                            <p>Optional columns: <code>email</code>, <code>civil_status</code>, and <code>occupation</code>.
                                Dates should be <code>YYYY-MM-DD</code>. Gender accepts Male/Female/Other or M/F/O.</p>
                        </div>
                        <div>
                            <label for="patientImportFile" class="{{ $label }}">CSV file</label>
                            <input type="file" id="patientImportFile" accept=".csv,text/csv,text/plain" wire:model.live="importFile"
                                   aria-invalid="{{ $invalid('importFile') }}"
                                   class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                            @error('importFile')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="ui-button ui-button-primary" wire:click="importCsv" wire:loading.attr="disabled" wire:target="importCsv,importFile" @disabled(! $importFile)>
                                    <span wire:loading.remove wire:target="importCsv"><i class="fas fa-upload mr-1" aria-hidden="true"></i>Run import</span>
                                    <span wire:loading wire:target="importCsv"><i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Importing…</span>
                                </button>
                                <button type="button" class="ui-button ui-button-secondary" wire:click="downloadTemplate">Template</button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </section>
    @endif

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        {{-- Registration / edit form --}}
        <section class="ui-panel lg:sticky lg:top-16">
            <div @class(['ui-panel-heading', 'bg-sky-50' => $isEditing])>
                <h2><i class="fas {{ $isEditing ? 'fa-user-edit' : 'fa-user-plus' }} mr-2 text-teal-700" aria-hidden="true"></i>{{ $isEditing ? 'Update profile' : 'Registration' }}</h2>
            </div>
            <div class="p-4">
                @if($formMessage)
                    <div class="mb-3 rounded-lg border px-3 py-2 text-sm font-semibold {{ $alertTone[$formMessageType] ?? 'border-sky-200 bg-sky-50 text-sky-900' }}" role="status">
                        <i class="fas {{ $formMessageType === 'success' ? 'fa-check-circle' : ($formMessageType === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle') }} mr-1" aria-hidden="true"></i>{{ $formMessage }}
                    </div>
                @endif

                <form wire:submit="saveEntry" class="space-y-4">
                    <div>
                        <label for="reg-pxnumber" class="{{ $label }}">PX number</label>
                        <input id="reg-pxnumber" type="text" wire:model="state.pxnumber" class="{{ $input }} !bg-slate-50" placeholder="Auto-generated" disabled>
                    </div>

                    <div class="relative">
                        <label for="reg-name" class="{{ $label }}">Full name</label>
                        <input id="reg-name" type="text" wire:model.live.debounce.300ms="nameSearch" class="{{ $input }}" placeholder="Enter patient name…" aria-invalid="{{ $invalid('name') }}" autocomplete="off">
                        @error('name')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        @if(!empty($suggestions))
                            <div class="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                                @foreach($suggestions as $s)
                                    <button type="button" wire:click="selectPatient({{ $s['id'] }})" class="block w-full border-b border-slate-100 px-3 py-2 text-left font-semibold hover:bg-slate-50">{{ $s['name'] }}</button>
                                @endforeach
                            </div>
                        @endif
                        @if(!empty($duplicatePatients) && !$isEditing)
                            <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">
                                <strong><i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>Possible duplicate:</strong>
                                @foreach($duplicatePatients as $duplicate)
                                    <button type="button" wire:click="selectPatient({{ $duplicate['id'] }})" class="ml-1 font-semibold underline">{{ $duplicate['name'] }} ({{ $duplicate['pxnumber'] }})</button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div>
                        <label for="reg-email" class="{{ $label }}">Email address</label>
                        <input id="reg-email" type="email" wire:model="state.email" class="{{ $input }}" aria-invalid="{{ $invalid('email') }}">
                        @error('email')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <fieldset>
                        <legend class="{{ $label }}">Message preferences</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                            <label class="ui-check"><input type="checkbox" class="rounded border-slate-300 text-teal-700" wire:model="state.sms_opt_out">No SMS</label>
                            <label class="ui-check"><input type="checkbox" class="rounded border-slate-300 text-teal-700" wire:model="state.whatsapp_opt_out">No WhatsApp</label>
                            <label class="ui-check"><input type="checkbox" class="rounded border-slate-300 text-teal-700" wire:model="state.marketing_opt_out">No birthday, recall or promotional messages</label>
                        </div>
                    </fieldset>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="reg-contact" class="{{ $label }}">Contact</label>
                            <input id="reg-contact" type="text" wire:model="state.contact" class="{{ $input }}" aria-invalid="{{ $invalid('contact') }}">
                            @error('contact')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="reg-civil" class="{{ $label }}">Civil status</label>
                            <select id="reg-civil" wire:model="state.civil_status" class="{{ $input }}" aria-invalid="{{ $invalid('civil_status') }}">
                                <option value="">Select</option>
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Widowed">Widowed</option>
                                <option value="Divorced">Divorced</option>
                            </select>
                            @error('civil_status')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="registry-dob" class="{{ $label }}">Birthday</label>
                            <div class="relative">
                                <input id="registry-dob" type="text" wire:model.change="dobDisplay"
                                       class="{{ $input }} registry-date-picker !pr-10" aria-invalid="{{ $invalid('dob') }}"
                                       data-trigger="registry-dob-trigger" placeholder="dd/mm/yy" inputmode="numeric" maxlength="8" autocomplete="off">
                                <button id="registry-dob-trigger" type="button" class="absolute inset-y-0 right-0 px-3 text-slate-500 hover:text-teal-700" aria-label="Choose birthday">
                                    <i class="far fa-calendar-alt" aria-hidden="true"></i>
                                </button>
                            </div>
                            @error('dob')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                            @if($dobAge !== null)<p class="mt-1 text-xs font-semibold text-slate-500">Age: {{ $dobAge }} years</p>@endif
                        </div>
                        <div>
                            <label for="reg-gender" class="{{ $label }}">Gender</label>
                            <select id="reg-gender" wire:model="state.gender" class="{{ $input }}" aria-invalid="{{ $invalid('gender') }}">
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                            @error('gender')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label for="reg-occupation" class="{{ $label }}">Occupation</label>
                        <input id="reg-occupation" type="text" wire:model="state.occupation" class="{{ $input }}" aria-invalid="{{ $invalid('occupation') }}">
                        @error('occupation')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="reg-address" class="{{ $label }}">Address</label>
                        <textarea id="reg-address" wire:model="state.address" class="{{ $input }}" rows="3" aria-invalid="{{ $invalid('address') }}"></textarea>
                        @error('address')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="{{ $label }}"><i class="fas fa-wallet mr-1 text-teal-700" aria-hidden="true"></i>Payment type</p>
                        <div class="grid grid-cols-2 gap-2" role="group" aria-label="Payment type">
                            <button type="button" wire:click="choosePaymentType('cash')" aria-pressed="{{ $paymentType === 'cash' ? 'true' : 'false' }}"
                                    @class(['ui-button', 'ui-button-primary' => $paymentType === 'cash', 'ui-button-secondary' => $paymentType !== 'cash'])>
                                <i class="fas fa-money-bill-wave" aria-hidden="true"></i>Cash
                            </button>
                            <button type="button" wire:click="openInsuranceModal" aria-pressed="{{ $paymentType === 'insurance' ? 'true' : 'false' }}"
                                    @class(['ui-button', 'ui-button-primary' => $paymentType === 'insurance', 'ui-button-secondary' => $paymentType !== 'insurance'])>
                                <i class="fas fa-shield-alt" aria-hidden="true"></i>Insurance
                            </button>
                        </div>

                        @if($paymentType === 'insurance')
                            <div class="mt-2 flex items-start justify-between gap-2 rounded-lg border border-slate-200 bg-white p-2 text-sm">
                                <div>
                                    <p class="font-semibold">{{ optional($insurers->firstWhere('id', (int) ($state['insurer_id'] ?? 0)))->name ?? 'Insurance details pending' }}</p>
                                    <p class="text-slate-500">
                                        {{ $state['insurance_member_id'] ?: 'No member ID' }}
                                        @if($state['insurance_policy_number']) &middot; {{ $state['insurance_policy_number'] }} @endif
                                    </p>
                                </div>
                                <button type="button" wire:click="openInsuranceModal" class="text-xs font-semibold text-teal-700 hover:underline">Edit</button>
                            </div>
                            @error('insurer_id')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                            @error('insurance_member_id')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        @endif
                    </div>

                    @if($showInsuranceModal)
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" x-data x-on:keydown.escape.window="$wire.closeInsuranceModal()">
                            <div class="w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="insurance-dialog-title">
                                <div class="ui-panel-heading">
                                    <h2 id="insurance-dialog-title"><i class="fas fa-shield-alt mr-2 text-teal-700" aria-hidden="true"></i>Insurance details</h2>
                                    <button type="button" wire:click="closeInsuranceModal" class="ui-button ui-button-secondary" aria-label="Close insurance details">Close</button>
                                </div>
                                <div class="space-y-3 p-4">
                                    {{-- Insurer: type to search the clinic's active insurers --}}
                                    <div class="relative"
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
                                        <label for="reg-insurer" class="{{ $label }}">Insurer <span class="font-normal normal-case">(optional)</span></label>
                                        <input id="reg-insurer" type="text" x-model="q" @focus="open = true" @input="open = true"
                                               @keydown.escape.stop="open = false"
                                               @keydown.enter.prevent="matches.length && pick(matches[0].id)"
                                               :placeholder="current ? 'Search to change insurer…' : 'Type to search insurers…'"
                                               class="{{ $input }}" aria-invalid="{{ $invalid('insurer_id') }}" autocomplete="off">
                                        <div x-show="open" x-cloak class="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white text-sm shadow-lg">
                                            <button type="button" class="block w-full border-b border-slate-100 px-3 py-1.5 text-left text-slate-500 hover:bg-slate-50" @click="pick('')">— None / cash patient —</button>
                                            <template x-for="o in matches" :key="o.id">
                                                <button type="button" class="flex w-full justify-between border-b border-slate-100 px-3 py-1.5 text-left hover:bg-slate-50"
                                                        :class="{ 'bg-teal-50 font-semibold': String(o.id) === String(selected) }" @click="pick(o.id)">
                                                    <span x-text="o.name"></span>
                                                    <span class="text-slate-500" x-text="o.scheme"></span>
                                                </button>
                                            </template>
                                            <p x-show="!matches.length" class="px-3 py-1.5 text-slate-500">No insurer matches "<span x-text="q"></span>"</p>
                                        </div>
                                        <p class="mt-1 text-sm" x-show="current" x-cloak>
                                            <i class="fas fa-check-circle mr-1 text-green-600" aria-hidden="true"></i>
                                            <strong x-text="current?.name"></strong>
                                            <span class="text-slate-500" x-text="current?.scheme"></span>
                                            <button type="button" class="ml-2 text-red-700 hover:underline" @click="pick('')">Clear</button>
                                        </p>
                                        @error('insurer_id')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-3">
                                        <div>
                                            <label for="reg-member-id" class="{{ $label }}">Member ID</label>
                                            <input id="reg-member-id" type="text" wire:model="state.insurance_member_id" class="{{ $input }}" placeholder="e.g. NHIS-123456" aria-invalid="{{ $invalid('insurance_member_id') }}">
                                            @error('insurance_member_id')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                        </div>
                                        <div>
                                            <label for="reg-member-name" class="{{ $label }}">Member name</label>
                                            <input id="reg-member-name" type="text" wire:model="state.insurance_member_name" class="{{ $input }}" placeholder="As on card" aria-invalid="{{ $invalid('insurance_member_name') }}">
                                            @error('insurance_member_name')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                        </div>
                                        <div>
                                            <label for="reg-policy" class="{{ $label }}">Policy number</label>
                                            <input id="reg-policy" type="text" wire:model="state.insurance_policy_number" class="{{ $input }}" placeholder="Policy #" aria-invalid="{{ $invalid('insurance_policy_number') }}">
                                            @error('insurance_policy_number')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                                    <button type="button" wire:click="choosePaymentType('cash')" class="ui-button ui-button-secondary">Use cash</button>
                                    <button type="button" wire:click="closeInsuranceModal" class="ui-button ui-button-primary">Done</button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <button type="submit" class="ui-button ui-button-primary w-full">{{ $isEditing ? 'Update record' : 'Save patient' }}</button>
                    @if($isEditing)
                        <button type="button" wire:click="resetForm" class="ui-button ui-button-secondary w-full">Cancel</button>
                    @endif
                </form>
            </div>
        </section>

        {{-- Patient list --}}
        <div class="min-w-0 space-y-3">
            @if(count($selectedPatients) > 0)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-800 px-4 py-2 text-white shadow" role="region" aria-label="Selected patients">
                    <span class="font-semibold"><i class="fas fa-check-double mr-2 text-teal-300" aria-hidden="true"></i>{{ count($selectedPatients) }} selected</span>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="exportSelected" class="ui-button ui-button-secondary">Export</button>
                        <button type="button" wire:click="clearSelection" class="ui-button ui-button-secondary">Clear</button>
                        @if($activeTab === 'archived')
                            <button type="button" wire:click="restoreSelected" class="ui-button ui-button-primary">Restore</button>
                        @else
                            <button type="button" wire:click="archiveSelected" wire:confirm="Archive selected patients?" class="ui-button ui-button-danger">Archive</button>
                        @endif
                    </div>
                </div>
            @endif

            <section class="ui-panel">
                <div class="flex overflow-x-auto border-b border-slate-200" role="tablist" aria-label="Patient lists">
                    @foreach($tabs as $key => [$tabLabel, $count])
                        <button type="button" role="tab" aria-selected="{{ $activeTab == $key ? 'true' : 'false' }}" wire:click="$set('activeTab', '{{ $key }}')"
                                @class(['flex flex-1 items-center justify-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold',
                                        'border-teal-700 text-teal-800' => $activeTab == $key,
                                        'border-transparent text-slate-500 hover:text-slate-800' => $activeTab != $key])>
                            @if($key === 'archived')<i class="fas fa-archive" aria-hidden="true"></i>@endif
                            {{ $tabLabel }}
                            @if($key === 'today')
                                <span class="rounded bg-slate-100 px-1.5 text-xs font-normal text-slate-600">M {{ $this->genderStats['male'] }}</span>
                                <span class="rounded bg-slate-100 px-1.5 text-xs font-normal text-slate-600">F {{ $this->genderStats['female'] }}</span>
                            @endif
                            @if($count)<span class="rounded-full bg-amber-100 px-2 text-xs text-amber-800">{{ $count }}</span>@endif
                        </button>
                    @endforeach
                </div>

                <div class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2">
                    <div>
                        <label for="registry-search" class="{{ $label }}">Search</label>
                        <div class="relative">
                            <input id="registry-search" type="search" wire:model.live.debounce.500ms="pxSearch" class="{{ $input }} !pr-9" placeholder="Name or PX number…">
                            @if($pxSearch)
                                <button type="button" class="absolute inset-y-0 right-0 px-3 text-slate-400 hover:text-slate-700" wire:click="$set('pxSearch', '')" aria-label="Clear search"><i class="fas fa-times" aria-hidden="true"></i></button>
                            @endif
                        </div>
                    </div>
                    <div>
                        <span class="{{ $label }}">Date registered</span>
                        <x-date-range from="fromDate" to="toDate" presets="activity" class="w-full" />
                        @if($dateRangeError)<p class="ui-error" role="alert">{{ $dateRangeError }}</p>@endif
                    </div>
                    <div>
                        <label for="registry-insurer" class="{{ $label }}"><i class="fas fa-shield-alt mr-1 text-teal-700" aria-hidden="true"></i>Insurer</label>
                        <select id="registry-insurer" wire:model.live="insurerFilter" class="{{ $input }}">
                            <option value="">All patients</option>
                            @foreach($insurers as $ins)<option value="{{ $ins->id }}">{{ $ins->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="self-end">
                        <button type="button" wire:click="resetFilters" class="ui-button ui-button-secondary w-full"><i class="fas fa-undo-alt" aria-hidden="true"></i>Reset</button>
                    </div>
                </div>

                <div wire:loading.delay wire:target="pxSearch,fromDate,toDate,insurerFilter,resetFilters" class="px-4 pt-3 text-sm font-semibold text-teal-700">
                    <i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Updating results…
                </div>
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th class="w-10"><input type="checkbox" wire:model.live="selectAll" class="rounded border-slate-300 text-teal-700" aria-label="Select all patients on this page"></th>
                                <th>Patient</th>
                                <th>Details</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($patients as $px)
                                @php $isBday = \Carbon\Carbon::parse($px->dob)->isBirthday(); @endphp
                                <tr>
                                    <td><input type="checkbox" wire:model.live="selectedPatients" value="{{ $px->id }}" id="px-{{ $px->id }}" class="rounded border-slate-300 text-teal-700" aria-label="Select {{ $px->name }}"></td>
                                    <td>
                                        <p class="font-semibold text-slate-900">{{ $px->name }} @if($isBday)<span title="Birthday today">🎂</span>@endif</p>
                                        <p class="text-xs font-semibold text-slate-500">{{ $px->pxnumber }} | {{ $px->contact }}</p>
                                        <p class="text-xs text-slate-500">DOB: {{ $px->dob ? \Carbon\Carbon::parse($px->dob)->format('d/m/y') : 'N/A' }} | Registered: {{ $px->created_at->format('d/m/y') }}</p>
                                    </td>
                                    <td>
                                        <p class="font-semibold text-slate-900">{{ \Carbon\Carbon::parse($px->dob)->age }} yrs ({{ $px->gender }})</p>
                                        <p class="text-xs uppercase text-slate-500">{{ $px->civil_status ?? 'N/A' }} | {{ $px->occupation }}</p>
                                    </td>
                                    <td>
                                        <div class="flex justify-end gap-1">
                                            <a href="{{ $this->generateWhatsAppLink($px->name, $px->contact) }}" target="_blank" rel="noopener" class="{{ $iconButton }} text-green-600" title="WhatsApp patient" aria-label="WhatsApp {{ $px->name }}"><i class="fab fa-whatsapp" aria-hidden="true"></i></a>
                                            <a href="{{ $this->generateCallLink($px->contact) }}" class="{{ $iconButton }} text-sky-600" title="Call patient" aria-label="Call {{ $px->name }}"><i class="fas fa-phone" aria-hidden="true"></i></a>
                                            @if($isBday)
                                                <a href="{{ $this->generateBirthdayWhatsAppLink($px->name, $px->contact) }}" target="_blank" rel="noopener" class="{{ $iconButton }} text-amber-500" title="Send birthday WhatsApp" aria-label="Send {{ $px->name }} a birthday WhatsApp"><i class="fas fa-birthday-cake" aria-hidden="true"></i></a>
                                            @endif
                                            @if($activeTab === 'archived')
                                                <button type="button" wire:click="$set('selectedPatients', ['{{ $px->id }}'])" class="{{ $iconButton }} text-green-600" title="Select this patient for restore" aria-label="Select {{ $px->name }} for restore"><i class="fas fa-undo" aria-hidden="true"></i></button>
                                            @else
                                                <button type="button" wire:click="edit({{ $px->id }})" wire:loading.attr="disabled" wire:target="edit({{ $px->id }})" class="{{ $iconButton }} text-teal-700" title="Edit patient" aria-label="Edit {{ $px->name }}"><i class="fas fa-edit" aria-hidden="true"></i></button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="ui-empty text-slate-500">No records found for the current selection.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $patients->links() }}</div>
            </section>
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
