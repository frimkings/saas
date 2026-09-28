{{-- Every waiting job is on the page; the filters hide rows in the browser (awaitingFilter, resources/js/live-totals.js). --}}
<div class="clinic-ui ui-page space-y-5" x-data="awaitingFilter()">
    @include('livewire.optical.partials.order-ui')
    {{-- Title, key figures and SMS status share one row so the job list starts near the top. --}}
    <div class="ui-heading flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Awaiting Collection</h1>
            <p class="ui-muted text-xs">Ready glasses not yet collected. Partner jobs are reported to the partner, not its patients.</p>
        </div>
        <dl class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
            <div><dt class="font-semibold uppercase text-slate-500">Waiting</dt><dd class="text-lg font-bold text-slate-900" x-text="count()">{{ $orders->count() }}</dd></div>
            <div><dt class="font-semibold uppercase text-slate-500">Balance still owed</dt><dd class="text-lg font-bold font-mono" :class="balance() > 0 ? 'text-red-700' : 'text-slate-900'">{{ currency() }} <span x-text="money(balance())">{{ number_format($balanceHeld, 2) }}</span></dd></div>
            <div><dt class="font-semibold uppercase text-slate-500">Longest wait</dt><dd class="text-lg font-bold text-slate-900" x-text="longest() === null ? '—' : longest() + ' days'">{{ $orders->isEmpty() ? '—' : $orders->first()->daysAwaitingCollection().' days' }}</dd></div>
            <div x-data="{ open: false }" class="relative" x-on:click.outside="open = false">
                <button type="button" x-on:click="open = ! open" :aria-expanded="open" class="rounded-full border px-3 py-1 font-semibold {{ $sms['available'] ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-amber-300 bg-amber-50 text-amber-800' }}">
                    {{ $sms['available'] ? 'SMS active' : 'SMS not available' }} ▾
                </button>
                <div x-show="open" x-cloak role="status" class="absolute right-0 z-20 mt-2 w-72 rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700 shadow-lg">
                    @if($sms['available'])
                        @if($sms['credits'] !== null){{ number_format($sms['credits']) }} credits left. @endif
                        @if($schedule)
                            Pickup reminders go out automatically on day {{ implode(', ', $schedule) }} after the glasses are ready.
                        @else
                            Automatic pickup reminders are off (Optical Settings).
                        @endif
                    @else
                        {{ $sms['reason'] }} WhatsApp buttons still work.
                    @endif
                </div>
            </div>
        </dl>
    </div>

    <x-ui.flash />
    @if($errors->any() && ! $viewOrderId)<div class="ui-panel p-3 text-red-700" role="alert">{{ $errors->first() }}</div>@endif

    @if($partners->isNotEmpty())
        <details class="ui-panel px-4 py-2" aria-label="Partner clinics with jobs waiting">
            <summary class="cursor-pointer py-1 text-sm"><span class="font-semibold text-slate-900">Partner clinics</span> <span class="text-xs text-slate-500">· {{ $partners->count() }} {{ \Illuminate\Support\Str::plural('partner', $partners->count()) }}, {{ $partners->sum('count') }} {{ \Illuminate\Support\Str::plural('job', $partners->sum('count')) }} waiting · one reminder per partner lists all its jobs</span></summary>
            <div class="divide-y divide-slate-100">
                @foreach($partners as $row)
                    @php $partner = $row['partner']; $link = $notifier->partnerWhatsAppLink($partner); @endphp
                    <div wire:key="partner-{{ $partner->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <div>
                            <span class="font-semibold text-slate-900">{{ $partner->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $row['count'] }} {{ \Illuminate\Support\Str::plural('job', $row['count']) }} · oldest {{ $row['oldest'] }} days · {{ $partner->messagingPhone() ?: 'no phone' }} · {{ \App\Models\OpticalPartnerClinic::NOTIFY_VIA[$partner->notify_via ?: 'sms'] ?? 'SMS' }}@if($row['balance'] > 0) · {{ currency() }} {{ number_format($row['balance'], 2) }} owed @endif</span>
                        </div>
                        <div class="flex gap-1.5">
                            @if($link)
                                <a href="{{ $link }}" target="_blank" rel="noopener" wire:click="recordPartnerWhatsApp({{ $partner->id }})" class="px-2.5 py-1 text-[11px] font-semibold rounded border border-emerald-300 bg-emerald-50 text-emerald-800"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp all</a>
                            @endif
                            @if($sms['available'] && $partner->notify_via === 'sms' && $partner->messagingPhone())
                                <button type="button" wire:click="sendPartnerSms({{ $partner->id }})" wire:confirm="Send one SMS to {{ $partner->name }} listing its {{ $row['count'] }} waiting jobs?" class="px-2.5 py-1 text-[11px] font-semibold rounded border border-teal-300 bg-teal-50 text-teal-800"><i class="fas fa-sms" aria-hidden="true"></i> SMS all</button>
                            @endif
                            @if($partner->notify_via === 'none')<span class="text-xs text-slate-400">Asked not to be notified</span>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-xs font-semibold">Search<input autocomplete="off" type="search" x-model="search" placeholder="Order, customer, partner or phone" class="ui-input mt-1 w-64"></label>
        <label class="text-xs font-semibold">Waiting at least
            <select x-model="minDays" class="ui-input mt-1">
                <option value="0">Any time</option><option value="7">7 days</option><option value="14">14 days</option><option value="30">30 days</option><option value="60">60 days</option>
            </select>
        </label>
        @if($partners->isNotEmpty())
            <label class="text-xs font-semibold">Customer
                <select x-model="partner" class="ui-input mt-1">
                    <option value="">Everyone</option><option value="own">Own customers</option>
                    @foreach($partners as $row)<option value="{{ $row['partner']->id }}">{{ $row['partner']->name }}</option>@endforeach
                </select>
            </label>
        @endif
    </div>

    <div class="ui-panel overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr><th class="p-3 text-left">Order</th><th class="p-3 text-left">Notify</th><th class="p-3 text-left">Waiting</th><th class="p-3 text-right">Balance</th><th class="p-3 text-left">Told</th><th class="p-3 text-right">Remind</th><th class="p-3 text-right">Hand over</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($orders as $ord)
                    @php
                        $recipient = $notifier->recipient($ord);
                        $m = $money[$ord->id];
                        $balance = $m['balance'];
                        $days = $ord->daysAwaitingCollection();
                        $kind = $ord->ready_notified_at ? \App\Services\OpticalCollectionNotifier::REMINDER : \App\Services\OpticalCollectionNotifier::READY;
                        $whatsApp = $notifier->whatsAppLink($ord, $kind);
                    @endphp
                    <tr wire:key="awaiting-{{ $ord->id }}" x-show="shows($el)" data-days="{{ $days }}" data-balance="{{ $balance }}" data-partner="{{ $ord->isPartnerJob() ? $ord->partner_clinic_id : 'own' }}" data-search="{{ mb_strtolower(implode(' ', [$ord->order_id, $ord->customer_name, $ord->customer_phone, $ord->patient?->name, $ord->patient?->contact, $ord->partnerClinic?->name])) }}" class="cursor-pointer hover:bg-slate-50 {{ $viewOrderId === $ord->id ? 'oo-active' : '' }}" wire:click="openOrder({{ $ord->id }})">
                        <td class="p-3"><span class="font-mono font-bold text-teal-800">{{ $ord->order_id }}</span>@if($ord->isPartnerJob())<span class="block text-xs text-slate-500">Wearer: {{ $ord->customer_name ?: '—' }}@if($ord->partnerReference()) · ref {{ $ord->partnerReference() }}@endif</span>@endif</td>
                        <td class="p-3">
                            <span class="font-medium text-slate-900">{{ $recipient['name'] ?? $ord->display_customer_name }}</span>
                            @if($ord->isPartnerJob())<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">Partner</span>@endif
                            <span class="block text-xs text-slate-500">{{ $recipient['phone'] ?? 'No phone' }}</span>
                        </td>
                        <td class="p-3"><span class="font-semibold {{ $days >= 30 ? 'text-red-700' : ($days >= 7 ? 'text-amber-700' : 'text-slate-700') }}">{{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }}</span><span class="block text-xs text-slate-500">since {{ ($ord->ready_at ?? $ord->updated_at)?->format('d M Y') }}</span></td>
                        <td class="p-3 text-right font-mono {{ $balance > 0 ? 'text-red-700 font-semibold' : 'text-emerald-700' }}">{{ $balance > 0 ? currency().' '.number_format($balance, 2) : 'Paid' }}@if($m['clinicBill'])<span class="block font-sans text-[10px] text-slate-500">clinic bill</span>@endif</td>
                        <td class="p-3 text-xs text-slate-600">
                            {{ $ord->ready_notified_at ? 'Ready message '.$ord->ready_notified_at->format('d M') : 'Not told yet' }}
                            @if($ord->collection_reminders_sent > 0)<span class="block">{{ $ord->collection_reminders_sent }} {{ \Illuminate\Support\Str::plural('reminder', $ord->collection_reminders_sent) }} · last {{ $ord->last_collection_reminder_at?->format('d M') }}</span>@endif
                        </td>
                        <td class="p-3" onclick="event.stopPropagation()">
                            <div class="flex justify-end gap-1.5">
                                @php $covers = $ord->isPartnerJob() && $kind === \App\Services\OpticalCollectionNotifier::REMINDER ? ' (all partner jobs)' : ''; @endphp
                                @if($whatsApp)
                                    <a href="{{ $whatsApp }}" target="_blank" rel="noopener" wire:click="recordWhatsApp({{ $ord->id }}, '{{ $kind }}')" title="Open WhatsApp with the {{ $kind === 'spectacles_ready' ? 'ready' : 'reminder' }} message typed in{{ $covers }}" class="px-2.5 py-1 text-[11px] font-semibold rounded border border-emerald-300 bg-emerald-50 text-emerald-800"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp</a>
                                @endif
                                @if($sms['available'] && $notifier->smsAllowed($ord))
                                    <button type="button" wire:click="sendSms({{ $ord->id }}, '{{ $kind }}')" wire:loading.attr="disabled" wire:confirm="Send an SMS to {{ $recipient['phone'] }}{{ $covers }}?" class="px-2.5 py-1 text-[11px] font-semibold rounded border border-teal-300 bg-teal-50 text-teal-800"><i class="fas fa-sms" aria-hidden="true"></i> SMS</button>
                                @endif
                                @if(($recipient['notify_via'] ?? 'sms') === 'none')<span class="text-xs text-slate-400">Partner asked not to be notified</span>
                                @elseif(! $whatsApp && ! ($recipient['phone'] ?? null))<span class="text-xs text-slate-400">No phone on order</span>@endif
                            </div>
                        </td>
                        <td class="p-3" onclick="event.stopPropagation()">
                            <div class="flex justify-end gap-1.5">
                                @if($balance > 0 && ! $m['onAccount'])
                                    @if($m['canTakePayment'])<button type="button" x-on:click="openLocal($wire, { paymentOrderId: {{ $ord->id }}, paymentAmount: '{{ number_format(max(0, $ord->total - (float) $ord->paid_amount), 2, '.', '') }}', showPaymentModal: true }, $root.querySelector('[data-payment-form]'))" class="oo-btn warn">Take payment</button>
                                    @else<span class="oo-btn warn" style="cursor:default" title="Paid on the patient's clinic bill at reception or the cashier.">Pay at clinic</span>@endif
                                @else
                                    <button type="button" wire:click="updateStatus({{ $ord->id }}, 'Collected')" wire:confirm="Hand the glasses to the customer and close this order?" class="oo-btn primary">Mark collected</button>
                                @endif
                                <button type="button" wire:click="openOrder({{ $ord->id }})" class="oo-btn">View</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ui-empty p-8 text-center"><p class="ui-muted text-sm">No glasses are waiting for collection.</p></td></tr>
                @endforelse
                @if($orders->isNotEmpty())<tr x-show="count() === 0" x-cloak><td colspan="7" class="ui-empty p-8 text-center"><p class="ui-muted text-sm">No waiting glasses match these filters.</p></td></tr>@endif
            </tbody>
        </table>
    </div>

    @include('livewire.optical.partials.order-panel')
</div>
