<x-platform.page active="sms" title="SMS Messaging" subtitle="Prepaid SMS credits for hosted clinics, sold separately from subscription plans and sent through the platform EazismsPro account.">
    @php
        $spare = isset($providerStatus['balance']) ? $providerStatus['balance'] - $outstanding : null;
        $counts = ['payments' => $bundleInvoices->count(), 'credits' => 0, 'bundles' => 0, 'senders' => $pending->count()];
    @endphp

    <x-ui.flash :map="['sms_message' => str_starts_with(session('sms_message') ?? '', 'Balance check failed') ? 'warning' : 'success']" />

    {{-- Provider balance vs what clinics have paid for --}}
    <section class="pp-card">
        <div class="pp-card-head">
            <div>
                <h2>Platform gateway</h2>
                @if($gatewayReady)
                    <p><span class="pp-badge">Configured</span> &nbsp;Default sender <b>{{ $defaultSender ?: 'account default' }}</b></p>
                @else
                    <p class="fail">Not configured. Set EAZISMS_API_URL and EAZISMS_API_KEY in the server environment; hosted clinics cannot send SMS until then.</p>
                @endif
            </div>
            <button type="button" class="pp-btn" wire:click="checkBalance" wire:loading.attr="disabled" wire:target="checkBalance">
                <span wire:loading.remove wire:target="checkBalance">↻ Check balance now</span><span wire:loading wire:target="checkBalance">Checking…</span>
            </button>
        </div>
        <div class="pp-stats">
            <div class="pp-stat">
                <span>EazismsPro balance</span>
                <b class="{{ ($providerStatus['low'] ?? false) ? 'fail' : 'pass' }}">{{ isset($providerStatus['balance']) ? number_format($providerStatus['balance']) : '—' }}</b>
                <small>
                    @if($providerStatus)
                        Checked {{ \Carbon\Carbon::parse($providerStatus['checked_at'])->diffForHumans() }}
                        @if($providerStatus['error'])<br><span class="fail">{{ $providerStatus['error'] }}</span>@endif
                    @else
                        Not checked yet (checked daily at 07:00)
                    @endif
                </small>
            </div>
            <div class="pp-stat">
                <span>Clinic prepaid credits (unused)</span>
                <b>{{ number_format($outstanding) }}</b>
                <small>Already paid for by clinics and still to be sent</small>
            </div>
            <div class="pp-stat">
                <span>Coverage</span>
                <b class="{{ $spare === null ? '' : ($spare < 0 ? 'fail' : (($providerStatus['low'] ?? false) ? 'warn' : 'pass')) }}">{{ $spare === null ? '—' : ($spare < 0 ? 'Short by ' . number_format(-$spare) : number_format($spare) . ' spare') }}</b>
                <small>Alert when the balance falls below {{ number_format(max((int) config('services.eazisms.low_balance', 1000), $outstanding)) }}</small>
            </div>
        </div>
    </section>

    <nav class="pp-tabs" role="tablist" aria-label="SMS sections">
        @foreach(['payments' => 'Awaiting payment', 'credits' => 'Clinic credits', 'bundles' => 'Bundles', 'senders' => 'Sender IDs'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $section === $key ? 'true' : 'false' }}" class="{{ $section === $key ? 'active' : '' }}" wire:click="setSection('{{ $key }}')">{{ $label }}@if($counts[$key])<span class="pp-badge {{ $key === 'senders' ? 'red' : 'amber' }}">{{ $counts[$key] }}</span>@endif</button>
        @endforeach
    </nav>

    @if($section === 'payments')
        {{-- Bundle payments awaiting confirmation --}}
        <section class="pp-card">
            <div class="pp-card-head"><div><h2>SMS credit purchases awaiting payment</h2><p>Record the clinic's full payment to add the credits immediately. Partial payments are recorded in Invoices &amp; Billing; credits are added once the invoice is fully paid.</p></div></div>
            <div class="pp-table-wrap"><table class="pp-table">
                <thead><tr><th>Clinic</th><th>Invoice</th><th>Credits</th><th>Due</th><th style="min-width:380px">Record payment</th></tr></thead>
                <tbody>
                @forelse($bundleInvoices as $invoice)
                    <tr wire:key="bundle-invoice-{{ $invoice->id }}">
                        <td><b>{{ $invoice->clinic?->name }}</b></td>
                        <td>{{ $invoice->number }}<small>{{ $invoice->currency }} {{ number_format($invoice->balance(), 2) }} outstanding</small></td>
                        <td>{{ number_format($invoice->sms_credits) }}</td>
                        <td>{{ $invoice->due_date->format('d M Y') }}@if($invoice->due_date->isPast())<small><span class="pp-badge red">OVERDUE</span></small>@endif</td>
                        <td>
                            <div class="pp-actions" style="flex-wrap:nowrap">
                                <select wire:model="paymentMethods.{{ $invoice->id }}" aria-label="Payment method" style="width:150px">
                                    <option value="">Method…</option>
                                    <option value="mobile_money">Mobile money</option>
                                    <option value="bank_transfer">Bank transfer</option>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                </select>
                                <input type="text" wire:model="paymentReferences.{{ $invoice->id }}" placeholder="Reference (optional)" aria-label="Payment reference">
                                <button type="button" class="pp-btn sm" wire:click="markBundlePaid({{ $invoice->id }})" wire:loading.attr="disabled"
                                        wire:confirm="Record full payment and add {{ number_format($invoice->sms_credits) }} credits to {{ $invoice->clinic?->name }}?">Mark paid</button>
                            </div>
                            @error('paymentMethods.'.$invoice->id)<small class="pp-err">{{ $message }}</small>@enderror
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="pp-empty">No SMS purchases are waiting for payment.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>

    @elseif($section === 'credits')
        {{-- Clinic balances and manual adjustments --}}
        <section class="pp-card">
            <div class="pp-card-head"><div><h2>Adjust credits</h2><p>Grant free credits (positive number) or correct a balance (negative number). Every change is recorded in the audit log.</p></div></div>
            <form wire:submit="adjustCredits" class="pp-form">
                <div class="pp-row" style="grid-template-columns:minmax(180px,1.2fr) minmax(120px,.6fr) minmax(220px,2fr) auto;align-items:end">
                    <label class="pp-field"><span>Clinic</span>
                        <select wire:model="creditClinicId">
                            <option value="">Choose a clinic…</option>
                            @foreach($clinics as $clinic)<option value="{{ $clinic->id }}">{{ $clinic->name }}</option>@endforeach
                        </select>
                    </label>
                    <label class="pp-field"><span>Credits</span><input type="number" wire:model="creditAmount" placeholder="+500 or -50"></label>
                    <label class="pp-field"><span>Reason</span><input type="text" wire:model="creditNote" placeholder="e.g. goodwill, correction"></label>
                    <button type="submit" class="pp-btn" wire:loading.attr="disabled" wire:target="adjustCredits">Apply</button>
                </div>
                @foreach(['creditClinicId', 'creditAmount', 'creditNote', 'credits'] as $field)
                    @error($field)<small class="pp-err">{{ $message }}</small>@enderror
                @endforeach
            </form>
        </section>

        <section class="pp-card">
            <div class="pp-card-head"><div><h2>Clinic SMS credits</h2><p>Hosted clinics only. Offline clinics send SMS through their own gateway.</p></div></div>
            <div class="pp-table-wrap"><table class="pp-table">
                <thead><tr><th>Clinic</th><th>Credits left</th><th>Sender ID</th><th>SMS</th><th></th></tr></thead>
                <tbody>
                @forelse($clinics as $clinic)
                    @php $wallet = $wallets[$clinic->id] ?? null; $setting = $settings[$clinic->id] ?? null; @endphp
                    <tr wire:key="clinic-{{ $clinic->id }}">
                        <td><b>{{ $clinic->name }}</b></td>
                        <td class="{{ !$wallet || $wallet->balance <= 0 ? 'fail' : ($wallet->balance < $wallet->lowBalanceThreshold() ? 'warn' : 'pass') }}"><b>{{ number_format($wallet?->balance ?? 0) }}</b></td>
                        <td>{{ $setting?->sms_sender_id ?: ($defaultSender ?: '—') }}@if(!$setting?->sms_sender_id)<small>Platform default</small>@endif</td>
                        <td><span class="pp-badge {{ $setting?->sms_enabled ? '' : 'amber' }}">{{ $setting?->sms_enabled ? 'Enabled' : 'Paused' }}</span></td>
                        <td style="text-align:right"><button type="button" class="pp-btn alt sm" wire:click="adjustFor({{ $clinic->id }})" onclick="window.scrollTo({top:0,behavior:'smooth'})">Adjust</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="pp-empty">No hosted clinics.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>

    @elseif($section === 'bundles')
        {{-- Bundle catalogue --}}
        <section class="pp-card">
            <div class="pp-card-head"><div><h2>{{ $editingBundleId ? 'Edit bundle' : 'Add a bundle' }}</h2><p>Clinics pick a bundle in Settings → SMS. Credits never expire. Price changes apply to new purchases only.</p></div></div>
            <form wire:submit="saveBundle" class="pp-form">
                <div class="pp-row" style="grid-template-columns:minmax(160px,1.5fr) repeat(3,minmax(90px,.7fr)) auto;align-items:end">
                    <label class="pp-field"><span>Name</span><input type="text" wire:model="bundleName" placeholder="e.g. Starter"></label>
                    <label class="pp-field"><span>Credits</span><input type="number" wire:model="bundleCredits" placeholder="1000"></label>
                    <label class="pp-field"><span>Price</span><input type="number" step="0.01" wire:model="bundlePrice" placeholder="50.00"></label>
                    <label class="pp-field"><span>Currency</span><input type="text" wire:model="bundleCurrency" maxlength="3" style="text-transform:uppercase"></label>
                    <div class="pp-actions">
                        <button type="submit" class="pp-btn" wire:loading.attr="disabled" wire:target="saveBundle">{{ $editingBundleId ? 'Update bundle' : 'Add bundle' }}</button>
                        @if($editingBundleId)<button type="button" class="pp-btn alt" wire:click="cancelBundleEdit">Cancel</button>@endif
                    </div>
                </div>
                @foreach(['bundleName', 'bundleCredits', 'bundlePrice', 'bundleCurrency'] as $field)
                    @error($field)<small class="pp-err">{{ $message }}</small>@enderror
                @endforeach
            </form>
        </section>

        <section class="pp-card">
            <div class="pp-table-wrap"><table class="pp-table">
                <thead><tr><th>Bundle</th><th>Credits</th><th>Price</th><th>Per SMS</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($bundles as $bundle)
                    <tr wire:key="bundle-{{ $bundle->id }}" style="{{ $editingBundleId === $bundle->id ? 'background:#16304a' : '' }}">
                        <td><b>{{ $bundle->name }}</b></td>
                        <td>{{ number_format($bundle->credits) }}</td>
                        <td>{{ $bundle->currency }} {{ number_format($bundle->price, 2) }}</td>
                        <td>{{ $bundle->currency }} {{ number_format($bundle->price / max(1, $bundle->credits), 3) }}</td>
                        <td><span class="pp-badge {{ $bundle->is_active ? '' : 'grey' }}">{{ $bundle->is_active ? 'On sale' : 'Hidden' }}</span></td>
                        <td><div class="pp-actions" style="justify-content:flex-end">
                            <button type="button" class="pp-btn alt sm" wire:click="editBundle({{ $bundle->id }})">Edit</button>
                            <button type="button" class="pp-btn alt sm" wire:click="toggleBundle({{ $bundle->id }})">{{ $bundle->is_active ? 'Hide' : 'Put on sale' }}</button>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="pp-empty">No bundles yet. Clinics cannot buy SMS credits until you add one.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>

    @else
        {{-- Sender ID approvals --}}
        <section class="pp-card">
            <div class="pp-card-head"><div><h2>Pending sender ID requests</h2><p>Register the sender ID in the EazismsPro dashboard first, then approve it here. Until approval the clinic sends as the default sender.</p></div></div>
            <div class="pp-table-wrap"><table class="pp-table">
                <thead><tr><th>Clinic</th><th>Requested</th><th>Current sender</th><th>Requested at</th><th style="min-width:360px">Decision</th></tr></thead>
                <tbody>
                @forelse($pending as $row)
                    @php $s = $row['setting']; @endphp
                    <tr wire:key="pending-{{ $s->id }}">
                        <td><b>{{ $row['clinic']?->name ?? 'Clinic #'.$s->clinic_id }}</b></td>
                        <td><span class="pp-badge amber">{{ $s->sms_sender_id_requested }}</span></td>
                        <td>{{ $s->sms_sender_id ?: '—' }}</td>
                        <td>{{ $s->sms_sender_id_requested_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td>
                            <div class="pp-actions" style="flex-wrap:nowrap">
                                <button type="button" class="pp-btn sm" wire:click="approve({{ $s->id }})" wire:loading.attr="disabled" wire:confirm="Approve sender ID {{ $s->sms_sender_id_requested }}? Make sure it is registered in EazismsPro first.">Approve</button>
                                <input type="text" wire:model="rejectNotes.{{ $s->id }}" placeholder="Reason for rejection" aria-label="Reason for rejection">
                                <button type="button" class="pp-btn danger sm" wire:click="reject({{ $s->id }})" wire:loading.attr="disabled">Reject</button>
                            </div>
                            @error('rejectNotes.'.$s->id)<small class="pp-err">{{ $message }}</small>@enderror
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="pp-empty">No pending requests.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    @endif
</x-platform.page>
