<div class="space-y-5">
    <x-ui.flash />
    <style>
        .sd-steps{display:flex;gap:6px;flex-wrap:wrap}
        .sd-step{flex:1;min-width:90px;text-align:center;font-size:11px;font-weight:700;border-radius:8px;padding:7px 6px;background:#f1f5f9;color:#64748b}
        .sd-step.done{background:#d1fae5;color:#065f46}.sd-step.now{background:#e6f6f5;color:#0f5f63;box-shadow:inset 0 0 0 2px #0f5f63}
        .sd-pick{border:1px solid var(--clinic-line);border-radius:9px;overflow:hidden}
        .sd-pick button{display:flex;width:100%;justify-content:space-between;gap:8px;padding:8px 10px;border:0;border-bottom:1px solid var(--clinic-line);background:#fff;text-align:left;font-size:13px;cursor:pointer}
        .sd-pick button:hover{background:#f6fafa}.sd-pick button:last-child{border-bottom:0}
        .sd-scanbtns{display:flex;flex-wrap:wrap;gap:6px}
        .sd-line{display:grid;grid-template-columns:minmax(0,1fr) 150px 110px 30px;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--clinic-line);font-size:13px}
        .sd-pay{display:grid;grid-template-columns:minmax(0,1fr) 140px 30px;gap:8px;align-items:center;margin-bottom:6px}
        .sd-radio{display:flex;flex-wrap:wrap;gap:6px}
        .sd-radio label{display:flex;align-items:center;gap:6px;border:1px solid var(--clinic-line);border-radius:8px;padding:7px 10px;font-size:13px;cursor:pointer}
        .sd-radio label.on{border-color:var(--clinic-accent);background:#e8f4f3}
        @media(max-width:600px){.sd-line{grid-template-columns:minmax(0,1fr) 110px 30px}.sd-line .sd-price{display:none}}
    </style>

    <div class="ds-tiles" data-tour="scan-desk-tiles">
        <div class="ds-tile"><span>Orders today</span><b>{{ $counts['today'] }}</b></div>
        <div class="ds-tile"><span>To bill</span><b style="color:{{ $counts['unbilled'] ? '#92400e' : 'inherit' }}">{{ $counts['unbilled'] }}</b></div>
        <div class="ds-tile"><span>To scan</span><b>{{ $counts['to_scan'] }}</b></div>
        <div class="ds-tile"><span>To report</span><b>{{ $counts['to_report'] }}</b></div>
    </div>

    <section class="ui-panel bg-white">
        <div class="oo-toolbar" style="grid-template-columns:minmax(220px,1fr) 170px auto auto">
            <input autocomplete="off" type="search" wire:model.live.debounce.250ms="search" placeholder="Search order, patient, number or phone…" class="ui-input text-sm" aria-label="Search scan orders">
            <select wire:model.live="sourceFilter" class="ui-input text-sm" aria-label="Referred by"><option value="">Anyone referred</option>@foreach(\App\Models\ScanOrder::SOURCES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            <span class="ui-muted" wire:loading.delay wire:target="search,sourceFilter,setStage">Updating…</span>
            @if($scans->isNotEmpty())<button type="button" wire:click="add" data-tour="scan-new-order" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ New scan order</button>@else<span class="ui-muted text-sm">Add scans to the catalogue first.</span>@endif
        </div>
        <div class="oo-chips" data-tour="scan-stages" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter by stage">
            @foreach(\App\Livewire\Scans\ScanDeskComponent::STAGES as $key => $label)
                <button type="button" wire:click="setStage('{{ $key }}')" class="oo-chip {{ $stage === $key ? 'active' : '' }}" aria-pressed="{{ $stage === $key ? 'true' : 'false' }}">{{ $label }}@isset($counts[$key])<b>{{ $counts[$key] }}</b>@endisset</button>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="oo-table w-full" style="min-width:960px">
                <colgroup><col style="width:140px"><col style="width:190px"><col style="width:auto"><col style="width:190px"><col style="width:130px"><col style="width:120px"></colgroup>
                <thead><tr><th>Order</th><th>Patient</th><th>Scans</th><th>Referred by</th><th class="oo-num">Bill</th><th>Stage</th></tr></thead>
                <tbody>
                    @forelse($orders as $order)
                        @php $pay = $order->paymentState(); @endphp
                        <tr wire:key="so-{{ $order->id }}" class="{{ $viewOrderId === $order->id ? 'oo-active' : '' }}" wire:click="openOrder({{ $order->id }})">
                            <td><span class="oo-id">{{ $order->order_number }}</span><span class="oo-sub">{{ $order->created_at?->format('d M, H:i') }}</span></td>
                            <td><b>{{ $order->patient?->name }}</b><span class="oo-sub">{{ $order->patient?->pxnumber }}</span></td>
                            <td>{{ $order->items->map->label()->implode(', ') }}</td>
                            <td>{{ $order->referrerLabel() }}@if($order->source === 'partner' && $order->partner)<span class="oo-sub">{{ $order->partner->code }}</span>@endif</td>
                            <td class="oo-num">{{ currency() }} {{ number_format($order->total_amount, 2) }}
                                <span class="oo-sub">@switch($pay)@case('paid')<span style="color:#065f46">Paid</span>@break @case('partial')<span style="color:#b91c1c">Owes {{ number_format(max(0, $order->sale->total_amount - $order->sale->amount_paid), 2) }}</span>@break @case('unpaid')<span style="color:#b91c1c">Unpaid</span>@break @default<span style="color:#92400e">Not billed</span>@endswitch</span></td>
                            <td>
                                @php $badge = ['registered' => $order->sale_id ? ['To scan', 'oo-b-blue'] : ['To bill', 'oo-b-amber'], 'scanned' => ['To report', 'oo-b-purple'], 'reported' => ['Reported', 'oo-b-green'], 'sent' => ['Results sent', 'oo-b-teal'], 'cancelled' => ['Cancelled', 'oo-b-grey']][$order->status] ?? [$order->status, 'oo-b-grey']; @endphp
                                <span class="oo-badge {{ $badge[1] }}">{{ $badge[0] }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">{{ $search || $sourceFilter || ! in_array($stage, ['open', 'all'], true) ? 'No scan orders match these filters.' : 'No open scan orders. New scan order registers a patient\'s scans.' }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $orders->links() }}</div>
    </section>

    {{-- ORDER PANEL --}}
    @if($viewOrder && ! $showForm)
        @php
            $pay = $viewOrder->paymentState();
            $billed = (bool) $viewOrder->sale_id;
            $owed = $billed ? max(0, (float) $viewOrder->sale->total_amount - (float) $viewOrder->sale->amount_paid - (float) $viewOrder->sale->insurer_amount) : (float) $viewOrder->total_amount;
            $flow = ['billed' => 'Billed', 'scanned' => 'Scanned', 'reported' => 'Reported'];
            $reached = ['billed' => $billed, 'scanned' => in_array($viewOrder->status, ['scanned', 'reported', 'sent'], true), 'reported' => in_array($viewOrder->status, ['reported', 'sent'], true)];
            $next = $viewOrder->status === 'cancelled' ? null : collect($reached)->search(false);
        @endphp
        <div class="oo-overlay" wire:click="closeOrder" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="sd-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.closeOrder()" wire:key="sd-drawer-{{ $viewOrder->id }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="sd-title">{{ $viewOrder->patient?->name }}</h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                        <span class="oo-id">{{ $viewOrder->order_number }}</span>
                        <span>{{ $viewOrder->patient?->pxnumber }}</span>
                        @if($viewOrder->status === 'cancelled')<span class="oo-badge oo-b-grey">Cancelled</span>@endif
                    </div>
                </div>
                <button type="button" class="oo-x" wire:click="closeOrder" aria-label="Close">&times;</button>
            </header>
            <div class="oo-drawer-body">
                @if($viewOrder->status !== 'cancelled')
                    <div class="sd-steps" aria-label="Progress">
                        @foreach($flow as $key => $label)
                            <span class="sd-step {{ $reached[$key] ? 'done' : ($next === $key ? 'now' : '') }}">{{ $reached[$key] ? '✓ ' : '' }}{{ $label }}</span>
                        @endforeach
                    </div>
                @else
                    <div class="oo-note amber">Cancelled {{ $viewOrder->cancelled_at?->format('d M Y, H:i') }}: {{ $viewOrder->cancel_reason }}</div>
                @endif
                @error('status')<div class="oo-note red">{{ $message }}</div>@enderror

                <section class="oo-section">
                    <h3>Scans</h3>
                    <table class="oo-lines">
                        <tbody>
                            @foreach($viewOrder->items as $item)
                                <tr><td>{{ $item->label() }}</td><td class="oo-num" style="width:130px">{{ $item->quantity > 1 ? $item->quantity.' × ' : '' }}{{ number_format($item->unit_price, 2) }}</td><td class="oo-num" style="width:110px"><b>{{ number_format($item->line_total, 2) }}</b></td></tr>
                            @endforeach
                            <tr><td colspan="2"><b>Total</b></td><td class="oo-num"><b>{{ currency() }} {{ number_format($viewOrder->total_amount, 2) }}</b></td></tr>
                        </tbody>
                    </table>
                </section>

                <section class="oo-section">
                    <h3>Referral</h3>
                    <dl class="oo-dl">
                        <dt>Referred by</dt><dd>{{ $viewOrder->referrerLabel() }}@if($viewOrder->partner) <span class="oo-id">{{ $viewOrder->partner->code }}</span>@endif</dd>
                        @if($viewOrder->referrer_note)<dt>Referral note</dt><dd>{{ $viewOrder->referrer_note }}</dd>@endif
                        @if($viewOrder->source === 'partner')
                            <dt>Share results</dt><dd>@if($viewOrder->share_consent)<span class="oo-badge oo-b-green">Patient agreed</span> <span class="ui-muted text-xs">{{ $viewOrder->consent_at?->format('d M Y, H:i') }}</span>@else<span class="oo-badge oo-b-grey">Not agreed</span> <span class="ui-muted text-xs">results stay with the patient</span>@endif</dd>
                        @endif
                        @if($viewOrder->notes)<dt>Notes</dt><dd style="white-space:pre-line">{{ $viewOrder->notes }}</dd>@endif
                        <dt>Registered</dt><dd>{{ $viewOrder->created_at?->format('d M Y, H:i') }} by {{ $viewOrder->creator?->name ?? '—' }}</dd>
                        @if($viewOrder->scanned_at)<dt>Scanned</dt><dd>{{ $viewOrder->scanned_at->format('d M Y, H:i') }}</dd>@endif
                        @if($viewOrder->reported_at)<dt>Reported</dt><dd>{{ $viewOrder->reported_at->format('d M Y, H:i') }}</dd>@endif
                    </dl>
                </section>

                <section class="oo-section">
                    <h3>Payment</h3>
                    @if($billed)
                        <div class="oo-money">
                            <div><span>Bill</span><b>{{ number_format($viewOrder->sale->total_amount, 2) }}</b></div>
                            <div><span>Paid</span><b>{{ number_format($viewOrder->sale->amount_paid, 2) }}</b></div>
                            <div><span>Owed</span><b style="color:{{ $owed > 0 ? '#b91c1c' : 'inherit' }}">{{ number_format($owed, 2) }}</b></div>
                        </div>
                        <div class="flex flex-wrap gap-2 mt-2.5">
                            <a href="{{ route('cashier.receipt.show', $viewOrder->sale_id) }}" target="_blank" class="oo-btn">Receipt</a>
                            @if($pay !== 'paid' && \Illuminate\Support\Facades\Route::has('cashier.outstanding-balances'))<a wire:navigate href="{{ route('cashier.outstanding-balances') }}" class="oo-btn">Collect the balance</a>@endif
                        </div>
                    @elseif($viewOrder->status !== 'cancelled')
                        <form wire:submit="checkout">
                            @foreach($payments as $i => $payment)
                                <div class="sd-pay" wire:key="sd-pay-{{ $i }}">
                                    <select wire:model="payments.{{ $i }}.method" class="ui-input" aria-label="Payment method">@foreach($methods as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                                    <input type="number" step="0.01" min="0" wire:model.blur="payments.{{ $i }}.amount" class="ui-input" aria-label="Amount">
                                    @if(count($payments) > 1)<button type="button" class="oo-x" wire:click="removePayment({{ $i }})" aria-label="Remove payment">&times;</button>@else<span></span>@endif
                                </div>
                            @endforeach
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <button type="button" class="oo-link" wire:click="addPayment">+ Split payment</button>
                                <span class="text-sm">Paying {{ currency() }} {{ number_format($paymentTotal, 2) }} of {{ number_format($viewOrder->total_amount, 2) }}
                                    @if($paymentTotal > 0 && $paymentTotal < (float) $viewOrder->total_amount - 0.004)<span class="oo-badge oo-b-amber">Part payment: {{ number_format((float) $viewOrder->total_amount - $paymentTotal, 2) }} owed</span>@endif</span>
                            </div>
                            @error('payments')<small class="ds-err">{{ $message }}</small>@enderror
                        </form>
                    @endif
                </section>

                @if(! $billed && $viewOrder->status !== 'cancelled')
                    <section class="oo-section" style="border-top:1px solid var(--clinic-line);padding-top:14px">
                        <h3>Cancel order</h3>
                        <div class="flex gap-2 items-start">
                            <input type="text" wire:model="cancelReason" class="ui-input" placeholder="Reason, e.g. patient left" style="flex:1" aria-label="Reason for cancelling">
                            <button type="button" class="oo-btn danger" wire:click="cancelOrder" wire:confirm="Cancel {{ $viewOrder->order_number }}?">Cancel order</button>
                        </div>
                        @error('cancelReason')<small class="ds-err">{{ $message }}</small>@enderror
                    </section>
                @endif
            </div>
            <footer class="oo-drawer-foot">
                @if(in_array($viewOrder->status, ['scanned', 'reported'], true))<button type="button" class="oo-btn" wire:click="undoStep" wire:confirm="Undo the last step?">Undo last step</button>@endif
                <button type="button" class="oo-btn" wire:click="closeOrder">Close</button>
                @if(! $billed && $viewOrder->status !== 'cancelled')
                    <button type="button" class="oo-btn primary" wire:click="checkout" wire:loading.attr="disabled" wire:target="checkout">Take payment</button>
                @elseif($viewOrder->status === 'registered')
                    <button type="button" class="oo-btn primary" wire:click="markScanned">Mark scanned</button>
                @elseif($viewOrder->status === 'scanned')
                    <button type="button" class="oo-btn primary" wire:click="markReported">Mark reported</button>
                @endif
            </footer>
        </aside>
    @endif

    {{-- NEW ORDER PANEL --}}
    @if($showForm)
        <div class="oo-overlay" wire:click="cancelForm" aria-hidden="true"></div>
        <aside class="oo-drawer" role="dialog" aria-modal="true" aria-labelledby="sd-form-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.cancelForm()">
            <header class="oo-drawer-head">
                <div><h2 id="sd-form-title">New scan order</h2></div>
                <button type="button" class="oo-x" wire:click="cancelForm" aria-label="Close">&times;</button>
            </header>
            <form wire:submit="save" class="oo-drawer-body" id="sd-form">
                <section class="oo-section">
                    <h3>Patient</h3>
                    @if($pickedPatient)
                        <div class="flex items-center justify-between gap-2" style="border:1px solid var(--clinic-line);border-radius:9px;padding:9px 11px">
                            <span><b>{{ $pickedPatient->name }}</b> <span class="ui-muted text-xs">· {{ $pickedPatient->pxnumber }} · {{ $pickedPatient->contact }}</span></span>
                            <button type="button" class="oo-link" wire:click="clearPatient">Change</button>
                        </div>
                    @elseif($newPatient)
                        <div class="ds-2">
                            <label class="ds-field full"><span>Full name *</span><input autocomplete="off" type="text" wire:model="newName" class="ui-input">@error('newName')<small class="ds-err">{{ $message }}</small>@enderror</label>
                            <label class="ds-field"><span>Phone *</span><input autocomplete="off" type="tel" wire:model="newPhone" class="ui-input">@error('newPhone')<small class="ds-err">{{ $message }}</small>@enderror</label>
                            <label class="ds-field"><span>Sex *</span><select wire:model="newGender" class="ui-input"><option value="">Choose…</option><option>Male</option><option>Female</option><option>Other</option></select>@error('newGender')<small class="ds-err">{{ $message }}</small>@enderror</label>
                            <label class="ds-field"><span>Date of birth</span><input type="date" wire:model="newDob" class="ui-input" max="{{ now()->toDateString() }}">@error('newDob')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        </div>
                        <p class="ds-hint" style="margin:6px 0 0">Registers them with a patient number so their results stay on file. <button type="button" class="oo-link" wire:click="$set('newPatient', false)">Search instead</button></p>
                    @else
                        <input autocomplete="off" type="search" wire:model.live.debounce.250ms="patientSearch" class="ui-input w-full" placeholder="Search name, patient number or phone…" aria-label="Search patients">
                        @if($patientResults->isNotEmpty())
                            <div class="sd-pick mt-2">
                                @foreach($patientResults as $patient)
                                    <button type="button" wire:click="pickPatient({{ $patient->id }})" wire:key="sd-pt-{{ $patient->id }}"><span><b>{{ $patient->name }}</b> <span class="ui-muted text-xs">{{ $patient->pxnumber }}</span></span><span class="ui-muted text-xs">{{ $patient->contact }}</span></button>
                                @endforeach
                            </div>
                        @elseif(mb_strlen(trim($patientSearch)) >= 2)
                            <p class="ui-muted text-sm" style="margin:8px 0 0">No patient found.</p>
                        @endif
                        <button type="button" class="oo-link" style="margin-top:6px" wire:click="startNewPatient">+ New patient</button>
                    @endif
                    @error('patientId')<small class="ds-err">{{ $message }}</small>@enderror
                </section>

                <section class="oo-section">
                    <h3>Referred by</h3>
                    <div class="sd-radio" role="radiogroup">
                        @foreach(\App\Models\ScanOrder::SOURCES as $key => $label)
                            <label class="{{ $source === $key ? 'on' : '' }}"><input type="radio" wire:model.live="source" value="{{ $key }}"> {{ $label }}</label>
                        @endforeach
                    </div>
                    @error('source')<small class="ds-err">{{ $message }}</small>@enderror
                    @if($source === 'partner')
                        <div class="ds-2 mt-2.5">
                            <label class="ds-field"><span>Partner clinic *</span>
                                <select wire:model.live="partnerId" class="ui-input"><option value="">Choose…</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->code }} · {{ $partner->name }}</option>@endforeach</select>
                                @error('partnerId')<small class="ds-err">{{ $message }}</small>@enderror
                                @if($partners->isEmpty())<small class="ds-hint">No partner clinics yet: a manager adds them on the Partner Clinics tab.</small>@endif
                            </label>
                            <label class="ds-field"><span>Doctor</span>
                                <select wire:model="partnerDoctorId" class="ui-input" @disabled(! $partnerId)><option value="">{{ $partnerDoctors->isEmpty() ? 'None listed' : 'Not given' }}</option>@foreach($partnerDoctors as $doctor)<option value="{{ $doctor->id }}">{{ $doctor->name }}</option>@endforeach</select>
                                @error('partnerDoctorId')<small class="ds-err">{{ $message }}</small>@enderror
                            </label>
                            <label class="ds-field full"><span>Referral note</span><input autocomplete="off" type="text" wire:model="referrerNote" class="ui-input" placeholder="Letter reference or the reason given">@error('referrerNote')<small class="ds-err">{{ $message }}</small>@enderror</label>
                        </div>
                        <label class="flex items-start gap-2 text-sm mt-2.5" style="background:#f8fafc;border:1px solid var(--clinic-line);border-radius:9px;padding:10px 12px">
                            <input type="checkbox" wire:model="shareConsent" style="margin-top:3px">
                            <span><b>Patient consents to sharing results with the referring clinic.</b><br><span class="ds-hint">Ask the patient. Without it, results are given to the patient only.</span></span>
                        </label>
                    @elseif($source === 'internal')
                        <label class="ds-field mt-2.5"><span>Ordered by *</span>
                            <select wire:model="orderedBy" class="ui-input"><option value="">Choose the doctor…</option>@foreach($doctors as $doctor)<option value="{{ $doctor->id }}">{{ $doctor->name }}</option>@endforeach</select>
                            @error('orderedBy')<small class="ds-err">{{ $message }}</small>@enderror
                        </label>
                    @endif
                </section>

                <section class="oo-section">
                    <h3>Scans</h3>
                    <div class="sd-scanbtns">
                        @foreach($scans as $scan)
                            <button type="button" class="oo-btn" wire:click="addLine({{ $scan->id }})" wire:key="sd-add-{{ $scan->id }}">+ {{ $scan->name }}</button>
                        @endforeach
                    </div>
                    @if($lines)
                        <div class="mt-2.5">
                            @foreach($lines as $i => $line)
                                @php $scan = $scanMap[$line['diagnostic_scan_id']] ?? null; @endphp
                                <div class="sd-line" wire:key="sd-line-{{ $i }}">
                                    <span><b>{{ $scan?->name }}</b></span>
                                    @if($scan?->per_eye)
                                        <select wire:model.live="lines.{{ $i }}.eye" class="ui-input" aria-label="Eye">@foreach(\App\Models\ScanOrderItem::EYES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                                    @else<span class="ui-muted text-xs">—</span>@endif
                                    <span class="oo-num sd-price">{{ number_format((float) ($scan?->price ?? 0) * (($line['eye'] ?? null) === 'OU' ? 2 : 1), 2) }}</span>
                                    <button type="button" class="oo-x" wire:click="removeLine({{ $i }})" aria-label="Remove {{ $scan?->name }}">&times;</button>
                                </div>
                            @endforeach
                            <div class="flex justify-between" style="padding:8px 0;font-size:13px"><b>Total</b><b>{{ currency() }} {{ number_format($formTotal, 2) }}</b></div>
                        </div>
                    @endif
                    @error('items')<small class="ds-err">{{ $message }}</small>@enderror
                </section>

                <label class="ds-field"><span>Notes</span><textarea wire:model="notes" rows="2" class="ui-input" placeholder="Anything the technician should know"></textarea></label>
            </form>
            <footer class="oo-drawer-foot">
                <button type="button" class="oo-btn" wire:click="cancelForm">Cancel</button>
                <button type="submit" form="sd-form" class="oo-btn primary" wire:loading.attr="disabled" wire:target="save">Register order</button>
            </footer>
        </aside>
    @endif
</div>
