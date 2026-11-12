<div class="space-y-5">
    <x-ui.flash />

    <div class="ds-tiles">
        <div class="ds-tile"><span>Active partners</span><b>{{ $counts['active'] }}</b></div>
        <div class="ds-tile"><span>Referring doctors</span><b>{{ $doctorTotal }}</b></div>
        <div class="ds-tile"><span>Agreed rates</span><b>{{ $agreedRates }}</b><small class="ds-hint">partner rates instead of the catalogue's</small></div>
        <div class="ds-tile"><span>Archived</span><b>{{ $counts['archived'] }}</b></div>
    </div>

    <section class="ui-panel bg-white">
        <div class="oo-toolbar" style="grid-template-columns:minmax(220px,1fr) auto auto">
            <input autocomplete="off" type="search" wire:model.live.debounce.250ms="search" placeholder="Search code, name, contact, phone or email…" class="ui-input text-sm" aria-label="Search partner clinics">
            <span class="ui-muted" wire:loading.delay wire:target="search,setStatus">Updating…</span>
            @if($canManage)<button type="button" wire:click="add" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ Add partner clinic</button>@else<span class="ui-muted text-sm">A manager adds and changes partner clinics.</span>@endif
        </div>
        <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter by status">
            @foreach(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $key => $label)
                <button type="button" wire:click="setStatus('{{ $key }}')" class="oo-chip {{ $statusFilter === $key ? 'active' : '' }}" aria-pressed="{{ $statusFilter === $key ? 'true' : 'false' }}">{{ $label }}<b>{{ $counts[$key] }}</b></button>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="oo-table w-full" style="min-width:820px">
                <colgroup><col style="width:100px"><col style="width:auto"><col style="width:200px"><col style="width:120px"><col style="width:140px"><col style="width:90px"></colgroup>
                <thead><tr><th>Code</th><th>Clinic</th><th>Contact</th><th>Doctors</th><th>Results by</th><th class="!text-right"></th></tr></thead>
                <tbody>
                    @forelse($partners as $partner)
                        <tr wire:key="sp-{{ $partner->id }}" class="{{ $viewPartnerId === $partner->id ? 'oo-active' : '' }}" wire:click="openPartner({{ $partner->id }})">
                            <td><span class="oo-id">{{ $partner->code }}</span></td>
                            <td><b>{{ $partner->name }}</b>@if(! $partner->is_active) <span class="oo-badge oo-b-grey">Archived</span>@endif @if($partner->rates_count)<span class="oo-sub">{{ $partner->rates_count }} agreed {{ \Illuminate\Support\Str::plural('rate', $partner->rates_count) }}</span>@endif</td>
                            <td>{{ $partner->contact_person ?: '—' }}<span class="oo-sub">{{ $partner->phone ?: ($partner->email ?: 'No contact details') }}</span></td>
                            <td>{{ $partner->doctors_count ?: '—' }}</td>
                            <td><span class="oo-badge {{ $partner->notify_via === 'none' ? 'oo-b-grey' : 'oo-b-teal' }}">{{ \App\Models\ScanPartnerClinic::NOTIFY_VIA[$partner->notify_via] ?? 'WhatsApp' }}</span></td>
                            <td onclick="event.stopPropagation()"><div class="flex justify-end"><button type="button" class="oo-btn" wire:click="openPartner({{ $partner->id }})">View</button></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">{{ $search || $statusFilter !== 'active' ? 'No partner clinics match these filters.' : 'No partner clinics yet. Add the clinics that send you patients for scans.' }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $partners->links() }}</div>
    </section>

    {{-- PARTNER PANEL --}}
    @if($viewPartner && ! $showForm)
        <div class="oo-overlay" wire:click="closePartner" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="sp-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.closePartner()" wire:key="sp-drawer-{{ $viewPartner->id }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="sp-title">{{ $viewPartner->name }}</h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                        <span class="oo-id">{{ $viewPartner->code }}</span>
                        <span class="oo-badge {{ $viewPartner->is_active ? 'oo-b-green' : 'oo-b-grey' }}">{{ $viewPartner->is_active ? 'Active' : 'Archived' }}</span>
                    </div>
                </div>
                <button type="button" class="oo-x" wire:click="closePartner" aria-label="Close">&times;</button>
            </header>
            <div class="oo-drawer-body">
                <section class="oo-section">
                    <h3>Contact</h3>
                    <dl class="oo-dl">
                        <dt>Contact person</dt><dd>{{ $viewPartner->contact_person ?: '—' }}</dd>
                        <dt>Phone</dt><dd>{{ $viewPartner->phone ?: '—' }}</dd>
                        <dt>Email</dt><dd>{{ $viewPartner->email ?: '—' }}</dd>
                        <dt>Address</dt><dd style="white-space:pre-line">{{ $viewPartner->address ?: '—' }}</dd>
                        <dt>Results</dt><dd>@if($viewPartner->notify_via === 'none')Not sent @else{{ \App\Models\ScanPartnerClinic::NOTIFY_VIA[$viewPartner->notify_via] }} to {{ $viewPartner->notify_via === 'email' ? ($viewPartner->email ?: 'no email set') : ($viewPartner->messagingPhone() ?: 'no phone set') }}@endif</dd>
                    </dl>
                    <p class="ds-hint" style="margin:6px 0 0">Results go to the partner only when the patient has agreed to share them.</p>
                </section>

                @if($canManage)
                    <section class="oo-section">
                        <h3>Commission payout</h3>
                        <dl class="oo-dl">
                            <dt>Paid by</dt><dd>{{ \App\Models\ScanPartnerClinic::PAYOUT_METHODS[$viewPartner->payout_method] ?? 'Cash' }}</dd>
                            <dt>Account</dt><dd>{{ $viewPartner->payout_account ?: '—' }}</dd>
                        </dl>
                    </section>
                @endif

                <section class="oo-section">
                    <h3>Doctors</h3>
                    @forelse($viewPartner->doctors as $doctor)
                        <div class="flex items-center justify-between gap-2" style="padding:6px 0;border-bottom:1px solid var(--clinic-line)" wire:key="spd-{{ $doctor->id }}">
                            <span><b style="{{ $doctor->is_active ? '' : 'color:var(--clinic-muted);text-decoration:line-through' }}">{{ $doctor->name }}</b>@if($doctor->phone) <span class="ui-muted text-xs">· {{ $doctor->phone }}</span>@endif @if(! $doctor->is_active) <span class="oo-badge oo-b-grey">Left</span>@endif</span>
                            @if($canManage)<button type="button" class="oo-link" wire:click="toggleDoctor({{ $doctor->id }})">{{ $doctor->is_active ? 'Mark as left' : 'Back' }}</button>@endif
                        </div>
                    @empty
                        <p class="ui-muted text-sm m-0">No doctors listed. Optional: add them to see which doctor refers most.</p>
                    @endforelse
                    @if($canManage && $viewPartner->is_active)
                        <form wire:submit="addDoctor" class="ds-2 mt-2.5" style="grid-template-columns:minmax(0,1fr) 140px auto;align-items:end">
                            <label class="ds-field"><span>Doctor's name</span><input autocomplete="off" type="text" wire:model="doctorName" class="ui-input">@error('doctorName')<small class="ds-err">{{ $message }}</small>@enderror</label>
                            <label class="ds-field"><span>Phone</span><input autocomplete="off" type="tel" wire:model="doctorPhone" class="ui-input"></label>
                            <button type="submit" class="oo-btn" style="height:37px" wire:loading.attr="disabled" wire:target="addDoctor">Add</button>
                        </form>
                    @endif
                </section>

                <section class="oo-section">
                    <h3>Agreed commission</h3>
                    <p class="ds-hint" style="margin:0 0 6px">Every scan earns this partner the catalogue's commission, except those listed here.</p>
                    @forelse($viewPartner->rates as $rate)
                        <div class="flex items-center justify-between gap-2" style="padding:6px 0;border-bottom:1px solid var(--clinic-line)" wire:key="spr-{{ $rate->id }}">
                            <span><b>{{ $rate->scan?->name ?? 'Removed scan' }}</b> <span class="oo-badge oo-b-purple">{{ \App\Models\DiagnosticScan::describeCommission($rate->commission_type, (float) $rate->commission_value) }}</span>
                                @if($rate->scan)<span class="ui-muted text-xs">· catalogue {{ $rate->scan->commissionLabel() }}</span>@endif</span>
                            @if($canManage)<button type="button" class="oo-link" wire:click="removeRate({{ $rate->id }})" wire:confirm="Use the catalogue's commission for this scan again?">Remove</button>@endif
                        </div>
                    @empty
                        <p class="ui-muted text-sm m-0">None: the catalogue's commission applies to every scan.</p>
                    @endforelse
                    @if($canManage && $viewPartner->is_active)
                        @if($rateScans->isNotEmpty())
                            <form wire:submit="saveRate" class="ds-2 mt-2.5" style="grid-template-columns:minmax(0,1fr) 150px 110px auto;align-items:end">
                                <label class="ds-field"><span>Scan</span>
                                    <select wire:model="rateScanId" class="ui-input"><option value="">Choose…</option>@foreach($rateScans as $scan)<option value="{{ $scan->id }}">{{ $scan->name }} ({{ $scan->commissionLabel() }})</option>@endforeach</select>
                                    @error('rateScanId')<small class="ds-err">{{ $message }}</small>@enderror
                                </label>
                                <label class="ds-field"><span>Type</span><select wire:model.live="rateType" class="ui-input">@foreach(\App\Models\DiagnosticScan::COMMISSION_TYPES as $value => $label)<option value="{{ $value }}">{{ $value === 'percent' ? 'Percentage' : ($value === 'fixed' ? 'Fixed amount' : 'None') }}</option>@endforeach</select></label>
                                <label class="ds-field"><span>{{ $rateType === 'percent' ? '%' : ($rateType === 'fixed' ? currency() : '—') }}</span><input type="number" step="0.01" min="0" wire:model="rateValue" class="ui-input" @disabled($rateType === 'none')></label>
                                <button type="submit" class="oo-btn" style="height:37px" wire:loading.attr="disabled" wire:target="saveRate">Set</button>
                            </form>
                            @error('rateValue')<small class="ds-err">{{ $message }}</small>@enderror
                        @elseif($scanCount === 0)
                            <p class="ds-hint" style="margin:8px 0 0">Add scans to the catalogue first.</p>
                        @endif
                    @endif
                </section>

                @if($canManage)
                    <section class="oo-section" style="border-top:1px solid var(--clinic-line);padding-top:14px">
                        @if($viewPartner->is_active)
                            <button type="button" class="oo-btn danger" wire:click="archive({{ $viewPartner->id }})" wire:confirm="Archive {{ $viewPartner->name }}? It can't be picked for new scans; its history stays.">Archive partner</button>
                        @else
                            <button type="button" class="oo-btn" wire:click="restore({{ $viewPartner->id }})">Restore for new scans</button>
                        @endif
                    </section>
                @endif
            </div>
            <footer class="oo-drawer-foot">
                @if($canManage)<button type="button" class="oo-btn" wire:click="edit({{ $viewPartner->id }})">Edit details</button>@endif
                <button type="button" class="oo-btn" wire:click="closePartner">Close</button>
            </footer>
        </aside>
    @endif

    {{-- ADD / EDIT PANEL --}}
    @if($showForm && $canManage)
        <div class="oo-overlay" wire:click="cancelForm" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="sp-form-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.cancelForm()">
            <header class="oo-drawer-head">
                <div><h2 id="sp-form-title">{{ $editingId ? 'Edit '.$name : 'Add a partner clinic' }}</h2></div>
                <button type="button" class="oo-x" wire:click="cancelForm" aria-label="Close">&times;</button>
            </header>
            <form wire:submit="save" class="oo-drawer-body" id="sp-form">
                <section class="oo-section">
                    <h3>Clinic</h3>
                    <div class="ds-2">
                        <label class="ds-field full"><span>Clinic name *</span><input autocomplete="off" type="text" wire:model="name" class="ui-input" required>@error('name')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        <label class="ds-field"><span>Partner code *</span><input autocomplete="off" type="text" wire:model="code" class="ui-input" maxlength="20" required>@error('code')<small class="ds-err">{{ $message }}</small>@enderror<small class="ds-hint">Print it on the referral slips you give them.</small></label>
                        <label class="ds-field"><span>Contact person</span><input autocomplete="off" type="text" wire:model="contactPerson" class="ui-input"></label>
                        <label class="ds-field"><span>Phone</span><input autocomplete="off" type="tel" wire:model="phone" class="ui-input">@error('phone')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        <label class="ds-field"><span>Email</span><input autocomplete="off" type="email" wire:model="email" class="ui-input">@error('email')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        <label class="ds-field full"><span>Address</span><textarea wire:model="address" rows="2" class="ui-input"></textarea></label>
                    </div>
                </section>
                <section class="oo-section">
                    <h3>Sending results</h3>
                    <div class="ds-2">
                        <label class="ds-field"><span>Send by</span><select wire:model="notifyVia" class="ui-input">@foreach(\App\Models\ScanPartnerClinic::NOTIFY_VIA as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="ds-field"><span>Results phone</span><input autocomplete="off" type="tel" wire:model="notificationPhone" placeholder="Defaults to the phone above" class="ui-input"></label>
                    </div>
                    <p class="ds-hint" style="margin:6px 0 0">Only sent when the patient agrees to share their results.</p>
                </section>
                <section class="oo-section">
                    <h3>Commission payout</h3>
                    <div class="ds-2">
                        <label class="ds-field"><span>Paid by</span><select wire:model="payoutMethod" class="ui-input">@foreach(\App\Models\ScanPartnerClinic::PAYOUT_METHODS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="ds-field"><span>Account / wallet number</span><input autocomplete="off" type="text" wire:model="payoutAccount" class="ui-input">@error('payoutAccount')<small class="ds-err">{{ $message }}</small>@enderror</label>
                    </div>
                    <p class="ds-hint" style="margin:6px 0 0">Stored encrypted; only managers see it.</p>
                </section>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isActive"> Active for new scans</label>
            </form>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" wire:click="cancelForm">Cancel</button>
                <button type="submit" form="sp-form" class="oo-btn primary" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save changes' : 'Add partner clinic' }}</button>
            </footer>
        </aside>
    @endif
</div>
