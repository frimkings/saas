<div class="clinic-ui ui-page space-y-4">
    @include('livewire.optical.partials.order-ui')
    <div class="ui-heading">
        <div>
            <h1>Job Tracking</h1>
            <p class="ui-muted">Open jobs that need following up, worst first. Each job is listed once with every reason. Lab turnaround is in <a class="underline" wire:navigate href="{{ route('optical.reports') }}#turnaround">Reports</a>.</p>
        </div>
    </div>

    <x-ui.flash :link="session('toastLink')" link-label="Tell the customer on WhatsApp" link-new-tab />
    @if($errors->any() && ! $viewOrderId && ! $rescheduleOrder && ! $abandonOrder)<div class="ui-panel p-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif

    <section class="ui-panel" aria-label="Jobs needing attention">
        <div class="oo-chips" style="padding:12px 16px;border-bottom:1px solid var(--clinic-line)" role="group" aria-label="Filter jobs">
            @foreach(\App\Services\OpticalJobTrackingService::FILTERS as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" class="oo-chip {{ $filter === $key ? 'active' : '' }}" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }}<b>{{ $counts[$key] }}</b></button>
            @endforeach
        </div>

        @forelse($groups as $bucket => $group)
            <details class="border-b border-slate-100 last:border-0" @if($bucket !== 'old' || $groups->count() === 1) open @endif wire:key="bucket-{{ $bucket }}-{{ $filter }}">
                <summary class="cursor-pointer bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
                    {{ $group['label'] }} behind · {{ $group['rows']->count() }} {{ \Illuminate\Support\Str::plural('job', $group['rows']->count()) }}
                    @if($bucket === 'old')<span class="ml-1 font-normal normal-case tracking-normal text-slate-500">Often abandoned, or finished but never updated. Close or update them to keep this list short.</span>@endif
                </summary>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-slate-500"><tr><th class="p-3 text-left">Job</th><th class="p-3 text-left">Customer</th><th class="p-3 text-left">Status</th><th class="p-3 text-left">Why it is listed</th><th class="p-3 text-right">Actions</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach($group['rows'] as $row)
                            @php
                                $order = $row['order'];
                                $customerLink = $tracking->customerDelayLink($order);
                                $labLink = $tracking->labChaseLink($order);
                                $phone = $order->isPartnerJob() ? $order->partnerClinic?->messagingPhone() : $order->display_customer_phone;
                            @endphp
                            <tr wire:key="job-{{ $order->id }}" class="align-top">
                                <td class="p-3 text-xs font-mono"><button type="button" wire:click="openOrder({{ $order->id }})" class="text-teal-800 underline">{{ $order->order_id }}</button></td>
                                <td class="p-3 text-xs">{{ $order->display_customer_name }}@if($phone)<span class="block text-slate-500">{{ $phone }}</span>@endif</td>
                                <td class="p-3 text-xs">{{ \App\Support\Optical\OrderPresenter::badge($order->status)[0] }}@if($order->labSupplier)<span class="block text-slate-500">{{ $order->labSupplier->name }}</span>@endif</td>
                                <td class="p-3 text-xs">
                                    <div class="flex flex-wrap gap-1">
                                        @if($row['late'])<span class="oo-badge oo-b-red" title="{{ $row['late']['reason'] }}, due {{ $row['late']['due']->format('d M Y') }}">{{ in_array('lab', $row['filters'], true) ? 'Lab' : 'Pickup' }} {{ $row['late']['days'] }} {{ \Illuminate\Support\Str::plural('day', $row['late']['days']) }} late</span>@endif
                                        @if($row['stuck'] !== null)<span class="oo-badge oo-b-amber" title="Status unchanged for over {{ $stuckDays }} {{ \Illuminate\Support\Str::plural('day', $stuckDays) }} (Settings)">No change for {{ $row['stuck'] }} {{ \Illuminate\Support\Str::plural('day', $row['stuck']) }}</span>@endif
                                        @if($row['held']->isNotEmpty())<span class="oo-badge oo-b-blue" title="Stock lenses held for this job cannot be sold">Holding {{ $row['held']->map(fn ($line) => strtoupper($line->eye))->implode(' + ') }} {{ \Illuminate\Support\Str::plural('lens', $row['held']->count()) }}</span>@endif
                                    </div>
                                    @if($row['late'])<p class="mt-1 text-slate-500">{{ $row['late']['reason'] }} · due {{ $row['late']['due']->format('d M Y') }}</p>@endif
                                </td>
                                <td class="p-3">
                                    <div class="flex flex-wrap justify-end gap-1.5">
                                        @if($customerLink)<a href="{{ $customerLink }}" target="_blank" rel="noopener" class="oo-btn" title="WhatsApp the {{ $order->isPartnerJob() ? 'partner clinic' : 'customer' }} about the delay"><i class="fab fa-whatsapp" aria-hidden="true"></i> {{ $order->isPartnerJob() ? 'Partner' : 'Customer' }}</a>@endif
                                        @if($labLink)<a href="{{ $labLink }}" target="_blank" rel="noopener" class="oo-btn" title="Ask {{ $order->labSupplier->name }} when the job will be back"><i class="fab fa-whatsapp" aria-hidden="true"></i> Chase lab</a>@endif
                                        <button type="button" wire:click="openReschedule({{ $order->id }})" class="oo-btn">New date</button>
                                        {{-- The menu is moved to <body> and pinned to the button: the table's scroll box would otherwise clip it. --}}
                                        <div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                            <button type="button" x-ref="more" class="oo-btn" x-on:click="open = ! open" :aria-expanded="open" aria-haspopup="menu" aria-label="More actions for {{ $order->order_id }}">More ▾</button>
                                            <template x-teleport="body">
                                            <div x-show="open" x-cloak x-anchor.bottom-end.offset.4="$refs.more" x-on:click.outside="if (! $refs.more.contains($event.target)) open = false" role="menu"
                                                 class="clinic-ui z-[1050] w-56 rounded-lg border border-slate-200 bg-white p-1 text-left text-xs shadow-lg">
                                                <button type="button" role="menuitem" wire:click="openOrder({{ $order->id }})" x-on:click="open = false" class="block w-full rounded px-3 py-2 text-left hover:bg-slate-50">Open order (move status, payment)</button>
                                                @if($phone)<a role="menuitem" x-on:click="open = false" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="block rounded px-3 py-2 hover:bg-slate-50">Call {{ $phone }}</a>@endif
                                                @if($isManager && $row['held']->isNotEmpty() && $order->status === 'Pending')
                                                    <button type="button" role="menuitem" wire:click="releaseLenses({{ $order->id }})" x-on:click="open = false" wire:confirm="Release the lenses held for {{ $order->order_id }} so they can be sold? The job takes a lens again at glazing if one is free." class="block w-full rounded px-3 py-2 text-left hover:bg-slate-50">Release held lenses</button>
                                                @endif
                                                @if($isManager)
                                                    <button type="button" role="menuitem" wire:click="openAbandon({{ $order->id }})" x-on:click="open = false" class="block w-full rounded px-3 py-2 text-left text-red-700 hover:bg-red-50">Close as abandoned…</button>
                                                @endif
                                            </div>
                                            </template>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @empty
            <p class="p-8 text-center ui-muted text-sm">{{ $filter === 'all' ? 'Nothing needs following up. Every open job is on time and moving.' : 'No jobs match this filter.' }}</p>
        @endforelse
    </section>

    @if($rescheduleOrder)
        <div class="oo-modal-wrap" data-sheet>
            <form wire:submit="saveReschedule" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4" role="dialog" aria-modal="true" aria-labelledby="reschedule-title">
                <div>
                    <h3 id="reschedule-title" class="text-base font-bold text-slate-900">New date for {{ $rescheduleOrder->order_id }}</h3>
                    <p class="text-xs text-slate-500">Currently promised for {{ $rescheduleOrder->pickUpDate ? \Illuminate\Support\Carbon::parse($rescheduleOrder->pickUpDate)->format('d M Y') : 'no date' }}. The reason is kept in the audit trail.</p>
                </div>
                <label class="block text-xs font-semibold">New pickup date promised to the customer
                    <input type="date" min="{{ today()->toDateString() }}" wire:model="newPickupDate" class="ui-input mt-1 w-full">
                    @error('newPickupDate')<span class="mt-1 block text-red-600">{{ $message }}</span>@enderror</label>
                @if($rescheduleOrder->status === 'Sent to Lab')
                    <label class="block text-xs font-semibold">New date back from {{ $rescheduleOrder->labSupplier?->name ?? 'the lab' }}
                        <input type="date" min="{{ today()->toDateString() }}" wire:model="newLabDate" class="ui-input mt-1 w-full">
                        @error('newLabDate')<span class="mt-1 block text-red-600">{{ $message }}</span>@enderror</label>
                @endif
                <label class="block text-xs font-semibold">Reason
                    <input type="text" maxlength="500" wire:model="rescheduleReason" placeholder="e.g. Lens back-ordered at the lab" class="ui-input mt-1 w-full">
                    @error('rescheduleReason')<span class="mt-1 block text-red-600">{{ $message }}</span>@enderror</label>
                <p class="text-xs text-slate-500">After saving you can send the customer the new date on WhatsApp.</p>
                <div class="flex justify-end gap-2"><button type="button" x-on:click="dismissLocal($el, $wire, { rescheduleOrderId: null, abandonOrderId: null })" class="oo-btn">Cancel</button><button type="submit" class="oo-btn primary">Save new date</button></div>
            </form>
        </div>
    @endif

    @if($abandonOrder)
        <div class="oo-modal-wrap" data-sheet>
            <form wire:submit="confirmAbandon" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4" role="dialog" aria-modal="true" aria-labelledby="abandon-title">
                <div>
                    <h3 id="abandon-title" class="text-base font-bold text-slate-900">Close {{ $abandonOrder->order_id }} as abandoned</h3>
                    <p class="text-xs text-slate-500">For a job the customer is not coming back for. It is cancelled and its stock goes back; lenses already cut are written off.</p>
                </div>
                <div class="oo-note {{ (float) $abandonOrder->paid_amount > 0 ? 'amber' : '' }} text-xs">
                    @if((float) $abandonOrder->paid_amount > 0)
                        The deposit of <b>{{ currency() }} {{ number_format((float) $abandonOrder->paid_amount, 2) }}</b> is kept as a cancellation fee. To give money back instead, open the order and use Refund &amp; cancel.
                    @else
                        Nothing has been paid on this job.
                    @endif
                </div>
                <label class="block text-xs font-semibold">Reason
                    <textarea wire:model="abandonReason" rows="2" maxlength="500" placeholder="e.g. Customer unreachable since June; three calls, two WhatsApp messages" class="ui-input mt-1 w-full"></textarea>
                    @error('abandonReason')<span class="mt-1 block text-red-600">{{ $message }}</span>@enderror</label>
                <div class="flex justify-end gap-2"><button type="button" x-on:click="dismissLocal($el, $wire, { rescheduleOrderId: null, abandonOrderId: null })" class="oo-btn">Go back</button><button type="submit" class="oo-btn" style="background:#b91c1c;border-color:#b91c1c;color:#fff">Close job</button></div>
            </form>
        </div>
    @endif

    @include('livewire.optical.partials.order-panel')
</div>
