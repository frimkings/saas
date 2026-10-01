{{-- Order side panel and its dialogs. Needs the ManagesOrderPanel trait on the component. --}}
    @php $viewOrder = $this->panelOrder(); @endphp
    @if($viewOrder)
        @php
            $o = $viewOrder;
            ['total' => $total, 'paid' => $paid, 'balance' => $balance, 'clinicBill' => $clinicBill] = \App\Support\Optical\OrderPresenter::money($o);
            $notifier = app(\App\Services\OpticalCollectionNotifier::class);
            $smsAvailable = \App\Support\Messaging\SmsAvailability::check()['available'];
            [$badgeLabel, $badgeClass] = \App\Support\Optical\OrderPresenter::badge($o->status);
            $isReady = in_array($o->status, ['Ready for Collection', 'Ready'], true);
            $open = ! in_array($o->status, ['Quotation', 'Cancelled', 'Collected'], true);
            $stepKeys = array_keys(\App\Support\Optical\OrderPresenter::STEPS);
            $currentIndex = array_search($o->status === 'Ready' ? 'Ready for Collection' : $o->status, $stepKeys, true);
            $awaited = $o->lensLines->where('source', 'special_order')->where('status', 'ordered');
            $step = \App\Support\Optical\OrderPresenter::nextStep($o, $bench ?? false);
            $onAccount = $o->order_source === 'partner' && $o->partner_billing_terms === 'on_account';
            $isManager = auth()->user()?->hasAnyRole(['Manager', 'Super Admin']);
            // Orders store their lab docket as JSON in notes; older or hand-written notes stay plain text.
            $docket = \App\Support\Optical\OrderPresenter::docket($o);
            $rx = $o->prescription_snapshot;
            $hasRx = filled(data_get($rx, 'od.sph')) || filled(data_get($rx, 'os.sph'));
        @endphp
        <div class="oo-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { viewOrderId: null })" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="oo-drawer-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="dismissLocal($el, $wire, { viewOrderId: null })" wire:key="drawer-{{ $o->id }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="oo-drawer-title"><span class="oo-id" style="font-size:17px">{{ $o->order_id }}</span></h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                        <span class="oo-badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                        <span><b class="text-slate-800">{{ $o->display_customer_name }}</b>@if($o->display_customer_phone) · {{ $o->display_customer_phone }}@endif</span>
                        <span>· Created {{ $o->created_at?->format('d M Y') }}@if($o->user) by {{ $o->user->name }}@endif</span>
                    </div>
                </div>
                <button type="button" class="oo-x" x-on:click="dismissLocal($el, $wire, { viewOrderId: null })" aria-label="Close">&times;</button>
            </header>

            <div class="oo-drawer-body">
                @if($errors->any())<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif

                @if($o->status === 'Cancelled')
                    <div class="oo-note red">Cancelled{{ $o->cancelled_at ? ' on '.$o->cancelled_at->format('d M Y') : '' }}.@if($o->refundLog) Refunded {{ currency() }} {{ number_format((float) $o->refundLog->refunded_amount, 2) }}@if((float) $o->cancellation_fee > 0); fee kept {{ currency() }} {{ number_format((float) $o->cancellation_fee, 2) }}@endif.@elseif((float) $o->cancellation_fee > 0) Deposit kept: {{ currency() }} {{ number_format((float) $o->cancellation_fee, 2) }}.@endif @if($o->cancellation_reason)<span class="block mt-1">{{ $o->cancellation_reason }}</span>@endif</div>
                @elseif($o->status === 'Quotation')
                    <div class="oo-note amber">This is a quotation. Nothing is reserved or charged until it is converted to an order.</div>
                @else
                    <ol class="oo-steps" aria-label="Order progress">
                        @foreach(\App\Support\Optical\OrderPresenter::STEPS as $key => $label)
                            @php $i = array_search($key, $stepKeys, true); @endphp
                            <li class="{{ $currentIndex !== false && $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current '.($o->status === 'Collected' ? 'done' : '') : '') }}" @if($i === $currentIndex) aria-current="step" @endif>{{ $label }}</li>
                        @endforeach
                    </ol>
                @endif

                <div class="oo-money">
                    <div><span>Total</span><b>{{ currency() }} {{ number_format($total, 2) }}</b></div>
                    <div><span>Paid</span><b style="color:#047857">{{ currency() }} {{ number_format($paid, 2) }}</b></div>
                    <div><span>Balance</span><b style="color:{{ $balance > 0 && $o->status !== 'Quotation' ? '#b91c1c' : 'inherit' }}">{{ currency() }} {{ number_format($balance, 2) }}</b></div>
                </div>

                @foreach($awaited as $line)
                    @php $poLine = $o->purchaseOrderLines->first(fn ($pl) => $pl->eye === $line->eye && $pl->purchaseOrder?->status !== 'cancelled'); @endphp
                    <div class="oo-note amber flex flex-wrap items-center justify-between gap-2">
                        <span>Waiting for the {{ strtoupper($line->eye) }} special-order lens ({{ $poLine ? 'on '.$poLine->purchaseOrder->po_number : 'not ordered from a supplier yet' }}). Glazing can't start until it arrives.</span>
                        @if($open)<button type="button" class="oo-btn warn" wire:click="receiveLens({{ $o->id }}, '{{ $line->eye }}')" wire:confirm="Confirm the {{ strtoupper($line->eye) }} lens has arrived from the supplier?">Mark received</button>@endif
                    </div>
                @endforeach
                @if($clinicBill)
                    <div class="oo-note amber" style="background:#f0f9ff;border-color:#bae6fd;color:#075985">Clinic order: the frame and lenses are paid on the patient's clinic bill ({{ strtolower($clinicBill['label']) }}), so payments are taken at reception or the cashier, not here.</div>
                @endif
                @if($isReady && $balance > 0 && ! $onAccount)
                    <div class="oo-note amber">{{ $clinicBill ? 'The clinic bill still has '.currency().' '.number_format($balance, 2).' to pay for these glasses. Take it at reception or the cashier before handing them over.' : 'Take the remaining '.currency().' '.number_format($balance, 2).' before handing over the glasses.' }}</div>
                @endif

                <section class="oo-section">
                    <h3>Details</h3>
                    <dl class="oo-dl">
                        <dt>Work type</dt><dd>{{ ucfirst(str_replace('_', ' ', $o->work_type ?? 'prescription')) }}@if($o->order_source === 'partner') · partner job{{ $o->partnerClinic || $o->partner_clinic_name ? ' for '.($o->partnerClinic?->name ?? $o->partner_clinic_name) : '' }}{{ $onAccount ? ' (on account)' : '' }}@endif</dd>
                        <dt>Frame</dt><dd>{{ $o->own_frame ? "Customer's own frame" : ($o->frameOpticalProduct?->name ?? $o->frameProduct?->name ?? ($o->frame_model_number ?: '—')) }}@if(! $o->own_frame && (float) $o->frame_price > 0) · {{ currency() }} {{ number_format((float) $o->frame_price, 2) }}@endif</dd>
                        <dt>Pickup date</dt><dd>{{ $o->pickUpDate ? \Carbon\Carbon::parse($o->pickUpDate)->format('D, d M Y') : 'Not set' }}</dd>
                        @if($o->sent_to_lab_at)<dt>Lab</dt><dd>{{ $o->labSupplier?->name ?? 'Outside lab' }} · sent {{ $o->sent_to_lab_at->format('d M') }}{{ $o->expected_back_at ? ' · due back '.$o->expected_back_at->format('d M Y') : '' }}@if($o->status === 'Sent to Lab' && $o->expected_back_at?->lt(today())) <span style="color:#b91c1c">(overdue)</span>@endif</dd>@endif
                        @if($o->status === 'Quotation' && $o->quote_valid_until)<dt>Quote valid</dt><dd>{{ $o->isQuoteExpired() ? 'Expired' : 'Until' }} {{ $o->quote_valid_until->format('d M Y') }}@if($o->isQuoteExpired()) <span style="color:#b91c1c">· choose prices when converting</span>@endif</dd>@endif
                        @if($o->ready_at)<dt>Ready since</dt><dd>{{ $o->ready_at->format('d M Y, H:i') }}@if($o->ready_notified_at) · customer told {{ $o->ready_notified_at->diffForHumans() }}@else · <span style="color:#b91c1c">customer not told yet</span>@endif</dd>@endif
                        @if($o->collected_at)<dt>Collected</dt><dd>{{ $o->collected_at->format('d M Y, H:i') }}</dd>@endif
                        @if($o->warranty_expires_at)<dt>Warranty</dt><dd>{{ $o->isUnderWarranty() ? 'Until' : 'Ended' }} {{ $o->warranty_expires_at->format('d M Y') }}</dd>@endif
                        @if($o->remakeOf)<dt>Remake of</dt><dd>{{ $o->remakeOf->order_id }} · {{ \App\Models\LensOrder::REMAKE_REASONS[$o->remake_reason] ?? 'Other' }} · {{ $o->remake_charge === 'free' ? 'free' : 'charged' }}</dd>@endif
                        @if($docket === null && $o->notes)<dt>Notes</dt><dd style="white-space:pre-line">{{ $o->notes }}</dd>@endif
                    </dl>
                </section>

                @if($hasRx)
                    <section class="oo-section">
                        <h3>Prescription</h3>
                        <table class="oo-lines oo-rx">
                            <thead><tr><th>Eye</th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th><th>PD</th><th>HGT</th></tr></thead>
                            <tbody>
                                @foreach(['od' => 'R (OD)', 'os' => 'L (OS)'] as $eye => $label)
                                    <tr><th scope="row">{{ $label }}</th>@foreach(['sph', 'cyl', 'axis', 'add', 'pd', 'hgt'] as $field)<td>{{ filled(data_get($rx, "$eye.$field")) ? data_get($rx, "$eye.$field") : '—' }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endif

                @if($docket)
                    @php
                        $lens = array_filter([
                            'Lens' => trim(implode(' · ', array_filter([data_get($docket, 'lens_details.category_name'), data_get($docket, 'lens_details.type'), data_get($docket, 'lens_details.index') ? 'index '.data_get($docket, 'lens_details.index') : null, data_get($docket, 'lens_details.brand')]))),
                            'Colour' => data_get($docket, 'lens_details.color'),
                            'Coatings' => implode(', ', array_filter(array_merge((array) data_get($docket, 'lens_details.coatings', []), [data_get($docket, 'lens_details.stock_coating')]))),
                        ]);
                        $fit = array_filter(['PD right' => data_get($docket, 'fitting.pd_right'), 'PD left' => data_get($docket, 'fitting.pd_left'), 'Fitting height' => data_get($docket, 'fitting.fitting_height'), 'Segment height' => data_get($docket, 'fitting.segment_height')], fn ($v) => filled($v));
                        $extra = array_filter(['Frame type' => data_get($docket, 'frame_structure'), 'Lab instructions' => data_get($docket, 'lab.instructions'), 'Notes' => data_get($docket, 'notes'), 'Reference' => data_get($docket, 'reference')], fn ($v) => filled($v) && ! is_array($v));
                    @endphp
                    @if($lens || $fit || $extra)
                        <section class="oo-section">
                            <h3>Lab docket</h3>
                            <dl class="oo-dl">
                                @foreach($lens as $label => $value)<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endforeach
                                @if($fit)<dt>Fitting</dt><dd>{{ collect($fit)->map(fn ($v, $k) => $k.' '.$v)->join(' · ') }}</dd>@endif
                                @foreach($extra as $label => $value)<dt>{{ $label }}</dt><dd style="white-space:pre-line">{{ $value }}</dd>@endforeach
                            </dl>
                        </section>
                    @endif
                @endif

                @if($o->lensLines->isNotEmpty() || $o->serviceLines->isNotEmpty() || (float) $o->lens_price > 0 || (float) $o->glazing_fee > 0)
                    <section class="oo-section">
                        <h3>Items</h3>
                        <table class="oo-lines">
                            <thead><tr><th>Item</th><th>Status</th><th class="oo-num">Amount</th></tr></thead>
                            <tbody>
                                @foreach($o->lensLines as $line)
                                    <tr><td>{{ strtoupper($line->eye) }} lens{{ $line->power !== null ? ' · '.number_format((float) $line->power, 2) : '' }}<span class="oo-sub">{{ $line->source === 'special_order' ? 'Special order' : 'From stock' }}</span></td><td><span class="oo-badge {{ $line->status === 'ordered' ? 'oo-b-amber' : 'oo-b-grey' }}">{{ ucfirst($line->status ?? '—') }}</span></td><td class="oo-num">{{ $line->unit_price !== null ? currency().' '.number_format((float) $line->unit_price, 2) : '' }}</td></tr>
                                @endforeach
                                @if($o->lensLines->isEmpty() && (float) $o->lens_price > 0)<tr><td>Lenses</td><td></td><td class="oo-num">{{ currency() }} {{ number_format((float) $o->lens_price, 2) }}</td></tr>@endif
                                @if((float) $o->glazing_fee > 0)<tr><td>Glazing</td><td></td><td class="oo-num">{{ currency() }} {{ number_format((float) $o->glazing_fee, 2) }}</td></tr>@endif
                                @foreach($o->serviceLines as $line)
                                    <tr><td>{{ $line->description }}@if($line->quantity > 1) × {{ $line->quantity }}@endif</td><td></td><td class="oo-num">{{ currency() }} {{ number_format((float) $line->line_total, 2) }}</td></tr>
                                @endforeach
                                @if((float) $o->discount_amount > 0)<tr><td>Discount</td><td></td><td class="oo-num">− {{ currency() }} {{ number_format((float) $o->discount_amount, 2) }}</td></tr>@endif
                            </tbody>
                        </table>
                    </section>
                @endif

                @if($isReady)
                    @php
                        $kind = $o->ready_notified_at ? \App\Services\OpticalCollectionNotifier::REMINDER : \App\Services\OpticalCollectionNotifier::READY;
                        $whatsApp = $notifier->whatsAppLink($o, $kind);
                        $phone = $notifier->recipient($o)['phone'] ?? null;
                    @endphp
                    <section class="oo-section">
                        <h3>Tell the customer</h3>
                        <div class="flex flex-wrap gap-2">
                            @if($whatsApp)<a href="{{ $whatsApp }}" target="_blank" rel="noopener" wire:click="recordPanelWhatsApp({{ $o->id }}, '{{ $kind }}')" class="oo-btn wa"><i class="fab fa-whatsapp" aria-hidden="true"></i> {{ $o->ready_notified_at ? 'WhatsApp reminder' : 'WhatsApp: glasses ready' }}</a>@endif
                            @if($smsAvailable && $notifier->smsAllowed($o))<button type="button" wire:click="sendCollectionSms({{ $o->id }}, '{{ $kind }}')" wire:confirm="Send an SMS to {{ $phone }}?" class="oo-btn"><i class="fas fa-sms" aria-hidden="true"></i> {{ $o->ready_notified_at ? 'SMS reminder' : 'SMS: glasses ready' }}</button>@endif
                            @if(! $whatsApp && ! ($smsAvailable && $notifier->smsAllowed($o)))<span class="ui-muted">No phone number on this order.</span>@endif
                        </div>
                    </section>
                @endif

                <section class="oo-section">
                    <h3>Documents</h3>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('optical.orders.docket', $o->id) }}" target="_blank" class="oo-btn">Lab docket</a>
                        <button type="button" class="oo-btn" onclick="window.print()">Print this panel</button>
                        @if($o->sale_id && $o->status !== 'Cancelled')<a href="{{ route('optical.receipt', $o->sale_id) }}" target="_blank" class="oo-btn">Receipt</a>@endif
                        @if($o->refundLog)<a href="{{ route('refunds.receipt', $o->refundLog) }}" target="_blank" class="oo-btn">Refund receipt</a>@endif
                        @if($o->status === 'Quotation')<a href="{{ route('optical.orders.create', ['quotation_id' => $o->id]) }}" class="oo-btn">Edit quote</a>@endif
                    </div>
                </section>

                @if(! in_array($o->status, ['Cancelled', 'Collected'], true))
                    <section class="oo-section" style="border-top:1px solid var(--clinic-line);padding-top:14px">
                        <h3 style="color:#b91c1c">Cancel</h3>
                        @if((float) $o->paid_amount > 0 && $o->status !== 'Quotation')
                            @if($isManager)
                                <p class="ui-muted" style="margin:0 0 8px">{{ currency() }} {{ number_format((float) $o->paid_amount, 2) }} has been paid, so the order is refunded before it is cancelled.</p>
                                <button type="button" wire:click="openRefundModal({{ $o->id }})" class="oo-btn danger">Refund &amp; cancel</button>
                            @else
                                <p class="ui-muted" style="margin:0">This order has payments. Ask a manager to refund and cancel it.</p>
                            @endif
                        @else
                            <button type="button" wire:click="confirmDeleteOrder({{ $o->id }})" class="oo-btn danger">Cancel {{ $o->status === 'Quotation' ? 'quotation' : 'order' }}</button>
                        @endif
                    </section>
                @endif
            </div>

            <footer class="oo-drawer-foot">
                @if(in_array($o->status, ['Ready for Collection', 'Ready', 'Collected'], true) && $o->work_type === 'prescription')
                    <button type="button" wire:click="remake({{ $o->id }})" class="oo-btn" title="Re-do the lenses for this order">Remake</button>
                @endif
                @if($balance > 0 && $o->sale_id && ! in_array($o->status, ['Quotation', 'Cancelled'], true))
                    <button type="button" x-on:click="openLocal($wire, { paymentOrderId: {{ $o->id }}, paymentAmount: '{{ number_format(max(0, $o->total - (float) $o->paid_amount), 2, '.', '') }}', showPaymentModal: true }, $root.querySelector('[data-payment-form]'))" class="oo-btn {{ $isReady ? 'primary' : 'warn' }}">Record payment</button>
                @endif
                @if($step && ! ($isReady && $balance > 0 && ! $onAccount))
                    <button type="button" wire:click="{{ $step[1] }}" @if($step[2]) wire:confirm="{{ $step[2] }}" @endif class="oo-btn primary" @if($awaited->isNotEmpty() && $o->status === 'Pending') disabled title="Waiting for a special-order lens" @endif>{{ $step[0] }}</button>
                @endif
            </footer>
        </aside>
    @endif

    {{-- CANCEL CONFIRMATION --}}
    @if($showDeleteOrderModal)
        <div class="oo-modal-wrap" data-sheet>
            <div class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4" role="alertdialog" aria-modal="true">
                <h3 class="text-base font-bold text-slate-900">Cancel optical order</h3>
                <p class="text-xs text-slate-600">Cancel order <strong class="text-slate-900 font-mono">{{ $orderToDeleteNumber }}</strong>? Reserved stock is returned. Paid orders need a refund first.</p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-3">
                    <button x-on:click="dismissLocal($el, $wire, { showDeleteOrderModal: false })" type="button" class="oo-btn">No, keep order</button>
                    <button wire:click="deleteOrder" type="button" class="oo-btn danger">Yes, cancel order</button>
                </div>
            </div>
        </div>
    @endif
    @if($showRefundModal)
        <div class="oo-modal-wrap" data-sheet>
            <form wire:submit.prevent="refundOrder" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4" role="dialog" aria-modal="true">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Refund &amp; cancel order {{ $refundOrderNumber }}</h3>
                    <p class="text-xs text-slate-600">{{ currency() }} {{ number_format($refundPaid, 2) }} has been paid. Refund all of it, or less to keep a cancellation fee (for example once glazing has started). Reserved frames and held lenses go back to stock; lenses already cut for this job do not.</p>
                </div>
                <div><label class="block text-xs font-semibold mb-1">Amount to refund ({{ currency() }}) *</label><input autocomplete="off" type="number" min="0.01" max="{{ $refundPaid }}" step="0.01" wire:model="refundAmount" class="ui-input w-full font-mono">
                    {{-- Worked out in the browser as the amount is typed. --}}<p class="mt-1 text-xs text-amber-800" x-data="{ paid: {{ (float) $refundPaid }} }" x-show="isNumeric($wire.refundAmount) && num($wire.refundAmount) < paid" x-cloak x-text="'Cancellation fee kept: ' + @js(currency()) + ' ' + money(paid - num($wire.refundAmount))"></p>
                    @error('refundAmount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-semibold mb-1">Reason *</label><select wire:model="refundReasonCode" class="ui-input w-full"><option value="">Choose a reason</option>@foreach(\App\Models\RefundLog::REASON_CODES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    @error('refundReasonCode')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-semibold mb-1">Details *</label><textarea wire:model="refundReason" rows="2" maxlength="500" class="ui-input w-full" placeholder="What happened and what was agreed with the customer"></textarea>
                    @error('refundReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div class="flex justify-end gap-2"><button type="button" x-on:click="dismissLocal($el, $wire, { showRefundModal: false })" class="oo-btn">Keep order</button><button type="submit" wire:confirm="Refund this payment and cancel the order? This cannot be undone." class="oo-btn danger">Refund &amp; cancel</button></div>
            </form>
        </div>
    @endif
    @if($showSendToLabModal)
        <div class="oo-modal-wrap" data-sheet>
            <form wire:submit.prevent="sendToLab" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4" role="dialog" aria-modal="true" aria-labelledby="send-lab-title">
                <h3 id="send-lab-title" class="text-base font-bold text-slate-900">Send to lab</h3>
                <div><label class="block text-xs font-semibold mb-1" for="lab-supplier">Lab</label>
                    <select id="lab-supplier" wire:model="labSupplierId" x-data x-on:change="const lead = parseInt($event.target.selectedOptions[0]?.dataset.lead, 10); const back = new Date(); back.setDate(back.getDate() + (lead || 0)); $wire.$set('expectedBack', lead ? back.toLocaleDateString('en-CA') : '', false)" class="ui-input w-full"><option value="">Not recorded</option>@foreach(\App\Models\Supplier::where('is_active', true)->orderBy('name')->get() as $lab)<option value="{{ $lab->id }}" data-lead="{{ $lab->lead_time_days }}">{{ $lab->name }}{{ $lab->lead_time_days ? ' · '.$lab->lead_time_days.' days' : '' }}</option>@endforeach</select>
                    @error('labSupplierId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-semibold mb-1" for="expected-back">Expected back</label>
                    <input autocomplete="off" id="expected-back" type="date" min="{{ today()->toDateString() }}" wire:model="expectedBack" class="ui-input w-full">
                    <p class="mt-1 text-xs text-slate-500">Filled from the lab lead time. Used for the overdue list in Job Tracking.</p>
                    @error('expectedBack')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                @error('status')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <div class="flex justify-end gap-2"><button type="button" x-on:click="dismissLocal($el, $wire, { showSendToLabModal: false })" class="oo-btn">Cancel</button><button type="submit" class="oo-btn primary">Send to lab</button></div>
            </form>
        </div>
    @endif
    @if($showConvertModal)
        @php $convertQuote = \App\Models\LensOrder::find($convertOrderId); @endphp
        <div class="oo-modal-wrap" data-sheet>
            <form wire:submit.prevent="confirmConvertQuotation" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4" role="dialog" aria-modal="true" aria-labelledby="convert-title">
                <h3 id="convert-title" class="text-base font-bold text-slate-900">Convert quotation {{ $convertQuote?->order_id }}</h3>
                @if($convertQuote)
                    <fieldset class="space-y-2"><legend class="text-xs font-semibold mb-1">@if($convertQuote->isQuoteExpired())This quotation expired on {{ $convertQuote->quote_valid_until->format('d M Y') }}. @endif Charge:</legend>
                        <label class="flex items-center gap-2 text-sm"><input type="radio" wire:model="convertPricing" value="keep"> The quoted prices ({{ currency() }} {{ number_format($convertQuote->total, 2) }})</label>
                        <label class="flex items-center gap-2 text-sm"><input type="radio" wire:model="convertPricing" value="reprice"> Current prices for catalogue items and services</label>
                        @error('convertPricing')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                        @error('quote')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </fieldset>
                @endif
                <div><label class="block text-xs font-semibold mb-1" for="convert-deposit">Deposit ({{ currency() }})</label><input autocomplete="off" id="convert-deposit" type="number" min="0" step="0.01" wire:model="convertDeposit" class="ui-input w-full">
                    @error('convertDeposit')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('paid_amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-semibold mb-1" for="convert-method">Method</label><select id="convert-method" wire:model="convertMethod" class="ui-input w-full">@foreach(\App\Support\PaymentMethods::active(\App\Support\PaymentMethods::OPTICAL) as $methodKey => $methodLabel)<option value="{{ $methodKey }}">{{ $methodLabel }}</option>@endforeach</select></div>
                @error('order')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <div class="flex justify-end gap-2"><button type="button" x-on:click="dismissLocal($el, $wire, { showConvertModal: false })" class="oo-btn">Cancel</button><button type="submit" class="oo-btn primary">Convert to order</button></div>
            </form>
        </div>
    @endif
    {{-- Always in the page: Take payment opens it in the browser (openLocal); only Record payment calls the server. --}}
        <div class="oo-modal-wrap" data-sheet data-keep data-payment-form x-show="$wire.showPaymentModal" x-cloak>
            <form wire:submit.prevent="recordPayment" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4" role="dialog" aria-modal="true">
                <h3 class="text-base font-bold text-slate-900">Record order payment</h3>
                <div><label class="block text-xs font-semibold mb-1">Amount ({{ currency() }})</label><input autocomplete="off" type="number" min="0.01" step="0.01" wire:model="paymentAmount" class="ui-input w-full">@error('paymentAmount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-semibold mb-1">Method</label><select wire:model="paymentMethod" class="ui-input w-full">@foreach(\App\Support\PaymentMethods::active(\App\Support\PaymentMethods::OPTICAL) as $methodKey => $methodLabel)<option value="{{ $methodKey }}">{{ $methodLabel }}</option>@endforeach</select></div>
                <div class="flex justify-end gap-2"><button type="button" x-on:click="dismissLocal($el, $wire, { showPaymentModal: false })" class="oo-btn">Cancel</button><button type="submit" class="oo-btn primary">Record payment</button></div>
            </form>
        </div>
