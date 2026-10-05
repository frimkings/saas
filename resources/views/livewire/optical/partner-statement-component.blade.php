<div class="clinic-ui ui-page space-y-5">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <a wire:navigate href="{{ route('optical.partners') }}" class="text-xs text-teal-700 underline">← Partner clinics</a>
            <h1 class="text-xl font-bold text-slate-900">{{ $partner->name }} · account</h1>
            <p class="ui-muted text-xs">{{ $partner->billing_terms === 'on_account' ? 'On account' : 'Pay on order' }}@if($partner->contact_person) · {{ $partner->contact_person }}@endif @if($partner->messagingPhone()) · {{ $partner->messagingPhone() }}@endif</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($openOrders->isNotEmpty())<button type="button" wire:click="openPaymentForm" class="ui-button ui-button-primary">Record payment</button>@endif
            <a href="{{ route('optical.partners.statement.print', ['partner' => $partner->id, 'from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()]) }}" target="_blank" class="ui-button">Print statement</a>
            @if($whatsApp)<a href="{{ $whatsApp }}" target="_blank" rel="noopener" class="ui-button"><i class="fab fa-whatsapp" aria-hidden="true"></i> Send summary</a>@endif
        </div>
    </div>

    <x-ui.flash />
    @if($errors->any())<div class="ui-panel p-3 text-red-700" role="alert">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="ui-panel p-4 col-span-2 lg:col-span-1"><p class="text-xs font-semibold uppercase text-slate-500">Balance owed</p><p class="text-2xl font-bold font-mono {{ $balance > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ currency() }} {{ number_format($balance, 2) }}</p></div>
        @foreach(\App\Services\OpticalPartnerAccountService::AGING as $key => $label)
            <div class="ui-panel p-4"><p class="text-xs font-semibold uppercase text-slate-500">{{ $label }}</p><p class="text-lg font-bold font-mono {{ $aging[$key] > 0 && in_array($key, ['61_90', '90_plus'], true) ? 'text-red-700' : 'text-slate-900' }}">{{ number_format($aging[$key], 2) }}</p></div>
        @endforeach
    </div>

    @if($showPaymentForm)
        <form data-sheet wire:submit.prevent="recordPayment" class="ui-panel p-5 space-y-4" aria-label="Record partner payment">
            <h2 class="text-sm font-semibold text-slate-900">Record a payment from {{ $partner->name }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label class="text-xs font-semibold">Amount received ({{ currency() }}) *<input autocomplete="off" type="number" min="0.01" step="0.01" max="{{ $balance }}" wire:model.live.debounce.400ms="paymentAmount" class="ui-input mt-1 w-full font-mono"><span class="font-normal text-slate-500">Owed: {{ currency() }} {{ number_format($balance, 2) }}</span></label>
                <label class="text-xs font-semibold">Method *<select wire:model="paymentMethod" class="ui-input mt-1 w-full">@foreach(\App\Models\OpticalPartnerPayment::METHODS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label class="text-xs font-semibold">Reference<input autocomplete="off" type="text" wire:model="paymentReference" maxlength="100" placeholder="Bank / MoMo transaction ID" class="ui-input mt-1 w-full"></label>
            </div>
            <label class="block text-xs font-semibold">Notes<input autocomplete="off" type="text" wire:model="paymentNotes" maxlength="1000" class="ui-input mt-1 w-full"></label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="allocateManually"> Choose which jobs this pays (otherwise oldest jobs are settled first)</label>
            @if($allocateManually)
                <table class="w-full text-xs">
                    <thead class="text-slate-500"><tr><th class="py-1 text-left">Job</th><th class="py-1 text-left">Date</th><th class="py-1 text-right">Owed</th><th class="py-1 text-right">Apply ({{ currency() }})</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($openOrders as $order)
                            <tr wire:key="alloc-{{ $order->id }}">
                                <td class="py-1">{{ $order->order_id }}@if($order->customer_name) · {{ $order->customer_name }}@endif @if($order->partnerReference()) · ref {{ $order->partnerReference() }}@endif</td>
                                <td class="py-1">{{ $order->created_at->format('d M Y') }}</td>
                                <td class="py-1 text-right font-mono">{{ number_format($service->balanceOf($order), 2) }}</td>
                                <td class="py-1 text-right"><input autocomplete="off" type="number" min="0" step="0.01" max="{{ $service->balanceOf($order) }}" wire:model.live.debounce.400ms="allocations.{{ $order->id }}" class="ui-input w-24 text-right" aria-label="Apply to {{ $order->order_id }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                    @php $applied = collect($allocations)->sum(fn ($v) => (float) $v); @endphp
                    <tfoot><tr><th colspan="3" class="py-1 text-right">Applied</th><th class="py-1 text-right font-mono {{ abs($applied - (float) $paymentAmount) > 0.001 ? 'text-red-700' : 'text-emerald-700' }}">{{ number_format($applied, 2) }} of {{ number_format((float) $paymentAmount, 2) }}</th></tr></tfoot>
                </table>
            @endif
            <div class="flex gap-2">
                <button type="submit" wire:confirm="Record this payment against {{ $partner->name }}'s jobs?" class="ui-button ui-button-primary">Record payment</button>
                <button type="button" x-on:click="dismissLocal($el, $wire, { showPaymentForm: false })" class="ui-button ui-button-secondary">Cancel</button>
            </div>
        </form>
    @endif

    <section class="ui-panel p-5 space-y-3" aria-label="Jobs owed">
        <h2 class="text-sm font-semibold text-slate-900">Jobs owed ({{ $openOrders->count() }})</h2>
        @if($openOrders->isEmpty())
            <p class="ui-muted text-sm">Nothing owed. The account is settled.</p>
        @else
            <table class="w-full text-xs">
                <thead class="text-slate-500"><tr><th class="py-1 text-left">Job</th><th class="py-1 text-left">Date</th><th class="py-1 text-left">Status</th><th class="py-1 text-right">Total</th><th class="py-1 text-right">Paid</th><th class="py-1 text-right">Owed</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($openOrders as $order)
                        <tr><td class="py-1 font-mono">{{ $order->order_id }}<span class="font-sans text-slate-500">@if($order->customer_name) · {{ $order->customer_name }}@endif @if($order->partnerReference()) · ref {{ $order->partnerReference() }}@endif</span></td>
                            <td class="py-1">{{ $order->created_at->format('d M Y') }}</td><td class="py-1">{{ $order->status }}</td>
                            <td class="py-1 text-right font-mono">{{ number_format($order->total, 2) }}</td><td class="py-1 text-right font-mono">{{ number_format((float) $order->paid_amount, 2) }}</td>
                            <td class="py-1 text-right font-mono font-semibold text-red-700">{{ number_format($service->balanceOf($order), 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="ui-panel p-5 space-y-3" aria-label="Statement">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 class="text-sm font-semibold text-slate-900">Statement</h2>
            <div class="flex gap-2">
                <x-date-range from="from" to="to" presets="finance" align="right" label="Period" />
            </div>
        </div>
        <div class="overflow-x-auto">@include('livewire.optical.partials.partner-statement-table')</div>
    </section>

    <section class="ui-panel p-5 space-y-3" aria-label="Payments received">
        <h2 class="text-sm font-semibold text-slate-900">Payments received</h2>
        @if($payments->isEmpty())
            <p class="ui-muted text-sm">No partner payments recorded yet.</p>
        @else
            <table class="w-full text-xs">
                <thead class="text-slate-500"><tr><th class="py-1 text-left">Receipt</th><th class="py-1 text-left">Date</th><th class="py-1 text-left">Method</th><th class="py-1 text-left">Jobs settled</th><th class="py-1 text-right">Amount</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($payments as $payment)
                        <tr><td class="py-1 font-mono">{{ $payment->receipt_number }}</td><td class="py-1">{{ $payment->created_at->format('d M Y') }} · {{ $payment->receiver?->name }}</td>
                            <td class="py-1">{{ \App\Models\OpticalPartnerPayment::METHODS[$payment->payment_method] ?? $payment->payment_method }}@if($payment->reference) · {{ $payment->reference }}@endif</td>
                            <td class="py-1">{{ $payment->allocations->map(fn ($a) => $a->order?->order_id.' ('.number_format((float) $a->amount, 2).')')->implode(', ') }}</td>
                            <td class="py-1 text-right font-mono">{{ number_format((float) $payment->amount, 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
