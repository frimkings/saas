<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .sc-field{display:flex;flex-direction:column;gap:4px}.sc-field>span{font-size:11px;font-weight:700;color:var(--clinic-muted)}.sc-field .ui-input{padding:8px 10px}
        .sc-progress{height:6px;background:#e5eef0;border-radius:6px;overflow:hidden;margin-top:4px}.sc-progress i{display:block;height:100%;background:var(--clinic-accent)}
    </style>
    @php $statusClass = ['counting' => 'oo-b-teal', 'submitted' => 'oo-b-amber', 'approved' => 'oo-b-green', 'cancelled' => 'oo-b-grey']; @endphp
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Stock Counts</h1>
            <p class="ui-muted text-xs">Count what is on the shelf, review the differences, and approve them into stock. Expected quantities are fixed when a count starts.</p>
        </div>
        @if($isManager)<button type="button" wire:click="openStartForm" class="oo-btn primary" style="padding:9px 16px;font-size:13px">+ Start a count</button>@endif
    </div>

    @if(session()->has('success') && ! $count)<div class="p-3 bg-teal-50 border border-teal-200 text-teal-800 rounded-lg text-xs font-semibold" role="status">{{ session('success') }}</div>@endif
    @if($errors->any() && ! $count && ! $showStartForm)<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif

    @if($showStartForm)
        <div class="oo-overlay" wire:click="$set('showStartForm', false)" aria-hidden="true"></div>
        <aside class="oo-drawer" style="width:min(520px,100vw)" role="dialog" aria-modal="true" aria-labelledby="sc-start-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.set('showStartForm', false)">
            <header class="oo-drawer-head"><h2 id="sc-start-title">Start a stock count</h2><button type="button" class="oo-x" wire:click="$set('showStartForm', false)" aria-label="Close">&times;</button></header>
            <form wire:submit.prevent="startCount" id="sc-start" class="oo-drawer-body">
                <label class="sc-field"><span>What to count *</span><select wire:model.live="countScope" class="ui-input">@foreach(\App\Services\OpticalStockCountService::SCOPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                @if($countScope === 'lens_range')
                    <label class="sc-field"><span>Lens range *</span><select wire:model="countRange" class="ui-input"><option value="">Choose a range</option>@foreach($lensRanges as $i => $specs)<option value="{{ $i }}">{{ $rangeTitle($specs) }}</option>@endforeach</select>@error('countRange')<small style="color:#b91c1c">{{ $message }}</small>@enderror</label>
                @endif
                <label class="sc-field"><span>Notes</span><input type="text" wire:model="countNotes" maxlength="1000" class="ui-input" placeholder="e.g. Month-end count, drawers A–C"></label>
                <div class="oo-note amber">Count at a quiet time. Expected quantities are fixed when the count starts; sales and glazing during the count are flagged for rechecking.</div>
            </form>
            <footer class="oo-drawer-foot"><button type="button" wire:click="$set('showStartForm', false)" class="oo-btn">Cancel</button><button type="submit" form="sc-start" class="oo-btn primary">Start count</button></footer>
        </aside>
    @endif

    @if($count)
        @php
            $counted = $count->lines->whereNotNull('counted_quantity');
            $varianceLines = $counted->filter(fn ($line) => $line->variance() !== 0);
            $varianceValue = $varianceLines->sum(fn ($line) => $line->variance() * (float) $line->unit_cost);
            $editable = $count->status === 'counting';
            $showExpected = $showExpected || ! $editable;
        @endphp
        <div class="oo-overlay" wire:click="closeCount" aria-hidden="true"></div>
        <aside class="oo-drawer" style="width:min(1000px,100vw)" role="dialog" aria-modal="true" aria-labelledby="sc-title" tabindex="-1" x-data x-init="$el.focus()" x-on:keydown.escape.window="$wire.closeCount()" wire:key="sc-drawer-{{ $count->id }}">
            <header class="oo-drawer-head">
                <div>
                    <h2 id="sc-title"><span class="oo-id" style="font-size:17px">{{ $count->count_number }}</span> · {{ $count->title }}</h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs ui-muted">
                        <span class="oo-badge {{ $statusClass[$count->status] ?? 'oo-b-grey' }}">{{ \App\Models\OpticalStockCount::STATUSES[$count->status] }}</span>
                        <span>Started {{ $count->created_at->format('d M Y H:i') }} by {{ $count->creator?->name ?? '—' }} · {{ $counted->count() }} of {{ $count->lines->count() }} counted</span>
                        @if($count->approved_at)<span>· approved {{ $count->approved_at->format('d M Y') }} by {{ $count->approver?->name }}</span>@endif
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @if($editable && $isManager)<button type="button" wire:click="toggleExpected" class="oo-btn">{{ $showExpected ? 'Hide' : 'Show' }} expected</button>@endif
                    <button type="button" class="oo-x" wire:click="closeCount" aria-label="Close">&times;</button>
                </div>
            </header>
            <div class="oo-drawer-body">
            @if(session()->has('success'))<div class="p-3 bg-teal-50 border border-teal-200 text-teal-800 rounded-lg text-xs font-semibold" role="status">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="oo-note red" role="alert">{{ $errors->first() }}</div>@endif
            @if($count->notes)<p class="ui-muted text-sm" style="margin:0">{{ $count->notes }}</p>@endif

            @if($moved->isNotEmpty())
                <p class="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900" role="alert">{{ $moved->count() }} {{ \Illuminate\Support\Str::plural('item', $moved->count()) }} moved (sold, glazed or received) since this count started, marked ⚠ below. Recount them before approving.</p>
            @endif

            @if($editable)
                <p class="text-xs text-slate-600">Enter the number of pieces on the shelf. Leave a cell blank if you did not count it; blank cells are not changed.</p>
            @endif

            @if($grid)
                <div class="overflow-auto max-h-[32rem] rounded-lg border">
                    <table class="text-xs border-collapse">
                        <thead class="sticky top-0 bg-slate-50"><tr><th class="p-2">SPH / {{ $grid['powerLabel'] }}</th>@foreach($grid['powers'] as $power)<th class="p-2">{{ sprintf('%+.2f', $power) }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach($grid['spheres'] as $sphere)
                                <tr><th class="p-2 bg-slate-50">{{ sprintf('%+.2f', $sphere) }}</th>
                                    @foreach($grid['powers'] as $power)
                                        @php $line = $grid['cells'][sprintf('%.2f|%.2f', $sphere, $power)] ?? null; @endphp
                                        <td class="border p-1 align-top {{ $line && $line->variance() ? ($line->variance() < 0 ? 'bg-red-50' : 'bg-amber-50') : '' }}">
                                            @if($line)
                                                @if($editable)<input type="number" min="0" max="100000" wire:model="counts.{{ $line->id }}" class="ui-input text-center" style="width:58px" aria-label="SPH {{ $sphere }} {{ $grid['powerLabel'] }} {{ $power }} counted">@else<span class="block text-center font-semibold">{{ $line->counted_quantity ?? '—' }}</span>@endif
                                                @if($showExpected)<span class="block text-center text-[10px] text-slate-500">exp {{ $line->expectedAtCount() }}@if($line->variance()) · <strong class="{{ $line->variance() < 0 ? 'text-red-700' : 'text-amber-700' }}">{{ sprintf('%+d', $line->variance()) }}</strong>@endif</span>@endif
                                                @if($moved->has($line->optical_product_id))<span class="block text-center text-[10px] text-amber-800" title="Moved {{ sprintf('%+d', $moved[$line->optical_product_id]) }} since the count started">⚠</span>@endif
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="overflow-x-auto max-h-[32rem] rounded-lg border">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-slate-50 text-slate-500"><tr><th class="p-2 text-left">Item</th>@if($showExpected)<th class="p-2 text-right">Expected</th>@endif<th class="p-2 text-right">Counted</th>@if($showExpected)<th class="p-2 text-right">Difference</th>@endif</tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($count->lines as $line)
                                <tr wire:key="count-line-{{ $line->id }}" class="{{ $line->variance() ? ($line->variance() < 0 ? 'bg-red-50' : 'bg-amber-50') : '' }}">
                                    <td class="p-2">{{ $line->product?->sku }} · {{ $line->product?->name }}@if($moved->has($line->optical_product_id)) <span class="text-amber-800" title="Moved since the count started">⚠</span>@endif</td>
                                    @if($showExpected)<td class="p-2 text-right">{{ $line->expectedAtCount() }}</td>@endif
                                    <td class="p-2 text-right">@if($editable)<input type="number" min="0" max="100000" wire:model="counts.{{ $line->id }}" class="ui-input w-20 text-right" aria-label="Counted">@else{{ $line->counted_quantity ?? '—' }}@endif</td>
                                    @if($showExpected)<td class="p-2 text-right font-semibold {{ ($line->variance() ?? 0) < 0 ? 'text-red-700' : 'text-amber-700' }}">{{ $line->variance() ? sprintf('%+d', $line->variance()) : '' }}</td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if(in_array($count->status, ['submitted', 'approved'], true))
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs space-y-1">
                    <p><strong>{{ $varianceLines->count() }}</strong> {{ \Illuminate\Support\Str::plural('difference', $varianceLines->count()) }}: {{ $varianceLines->filter(fn ($l) => $l->variance() < 0)->sum(fn ($l) => -$l->variance()) }} missing, {{ $varianceLines->filter(fn ($l) => $l->variance() > 0)->sum(fn ($l) => $l->variance()) }} extra · value at cost <strong class="{{ $varianceValue < 0 ? 'text-red-700' : '' }}">{{ currency() }} {{ number_format($varianceValue, 2) }}</strong></p>
                    <p>{{ $count->lines->count() - $counted->count() }} {{ \Illuminate\Support\Str::plural('item', $count->lines->count() - $counted->count()) }} not counted {{ $count->status === 'approved' ? 'were' : 'will be' }} left unchanged.</p>
                </div>
            @endif

            </div>
            <footer class="oo-drawer-foot">
                @if(in_array($count->status, ['counting', 'submitted'], true) && $isManager)
                    <button type="button" wire:click="cancel" wire:confirm="Cancel this count? No stock will change." class="oo-btn danger" style="margin-right:auto">Cancel count</button>
                @endif
                @if($editable)
                    <button type="button" wire:click="saveCounts(false)" class="oo-btn">Save progress</button>
                    <button type="button" wire:click="saveCounts(true)" wire:confirm="Submit this count for approval? You can't change it afterwards unless a manager sends it back." class="oo-btn primary">Submit for approval</button>
                @endif
                @if($count->status === 'submitted' && $isManager)
                    <button type="button" wire:click="reopen" class="oo-btn">Send back for recount</button>
                    <button type="button" wire:click="approve" wire:confirm="Correct stock for every counted difference? This posts count corrections to the ledger." class="oo-btn primary">Approve &amp; correct stock</button>
                @endif
                @if(! in_array($count->status, ['counting', 'submitted'], true))<button type="button" wire:click="closeCount" class="oo-btn">Close</button>@endif
            </footer>
        </aside>
    @endif

    <section class="ui-panel bg-white">
        <div class="overflow-x-auto">
            <table class="oo-table w-full" style="min-width:760px">
                <thead><tr><th>Count</th><th>What</th><th>Status</th><th style="width:200px">Progress</th><th style="text-align:right;width:120px">Actions</th></tr></thead>
                <tbody>
                    @forelse($countsList as $item)
                        @php $pct = $item->lines_count ? round($item->counted_lines / $item->lines_count * 100) : 0; @endphp
                        <tr wire:key="count-{{ $item->id }}" class="{{ $viewCountId === $item->id ? 'oo-active' : '' }}" wire:click="openCount({{ $item->id }})">
                            <td><span class="oo-id">{{ $item->count_number }}</span><span class="oo-sub">{{ $item->created_at->format('d M Y') }}</span></td>
                            <td><b>{{ $item->title }}</b></td>
                            <td><span class="oo-badge {{ $statusClass[$item->status] ?? 'oo-b-grey' }}">{{ \App\Models\OpticalStockCount::STATUSES[$item->status] }}</span></td>
                            <td>{{ $item->counted_lines }} / {{ $item->lines_count }} counted<div class="sc-progress" aria-hidden="true"><i style="width:{{ $pct }}%"></i></div></td>
                            <td style="text-align:right" onclick="event.stopPropagation()"><button type="button" wire:click="openCount({{ $item->id }})" class="oo-btn {{ in_array($item->status, ['counting', 'submitted'], true) ? 'primary' : '' }}">{{ $item->status === 'counting' ? 'Count' : ($item->status === 'submitted' ? 'Review' : 'View') }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-empty"><p class="ui-muted text-sm">No stock counts yet.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $countsList->links() }}</div>
    </section>
</div>
