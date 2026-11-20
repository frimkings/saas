<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .ir-table col.c-when{width:130px}.ir-table col.c-from{width:220px}.ir-table col.c-rx{width:auto}.ir-table col.c-state{width:190px}.ir-table col.c-act{width:90px}
        .ir-rx{display:grid;grid-template-columns:44px 1fr;gap:4px 10px;font-variant-numeric:tabular-nums}
        .ir-rx b{color:var(--clinic-muted);font-size:11px}
        .ir-err{color:#b91c1c;font-size:11px}.ir-hint{color:var(--clinic-muted);font-size:11px}
        .ir-note{background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:8px 10px;font-size:13px}
    </style>

    @php $statusLabels = ['sent' => 'New', 'query' => 'Asked the clinic', 'quoted' => 'Quote sent', 'approved' => 'Approved: start the job', 'accepted' => 'Accepted',
        'quote_declined' => 'Quote declined', 'rejected' => 'Turned down', 'cancelled' => 'Cancelled by the clinic']; @endphp
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Incoming Orders</h1>
            <p class="ui-muted text-sm">Spectacle orders sent online by linked clinics. Accept one to price it: the clinic approves your quote, then you start the job here.</p>
        </div>
        <x-guide-tour page="optical.requests" class="oo-btn" />
    </div>

    <x-ui.flash />

    <section class="ui-panel bg-white" data-tour="incoming-orders">
        <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter requests">
            @foreach(\App\Livewire\Optical\IncomingRequestsComponent::TABS as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" class="oo-chip {{ $tab === $key ? 'active' : '' }}" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}<b>{{ $counts[$key] }}</b></button>
            @endforeach
        </div>
        @if($tab === 'quotes')
            <div class="px-4 pt-3 space-y-2" data-tour="ir-bulk">
                @include('livewire.optical.partials.bulk-report', ['report' => $bulkReport])
                @if($tickedCount)
                    <div class="oo-bulkbar" role="region" aria-label="Ticked quotes">
                        <b>{{ $tickedCount }} ticked</b>
                        <button type="button" class="oo-btn primary" wire:click="bulkStartJobs" wire:confirm="Start {{ $tickedCount }} {{ \Illuminate\Support\Str::plural('job', $tickedCount) }} at the approved prices, on each clinic's account?">Start jobs ({{ $tickedCount }})</button>
                        <button type="button" class="oo-btn ml-auto" wire:click="clearSelection">Clear</button>
                    </div>
                @endif
            </div>
        @endif
        <div class="overflow-x-auto">
            <table class="oo-table ir-table w-full" style="min-width:820px">
                <colgroup>@if($tab === 'quotes')<col style="width:40px">@endif<col class="c-when"><col class="c-from"><col class="c-rx"><col class="c-state"><col class="c-act"></colgroup>
                <thead><tr>@if($tab === 'quotes')<th title="Approved quotes can be started together"><input type="checkbox" class="oo-check" aria-label="Tick every approved quote on this page"
                        x-data="{ ids: @js($requests->where('status', 'approved')->pluck('id')->map(fn ($id) => (string) $id)->values()) }"
                        x-bind:checked="ids.length && ids.every(id => $wire.selected.includes(id))"
                        x-on:change="$wire.selected = $event.target.checked ? [...new Set([...$wire.selected, ...ids])] : $wire.selected.filter(id => ! ids.includes(id))"></th>@endif<th>Sent</th><th>From</th><th>Wearer &amp; prescription</th><th>Status</th><th class="!text-right"></th></tr></thead>
                <tbody>
                    @forelse($requests as $request)
                        <tr wire:key="ir-{{ $request->id }}" class="{{ $viewId === $request->id ? 'oo-active' : '' }}" wire:click="open({{ $request->id }})">
                            @if($tab === 'quotes')<td onclick="event.stopPropagation()">@if($request->status === 'approved')<input type="checkbox" class="oo-check" value="{{ $request->id }}" wire:model.live="selected" aria-label="Tick the approved quote for {{ $request->patient_name }}">@endif</td>@endif
                            <td>{{ $request->created_at->format('d M Y') }}<span class="oo-sub">{{ $request->created_at->format('H:i') }}</span></td>
                            <td><b>{{ $request->senderLabel() }}</b><span class="oo-sub">Ref {{ $request->sender_reference ?: '—' }}</span></td>
                            <td><b>{{ $request->patient_name }}</b>@if($request->urgency === 'urgent') <span class="oo-badge oo-b-red">Urgent</span>@endif @if($request->isRemake())<span class="oo-badge oo-b-amber">Remake</span>@endif
                                <span class="oo-sub">R {{ $request->eyeLine('od') }} · L {{ $request->eyeLine('os') }}</span></td>
                            <td><span class="oo-badge {{ match($request->status) { 'sent' => 'oo-b-purple', 'query', 'quoted' => 'oo-b-amber', 'accepted', 'approved' => 'oo-b-green', 'quote_declined' => 'oo-b-red', default => 'oo-b-grey' } }}">{{ $statusLabels[$request->status] ?? $request->status }}</span>
                                @if(in_array($request->status, ['quoted', 'approved'], true))<span class="oo-sub">{{ currency() }} {{ number_format((float) $request->quote_total, 2) }}{{ $request->quoteExpired() ? ' · expired' : '' }}</span>
                                @elseif($request->order_number)<span class="oo-sub">{{ $request->order_number }} · {{ $request->order_status }}</span>@endif</td>
                            <td class="!text-right" onclick="event.stopPropagation()"><button type="button" class="oo-btn" wire:click="open({{ $request->id }})">View</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $tab === 'quotes' ? 6 : 5 }}" class="ui-empty"><p class="ui-muted text-sm">{{ $tab === 'new' ? 'No new orders. Linked clinics send them from their Spectacles page.' : 'Nothing here.' }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $requests->links() }}</div>
    </section>

    @if($viewRequest)
        <div class="oo-overlay" data-sheet-overlay wire:click="close" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="ir-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.close()" wire:key="ir-drawer-{{ $viewRequest->id }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="ir-title">{{ $viewRequest->patient_name }}</h2>
                    <div class="text-xs ui-muted">From {{ $viewRequest->senderLabel() }} · {{ $viewRequest->created_at->format('d M Y H:i') }}@if($viewRequest->sender) by {{ $viewRequest->sender->name }}@endif</div>
                </div>
                <button type="button" class="oo-x" wire:click="close" aria-label="Close">&times;</button>
            </header>
            <div class="oo-drawer-body">
                @if($viewRequest->status === 'rejected')<p class="ir-note">You turned this down: {{ $viewRequest->reject_reason }}</p>@endif
                @if($viewRequest->status === 'cancelled')<p class="ir-note">{{ $viewRequest->cancel_answered_at ? 'You cancelled this job at the clinic\'s request.' : 'The clinic cancelled this request.' }}</p>@endif
                @if($viewRequest->cancelPending())
                    <section class="oo-section" data-tour="incoming-cancel-ask">
                        <h3>The clinic asks you to cancel</h3>
                        <p class="text-sm m-0">{{ $viewRequest->cancel_request_reason }}</p>
                        <p class="ir-hint mt-1">Asked {{ $viewRequest->cancel_requested_at->format('d M Y H:i') }}. Cancelling puts the stock back and takes the job off their account. If the work is too far along, keep it and say why.</p>
                        @if($action === 'keep')
                            <label class="mt-2" style="display:flex;flex-direction:column;gap:4px"><span class="ir-hint" style="font-weight:700">Why are you keeping the job?</span>
                                <textarea wire:model="keepReason" rows="2" class="ui-input text-sm" placeholder="The clinic sees this, e.g. the lenses are already cut."></textarea></label>
                            @error('keepReason')<p class="ir-err">{{ $message }}</p>@enderror
                            <div class="flex gap-2 mt-2"><button type="button" class="oo-btn" wire:click="refuseCancel">Keep the job</button><button type="button" class="oo-btn" wire:click="startAction('')">Back</button></div>
                        @else
                            @error('order')<p class="ir-err">{{ $message }}</p>@enderror
                            <div class="flex gap-2 mt-2">
                                <button type="button" class="oo-btn danger" wire:click="acceptCancel" wire:confirm="Cancel job {{ $viewRequest->order_number }}? The stock goes back and the clinic is not billed for it.">Cancel the job</button>
                                <button type="button" class="oo-btn" wire:click="startAction('keep')">Keep it…</button>
                            </div>
                        @endif
                    </section>
                @endif
                @if($viewRequest->cancel_refused_reason && $viewRequest->status === 'accepted')<p class="ir-note">You kept the job when the clinic asked to cancel: {{ $viewRequest->cancel_refused_reason }}</p>@endif
                @if($viewRequest->isRemake())<p class="ir-note"><b>Remake</b> of job {{ $viewRequest->remakeOf?->order_number }} · {{ \App\Models\LensOrder::REMAKE_REASONS[$viewRequest->remake_reason] ?? $viewRequest->remake_reason }}. Accepting opens the remake form, where you choose free or charged.</p>@endif
                @if(in_array($viewRequest->status, ['quoted', 'approved', 'quote_declined'], true) && $viewRequest->quote_total !== null)
                    <section class="oo-section" data-tour="incoming-quote">
                        <h3>Your quote</h3>
                        <dl class="oo-dl">
                            @foreach((array) $viewRequest->quote_lines as $line)<dt>{{ $line['label'] }}</dt><dd class="tabular-nums">{{ currency() }} {{ number_format((float) $line['amount'], 2) }}</dd>@endforeach
                            <dt><b>Total</b></dt><dd class="tabular-nums"><b>{{ currency() }} {{ number_format((float) $viewRequest->quote_total, 2) }}</b></dd>
                            @if($viewRequest->quote_valid_until)<dt>Valid until</dt><dd>{{ $viewRequest->quote_valid_until->format('d M Y') }}</dd>@endif
                        </dl>
                        @if($viewRequest->status === 'quoted')<p class="ir-hint mt-1">Waiting for {{ $viewRequest->senderLabel() }} to approve it.</p>@endif
                        @if($viewRequest->status === 'approved')<p class="ir-note mt-2">Approved {{ $viewRequest->approved_at?->format('d M Y H:i') }}. Start the job: it goes on their account at this price.</p>
                            @php $link = \App\Models\OpticalPartnerClinic::find($viewRequest->optical_partner_clinic_id); $linkOwed = $link ? \App\Services\OpticalLinks\OpticalLinkTermsService::owed($link) : 0; @endphp
                            @if($link && \App\Services\OpticalLinks\OpticalLinkTermsService::overLimit($link, $linkOwed))<p class="ir-note mt-2" style="border-color:#fca5a5;background:#fef2f2"><b>Over the credit limit:</b> they owe {{ currency() }} {{ number_format($linkOwed, 2) }} of a {{ currency() }} {{ number_format((float) $link->credit_limit, 2) }} limit. You can still start it, or wait for a payment.</p>@endif
                        @endif
                        @if($viewRequest->status === 'quote_declined')<p class="ir-note mt-2"><b>Declined:</b> {{ $viewRequest->decline_reason }}</p>@endif
                    </section>
                @endif
                @if($viewRequest->query_message)
                    <section class="oo-section">
                        <h3>Your question</h3>
                        <p class="text-sm m-0">{{ $viewRequest->query_message }}</p>
                        @if($viewRequest->query_answer)<p class="ir-note mt-2"><b>Clinic's answer:</b> {{ $viewRequest->query_answer }}</p>@elseif($viewRequest->status === 'query')<p class="ir-hint mt-1">Waiting for the clinic to answer.</p>@endif
                    </section>
                @endif
                <section class="oo-section">
                    <h3>Prescription</h3>
                    <div class="ir-rx">
                        <b>R (OD)</b><span>{{ $viewRequest->eyeLine('od') }}</span>
                        <b>L (OS)</b><span>{{ $viewRequest->eyeLine('os') }}</span>
                        <b>PD</b><span>{{ data_get($viewRequest->rx, 'pd') ?: '—' }}</span>
                    </div>
                </section>
                <section class="oo-section">
                    <h3>Order</h3>
                    <dl class="oo-dl">
                        <dt>Lens</dt><dd>{{ $viewRequest->lens_type ?: '—' }}@if(data_get($viewRequest->lens_choice, 'label'))<span class="oo-sub">Picked on their page: <b>{{ data_get($viewRequest->lens_choice, 'label') }}</b> ({{ collect(data_get($viewRequest->lens_choice, 'eyes', []))->map(fn ($eye, $side) => ($side === 'od' ? 'R' : 'L').' '.(($eye['source'] ?? '') === 'stock' ? 'held from stock' : 'order in'))->implode(', ') }})</span>@endif @if($viewRequest->lens_notes)<span class="oo-sub" style="white-space:pre-line">{{ $viewRequest->lens_notes }}</span>@endif</dd>
                        <dt>Frame</dt><dd>{{ \App\Models\OpticalOrderRequest::FRAME_CHOICES[$viewRequest->frame_choice] ?? $viewRequest->frame_choice }}@if($viewRequest->frame_notes)<span class="oo-sub" style="white-space:pre-line">{{ $viewRequest->frame_notes }}</span>@endif</dd>
                        <dt>Urgency</dt><dd>{{ $viewRequest->urgency === 'urgent' ? 'Urgent' : 'Normal' }}</dd>
                        <dt>Notes</dt><dd style="white-space:pre-line">{{ $viewRequest->notes ?: '—' }}</dd>
                        <dt>Wearer phone</dt><dd>{{ $viewRequest->patient_phone ?: '—' }}</dd>
                        <dt>Clinic ref</dt><dd>{{ $viewRequest->sender_reference ?: '—' }}</dd>
                        @if($viewRequest->order_number)<dt>Job</dt><dd><a class="oo-link" wire:navigate href="{{ route('optical.orders', ['searchTerm' => $viewRequest->order_number]) }}">{{ $viewRequest->order_number }}</a> · {{ $viewRequest->order_status }}</dd>@endif
                    </dl>
                </section>

                @if($viewRequest->status === 'sent')
                    @if($action === 'query')
                        <section class="oo-section">
                            <label class="pc-field" style="display:flex;flex-direction:column;gap:4px"><span class="ir-hint" style="font-weight:700">Question for the clinic</span>
                                <textarea wire:model="question" rows="3" class="ui-input text-sm" placeholder="e.g. The left axis looks unusual. Please confirm."></textarea></label>
                            @error('message')<p class="ir-err">{{ $message }}</p>@enderror
                            <div class="flex gap-2 mt-2"><button type="button" class="oo-btn primary" wire:click="sendQuestion">Send question</button><button type="button" class="oo-btn" wire:click="startAction('')">Back</button></div>
                        </section>
                    @elseif($action === 'reject')
                        <section class="oo-section">
                            <label style="display:flex;flex-direction:column;gap:4px"><span class="ir-hint" style="font-weight:700">Why are you turning it down?</span>
                                <textarea wire:model="rejectReason" rows="3" class="ui-input text-sm" placeholder="The clinic sees this, e.g. lens not available in this power."></textarea></label>
                            @error('reason')<p class="ir-err">{{ $message }}</p>@enderror
                            <div class="flex gap-2 mt-2"><button type="button" class="oo-btn danger" wire:click="reject">Turn down</button><button type="button" class="oo-btn" wire:click="startAction('')">Back</button></div>
                        </section>
                    @endif
                @endif
            </div>
            @if($viewRequest->status === 'sent' && $action === '')
                <footer class="oo-drawer-foot">
                    <button type="button" class="oo-btn danger" wire:click="startAction('reject')">Turn down</button>
                    <button type="button" class="oo-btn" wire:click="startAction('query')">Ask a question</button>
                    <button type="button" class="oo-btn primary" wire:click="accept">{{ $viewRequest->isRemake() ? 'Accept the remake' : 'Accept & price the job' }}</button>
                </footer>
            @elseif(in_array($viewRequest->status, ['quoted', 'approved', 'quote_declined'], true) && $viewRequest->order_status === 'Quotation' && ! $viewRequest->order_cancelled_at)
                <footer class="oo-drawer-foot">
                    <button type="button" class="oo-btn danger" wire:click="cancelQuote" wire:confirm="{{ $viewRequest->status === 'quote_declined' ? 'Cancel this quotation?' : 'Withdraw this quote? The clinic sees that you withdrew it.' }}">{{ $viewRequest->status === 'quote_declined' ? 'Cancel quotation' : 'Withdraw quote' }}</button>
                    @if($viewRequest->status === 'approved')<button type="button" class="oo-btn primary" wire:click="startJob" wire:loading.attr="disabled" wire:target="startJob">Start the job</button>@endif
                </footer>
            @endif
        </aside>
    @endif
</div>
