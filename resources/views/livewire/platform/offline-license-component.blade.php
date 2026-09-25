<div class="pa-card">
    <h3>Issue Offline License</h3>
    <p class="pa-muted">Choose the installation, what it can use, the expiry date and any premium features. Basic features are always included.</p>
    @if($errors->any())<div role="alert" class="pa-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form wire:submit="issue">
        <div class="pa-form">
            <label>Clinic<select wire:model.live="clinicId"><option value="">Select local clinic</option>@foreach($clinics as $clinic)<option value="{{ $clinic->id }}">{{ $clinic->name }}</option>@endforeach</select></label>
            <label>Installation ID<input wire:model.live.debounce.300ms="installationId" placeholder="Copy from the clinic license page"></label>
            <label>Plan name<input wire:model.live.debounce.300ms="planName"></label>
            <label>Expiry date<input type="date" wire:model.live="expires" min="{{ now()->toDateString() }}"></label>
        </div>
        <fieldset style="margin:20px 0 0">
            <legend>Product</legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:8px" role="radiogroup">
                @foreach(\App\Support\PlanProduct::PRODUCTS as $key => $label)
                    <label style="display:flex;flex-direction:column;gap:3px;border:1px solid {{ $product === $key ? '#38bdf8' : '#273750' }};border-radius:10px;padding:10px 12px;cursor:pointer;background:#0b1526">
                        <span><input type="radio" wire:model.live="product" value="{{ $key }}"> <b>{{ $label }}</b></span>
                        <small class="pa-muted">{{ ['clinic' => 'Patients, consultations, cashier, clinic stock', 'optical' => 'Optical orders, lab, POS and optical stock only', 'both' => 'Everything in both modules'][$key] }}</small>
                    </label>
                @endforeach
            </div>
            @error('product')<small class="pa-err">{{ $message }}</small>@enderror
        </fieldset>
        <fieldset style="margin:20px 0">
            <legend>Included premium features</legend>
            <button type="button" class="pa-btn alt" wire:click="selectAll">Select all features</button>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px">
                @foreach($availableFeatures as $feature)
                    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" wire:model.live="features" value="{{ $feature }}"> {{ \App\Support\PlanProduct::EXTRAS[$feature][0] ?? ucwords(str_replace('_', ' ', $feature)) }}</label>
                @endforeach
            </div>
        </fieldset>
        <p class="pa-muted">Only the checked premium features are included. A key with none still unlocks the chosen product's basic features.</p>
        <button class="pa-btn" type="submit" wire:loading.attr="disabled">Generate license key</button>
    </form>
    @if($generatedKey)
        <div class="pa-alert" style="margin-top:20px">
            <label for="issued-offline-key">Generated license key</label>
            <textarea id="issued-offline-key" readonly rows="5" style="width:100%" onclick="this.select()">{{ $generatedKey }}</textarea>
            <p>Copy this key and send it to the clinic administrator to activate. Keep the signing key on the developer server.</p>
        </div>
    @endif
    <h3 style="margin-top:28px">Clinic Access Overview</h3>
    <p class="pa-muted">Hosted status is current. Offline details describe the latest issued key; actual installation state is unavailable while disconnected.</p>
    <label>Find clinic <input wire:model.live.debounce.300ms="overviewSearch" placeholder="Clinic name"></label>
    <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Clinic</th><th>Plan / Features</th><th>Status</th><th>Expiry</th><th>Activation</th></tr></thead><tbody>
        @foreach($overview as $row)<tr>
            <td>{{ $row['clinic']->name }}<small style="display:block">{{ ucfirst($row['clinic']->deployment_mode) }}</small></td>
            <td>{{ $row['plan'] }} <span class="pa-badge">{{ \App\Support\PlanProduct::label(\App\Support\PlanProduct::productOf($row['features'])) }}</span><small style="display:block">{{ implode(', ', array_diff($row['features'], \App\Support\PlanProduct::PRODUCT_KEYS)) ?: 'No premium features' }}</small></td>
            <td>{{ $row['status'] }}</td><td>{{ $row['expiry'] ?? 'Not recorded' }}</td>
            <td>@if($row['clinic']->deployment_mode === 'hosted')Developer-managed
                @elseif($row['reported'])Reported by clinic on {{ $row['reported']->created_at->format('d M Y') }}; not remotely verified
                @elseif($row['issuance'])Not confirmed<br><button class="pa-btn alt" wire:click="recordActivationReport({{ $row['issuance']->id }})" wire:confirm="Has the clinic told you this key was activated? This records their report, not remote verification.">Record activation report</button>
                @else Unknown @endif</td>
        </tr>@endforeach
    </tbody></table></div>
    <p class="pa-muted">Showing up to 100 matching clinics.</p>
    <h3 style="margin-top:24px">Recent License History</h3>
    <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Time</th><th>Developer</th><th>Clinic</th><th>Event</th><th>Details</th></tr></thead><tbody>
        @foreach($history as $entry)<tr><td>{{ $entry->created_at->format('d M Y H:i T') }}</td><td>{{ $entry->user?->name }}</td><td>{{ $entry->clinic?->name }}</td><td>{{ str_replace('_', ' ', $entry->action) }}</td><td>{{ data_get($entry->new_values, 'plan') }} {{ data_get($entry->new_values, 'expires') }}<small style="display:block">{{ implode(', ', data_get($entry->new_values, 'features', [])) }}</small></td></tr>@endforeach
    </tbody></table></div>
</div>
