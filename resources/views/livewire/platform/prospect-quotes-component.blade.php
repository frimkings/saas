<x-platform.page active="quotes" title="Quotes" subtitle="Quotations and pro-forma invoices for businesses that are not on the platform yet — Clinic, Optical, Attendance or a mix. They are not invoices: billing starts once the business is set up through Clinics → Onboard new clinic.">
    <x-slot:actions><button type="button" class="ui-button ui-button-primary" wire:click="newQuote" data-tour="pq-new">＋ New quote</button></x-slot:actions>
    <style>.pq-text{width:100%;min-height:64px;box-sizing:border-box;background:#050d1c;border:1px solid #273750;color:#edf5ff;border-radius:8px;padding:9px 11px;font:inherit}.pq-lines td{padding:6px 8px}.pq-total{display:grid;grid-template-columns:auto auto;gap:4px 18px;justify-content:end;margin-top:10px;color:#aebbd0}.pq-total b{color:#e8eef8;text-align:right}</style>

    @if($showEditor)
        <form class="ui-card" wire:submit="save">
            <div class="ui-card-head">
                <div><h2>{{ $editing ? $editing->number : 'New quote' }}</h2><p>@if($form['pricing_request_id'])Started from a pricing-page request; check the lines before sending. @endif Amounts are for one billing period ({{ $form['billing_interval'] === 'yearly' ? '12 months' : 'one month' }}).</p></div>
                @if($editing)<div class="ui-actions">
                    <a class="ui-button ui-button-secondary ui-button-sm" href="{{ route('platform.quote.pdf', $editing) }}" target="_blank" rel="noopener">Download PDF</a>
                    <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="sendEmail({{ $editing->id }})" wire:confirm="Email {{ $editing->number }} to {{ $editing->email ?: 'the prospect' }}?" @disabled(!$editing->email)>Email</button>
                    <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="sendSms({{ $editing->id }})" wire:confirm="Text a link to {{ $editing->number }} to {{ $editing->phone ?: 'the prospect' }}?" @disabled(!$editing->phone)>SMS</button>
                </div>@endif
            </div>
            @error('form')<div class="ui-alert ui-alert--warning">{{ $message }}</div>@enderror

            <div class="ui-row">
                <label class="ui-field"><span>Document</span>
                    <select class="ui-input" wire:model.live="form.document_type">@foreach(\App\Models\ProspectQuote::TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                    <small class="ui-hint">{{ $form['document_type'] === 'proforma' ? 'Asks for payment before set-up. Numbered PF-…' : 'An offer to consider. Numbered QUO-…' }}</small>
                </label>
                <label class="ui-field"><span>Business name</span><input class="ui-input" type="text" wire:model="form.business_name" maxlength="150">@error('form.business_name')<small class="ui-error">{{ $message }}</small>@enderror</label>
                <label class="ui-field"><span>Contact person</span><input class="ui-input" type="text" wire:model="form.contact_name" maxlength="120"></label>
            </div>
            <div class="ui-row" style="margin-top:12px">
                <label class="ui-field"><span>Phone (for SMS)</span><input class="ui-input" type="tel" wire:model="form.phone" maxlength="30">@error('form.phone')<small class="ui-error">{{ $message }}</small>@enderror</label>
                <label class="ui-field"><span>Email</span><input class="ui-input" type="email" wire:model="form.email" maxlength="150">@error('form.email')<small class="ui-error">{{ $message }}</small>@enderror</label>
                <label class="ui-field"><span>Address / town</span><input class="ui-input" type="text" wire:model="form.address" maxlength="255"></label>
            </div>
            <div class="ui-row" style="margin-top:12px">
                <label class="ui-field"><span>Plan they'll be set up on</span>
                    <select class="ui-input" wire:model.live="form.subscription_plan_id"><option value="">Not chosen yet</option>
                        @foreach($plans->groupBy('product') as $product => $group)<optgroup label="{{ \App\Support\PlanProduct::label($product) }}">@foreach($group as $plan)<option value="{{ $plan->id }}">{{ $plan->name }}</option>@endforeach</optgroup>@endforeach
                    </select>
                    <small class="ui-hint">Used to fill in the clinic onboarding form when they accept. It doesn't change the lines.</small>
                </label>
                <label class="ui-field"><span>Product</span>
                    <select class="ui-input" wire:model="form.product">@foreach(\App\Support\PlanProduct::PRODUCTS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label class="ui-field"><span>Billing</span>
                    <select class="ui-input" wire:model.live="form.billing_interval"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select>
                </label>
                <label class="ui-field"><span>Valid until</span><input class="ui-input" type="date" wire:model="form.valid_until">@error('form.valid_until')<small class="ui-error">{{ $message }}</small>@enderror</label>
            </div>

            <h3 style="margin:18px 0 8px;font-size:14px">Lines</h3>
            <div class="ui-table-wrap ui-table-wrap--framed">
                <table class="ui-table pq-lines">
                    <thead><tr><th>Description</th><th style="width:170px">Amount ({{ $form['currency'] }})</th><th style="width:40px"></th></tr></thead>
                    <tbody>
                    @foreach($form['lines'] as $i => $line)
                        <tr wire:key="pq-line-{{ $i }}">
                            <td><input class="ui-input" type="text" wire:model="form.lines.{{ $i }}.description" maxlength="200" aria-label="Line {{ $i + 1 }} description" placeholder="e.g. Clinic Standard plan (1 month)">@error("form.lines.$i.description")<small class="ui-error">{{ $message }}</small>@enderror</td>
                            <td><input class="ui-input" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="form.lines.{{ $i }}.amount" aria-label="Line {{ $i + 1 }} amount">@error("form.lines.$i.amount")<small class="ui-error">{{ $message }}</small>@enderror</td>
                            <td>@if(count($form['lines']) > 1)<button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="removeLine({{ $i }})" aria-label="Remove line {{ $i + 1 }}">✕</button>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ui-row" style="margin-top:10px;align-items:end">
                <div><button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="addLine">＋ Add a line</button></div>
                <label class="ui-field"><span>Discount ({{ $form['currency'] }})</span><input class="ui-input" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="form.discount">@error('form.discount')<small class="ui-error">{{ $message }}</small>@enderror</label>
                <label class="ui-field"><span>Tax %</span><input class="ui-input" type="number" step="0.01" min="0" max="100" wire:model.live.debounce.400ms="form.tax_rate"></label>
                <label class="ui-field"><span>Currency</span><input class="ui-input" type="text" wire:model.live="form.currency" maxlength="3"></label>
            </div>
            <div class="pq-total">
                <span>Subtotal</span><b>{{ $form['currency'] }} {{ number_format($preview['subtotal'], 2) }}</b>
                <span>Tax</span><b>{{ $form['currency'] }} {{ number_format($preview['tax'], 2) }}</b>
                <span>{{ $form['document_type'] === 'proforma' ? 'Amount due' : 'Total' }}</span><b style="font-size:16px">{{ $form['currency'] }} {{ number_format($preview['total'], 2) }}</b>
            </div>

            <div class="ui-row" style="margin-top:14px">
                <label class="ui-field"><span>Notes for the prospect</span><textarea class="ui-input" wire:model="form.notes" maxlength="2000" placeholder="e.g. Includes set-up and staff training at your clinic."></textarea></label>
                <label class="ui-field"><span>{{ $form['document_type'] === 'proforma' ? 'Payment details and terms' : 'Terms' }}</span><textarea class="ui-input" wire:model="form.terms" maxlength="3000" placeholder="e.g. MoMo 024 000 0000 (Nexus App). Bank: …"></textarea><small class="ui-hint">Kept for the next quote too.</small></label>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2" style="margin-top:14px">
                <button type="button" class="ui-button ui-button-secondary" wire:click="closeEditor">Close</button>
                <button type="submit" class="ui-button ui-button-primary">{{ $editing ? 'Save changes' : 'Save quote' }}</button>
            </div>
        </form>
    @endif

    <section class="ui-card">
        <div class="ui-card-head">
            <div><h2>All quotes</h2><p>Once a business accepts, press "Set up as clinic": the Onboard new clinic form opens with their details filled in, and the quote is marked accepted when the clinic is created.</p></div>
            <div class="ui-actions">
                <label class="ui-field" style="width:200px"><span>Search</span><input class="ui-input" type="search" wire:model.live.debounce.400ms="search" placeholder="Business, number or phone"></label>
                <label class="ui-field" style="width:220px" data-tour="pq-status"><span>Show</span>
                    <select class="ui-input" wire:model.live="status"><option value="open">Open (draft and sent)</option>@foreach(\App\Models\ProspectQuote::STATUSES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach<option value="all">All</option></select>
                </label>
            </div>
        </div>
        <div class="ui-table-wrap ui-table-wrap--framed" data-tour="pq-list">
            <table class="ui-table">
                <thead><tr><th>Number</th><th>Business</th><th>Total</th><th>Status</th><th>Valid until</th><th></th></tr></thead>
                <tbody>
                @forelse($quotes as $q)
                    <tr wire:key="pq-{{ $q->id }}">
                        <td><b>{{ $q->number }}</b><small>{{ $q->typeLabel() }}</small></td>
                        <td>{{ $q->business_name }}<small>{{ $q->contact_name }}@if($q->phone) · {{ $q->phone }}@endif</small></td>
                        <td>{{ $q->currency }} {{ number_format((float) $q->total, 2) }}<small>{{ $q->billing_interval === 'yearly' ? 'a year' : 'a month' }}</small></td>
                        <td><span class="ui-badge {{ $q->expired() ? 'ui-badge--danger' : (['draft' => 'ui-badge--neutral', 'sent' => 'ui-badge--warning', 'accepted' => '', 'declined' => 'ui-badge--danger'][$q->status] ?? 'ui-badge--neutral') }}">{{ $q->statusLabel() }}</span>@if($q->clinic)<small>{{ $q->clinic->name }}</small>@elseif($q->sent_at)<small>sent {{ $q->sent_at->diffForHumans() }}</small>@endif</td>
                        <td>{{ $q->valid_until->format('j M Y') }}</td>
                        <td><div class="ui-actions">
                            <button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="edit({{ $q->id }})">{{ $q->status === 'accepted' ? 'View' : 'Open' }}</button>
                            <a class="ui-button ui-button-secondary ui-button-sm" href="{{ route('platform.quote.pdf', $q) }}" target="_blank" rel="noopener">PDF</a>
                            @if($q->status !== 'accepted')
                                <a class="ui-button ui-button-primary ui-button-sm" href="{{ route('platform.dashboard', ['tab' => 'clinics', 'quote' => $q->id]) }}">Set up as clinic</a>
                                @if($q->status !== 'declined')<button type="button" class="ui-button ui-button-danger ui-button-sm" wire:click="decide({{ $q->id }}, 'declined')" wire:confirm="Mark {{ $q->number }} as declined?">Declined</button>
                                @else<button type="button" class="ui-button ui-button-secondary ui-button-sm" wire:click="decide({{ $q->id }}, 'sent')">Reopen</button>@endif
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-empty">No quotes here yet. Press "New quote", or "Make a quote" on a request under Pricing Page.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px">{{ $quotes->links() }}</div>
    </section>
</x-platform.page>
