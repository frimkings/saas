<div class="space-y-5">
    <x-ui.flash />

    <div class="ds-tiles">
        <div class="ds-tile"><span>Scans offered</span><b>{{ $counts['active'] }}</b></div>
        <div class="ds-tile"><span>Earn commission</span><b>{{ $tiles['withCommission'] }}</b></div>
        <div class="ds-tile"><span>Lowest price</span><b>{{ $tiles['lowest'] !== null ? currency().' '.number_format($tiles['lowest'], 2) : '—' }}</b></div>
        <div class="ds-tile"><span>Highest price</span><b>{{ $tiles['highest'] !== null ? currency().' '.number_format($tiles['highest'], 2) : '—' }}</b></div>
    </div>

    <section class="ui-panel bg-white">
        <div class="oo-toolbar" style="grid-template-columns:minmax(220px,1fr) auto auto">
            <input autocomplete="off" type="search" wire:model.live.debounce.250ms="search" placeholder="Search scan name or code…" class="ui-input text-sm" aria-label="Search scans">
            <span class="ui-muted" wire:loading.delay wire:target="search,setStatus">Updating…</span>
            @if($canManage)<button type="button" wire:click="add" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ Add scan</button>@else<span class="ui-muted text-sm">A manager sets scans and prices.</span>@endif
        </div>
        <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter by status">
            @foreach(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $key => $label)
                <button type="button" wire:click="setStatus('{{ $key }}')" class="oo-chip {{ $statusFilter === $key ? 'active' : '' }}" aria-pressed="{{ $statusFilter === $key ? 'true' : 'false' }}">{{ $label }}<b>{{ $counts[$key] }}</b></button>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="oo-table w-full" style="min-width:820px">
                <colgroup><col style="width:auto"><col style="width:100px"><col style="width:140px"><col style="width:160px"><col style="width:130px"><col style="width:150px"></colgroup>
                <thead><tr><th>Scan</th><th>Code</th><th class="oo-num">Price</th><th>Referrer commission</th><th>Turnaround</th><th class="!text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse($scans as $scan)
                        <tr wire:key="scan-{{ $scan->id }}" style="cursor:default">
                            <td><b>{{ $scan->name }}</b>@if(! $scan->is_active) <span class="oo-badge oo-b-grey">Archived</span>@endif</td>
                            <td>@if($scan->code)<span class="oo-id">{{ $scan->code }}</span>@else<span class="ui-muted">—</span>@endif</td>
                            <td class="oo-num">{{ currency() }} {{ number_format($scan->price, 2) }}@if($scan->per_eye)<span class="oo-sub">per eye</span>@endif</td>
                            <td>
                                @if($scan->commission_type === 'none')
                                    <span class="oo-badge oo-b-grey">None</span>
                                @else
                                    <span class="oo-badge oo-b-teal">{{ $scan->commissionLabel() }}</span>
                                    @if($scan->commission_type === 'percent')<span class="oo-sub">{{ currency() }} {{ number_format(\App\Models\DiagnosticScan::commissionOn((float) $scan->price, 'percent', (float) $scan->commission_value), 2) }}{{ $scan->per_eye ? ' per eye' : ' per scan' }}</span>@endif
                                @endif
                            </td>
                            <td>{{ $scan->turnaround ?: '—' }}</td>
                            <td>
                                @if($canManage)
                                    <div class="flex justify-end gap-2">
                                        <button type="button" class="oo-btn" wire:click="edit({{ $scan->id }})">Edit</button>
                                        @if($scan->is_active)
                                            <button type="button" class="oo-btn danger" wire:click="archive({{ $scan->id }})" wire:confirm="Archive {{ $scan->name }}? It can't be ordered any more; past orders keep it.">Archive</button>
                                        @else
                                            <button type="button" class="oo-btn" wire:click="restore({{ $scan->id }})">Restore</button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">{{ $search || $statusFilter !== 'active' ? 'No scans match these filters.' : 'No scans yet. Add the scans you offer, such as OCT, visual field test or fundus photography.' }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <p class="ds-hint">Commission is what the referrer earns on each scan: the partner clinic that sent the patient, or your own doctor who ordered it. Self-referred patients earn no one commission.</p>

    @if($showForm && $canManage)
        <div class="oo-overlay" wire:click="cancelForm" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="ds-scan-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.cancelForm()">
            <header class="oo-drawer-head">
                <div><h2 id="ds-scan-title">{{ $editingId ? 'Edit '.$name : 'Add a scan' }}</h2></div>
                <button type="button" class="oo-x" wire:click="cancelForm" aria-label="Close">&times;</button>
            </header>
            <form wire:submit="save" class="oo-drawer-body" id="ds-scan-form">
                <section class="oo-section">
                    <h3>Scan</h3>
                    <div class="ds-2">
                        <label class="ds-field full"><span>Name *</span><input autocomplete="off" type="text" wire:model="name" class="ui-input" placeholder="e.g. OCT Macula" required>@error('name')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        <label class="ds-field"><span>Code</span><input autocomplete="off" type="text" wire:model="code" class="ui-input" placeholder="e.g. OCT-M" maxlength="20">@error('code')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        <label class="ds-field"><span>Turnaround</span><input autocomplete="off" type="text" wire:model="turnaround" class="ui-input" placeholder="e.g. Same day" maxlength="50">@error('turnaround')<small class="ds-err">{{ $message }}</small>@enderror</label>
                    </div>
                </section>
                <section class="oo-section">
                    <h3>Price</h3>
                    <div class="ds-2">
                        <label class="ds-field"><span>Price ({{ currency() }}) *</span><input type="number" step="0.01" min="0" wire:model.blur="price" class="ui-input" required>@error('price')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        <label class="flex items-center gap-2 text-sm" style="align-self:end;padding-bottom:9px"><input type="checkbox" wire:model.live="perEye"> Priced per eye</label>
                    </div>
                    <p class="ds-hint" style="margin:6px 0 0">Per eye: one eye costs the price, both eyes twice it.</p>
                </section>
                <section class="oo-section">
                    <h3>Referrer commission</h3>
                    <div class="ds-2">
                        <label class="ds-field"><span>Type</span>
                            <select wire:model.live="commissionType" class="ui-input">@foreach(\App\Models\DiagnosticScan::COMMISSION_TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                        </label>
                        @if($commissionType !== 'none')
                            <label class="ds-field"><span>{{ $commissionType === 'percent' ? 'Percentage (%)' : 'Amount ('.currency().')' }} *</span><input type="number" step="0.01" min="0" wire:model.blur="commissionValue" class="ui-input">@error('commissionValue')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        @endif
                    </div>
                    @if($commissionType !== 'none' && $preview !== null)
                        <p class="ds-hint" style="margin:6px 0 0">The referrer earns <b>{{ currency() }} {{ number_format($preview, 2) }}</b> {{ $perEye ? 'per eye' : 'per scan' }}. Partner clinics and your own doctors earn the same; a partner can be given its own rate on its page.</p>
                    @endif
                </section>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isActive"> Offered (can be ordered)</label>
            </form>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" wire:click="cancelForm">Cancel</button>
                <button type="submit" form="ds-scan-form" class="oo-btn primary" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save changes' : 'Add scan' }}</button>
            </footer>
        </aside>
    @endif
</div>
