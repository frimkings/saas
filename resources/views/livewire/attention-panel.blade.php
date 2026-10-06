<div class="{{ $page ? 'clinic-ui ui-page' : '' }}">
    <style>
        .att-card{background:#fff;border:1px solid #e3e8ef;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);margin-bottom:16px}
        .att-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-bottom:1px solid #eef1f5}
        .att-head h5{margin:0;font-size:15px;font-weight:700;color:#1e293b}
        .att-badge{display:inline-block;min-width:22px;padding:1px 7px;border-radius:999px;background:#fee2e2;color:#b91c1c;font-size:12px;font-weight:700;text-align:center}
        .att-group{padding:10px 16px 4px}
        .att-group + .att-group{border-top:1px dashed #e8edf3}
        .att-group h6{margin:0;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#475569}
        .att-group .att-hint{font-size:12px;color:#64748b;margin:2px 0 6px}
        .att-item{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid #f3f5f8}
        .att-item:first-of-type{border-top:0}
        .att-title{font-weight:700;font-size:13px;color:#0f172a;overflow-wrap:anywhere}
        .att-detail{font-size:12px;color:#64748b}
        .att-actions{display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end;flex:none}
        .att-actions a,.att-actions button{font-size:12px;padding:3px 8px;border-radius:6px;border:1px solid #d6dee8;background:#fff;color:#334155;text-decoration:none;cursor:pointer;white-space:nowrap}
        .att-actions a:hover,.att-actions button:hover{background:#f1f5f9}
        .att-actions .att-done{border-color:#86efac;color:#166534}
        .att-actions .att-remove{border-color:#fca5a5;color:#b91c1c}
        .att-stale{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:10px 16px;border-top:1px solid #eef1f5;background:#f8fafc;border-radius:0 0 10px 10px;font-size:13px;color:#475569}
        .att-stale a{font-weight:600}
        .att-note{display:flex;gap:6px;margin:4px 0 8px}
        .att-note input{flex:1;min-width:0;font-size:13px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px}
        .att-note button{font-size:12px;padding:4px 10px;border-radius:6px;border:1px solid #2563eb;background:#2563eb;color:#fff;cursor:pointer}
        .att-note button.att-cancel{background:#fff;color:#334155;border-color:#cbd5e1}
        .att-empty{padding:18px 16px;color:#64748b;font-size:13px}
        .att-more{display:block;font-size:12px;padding:2px 0 10px}
        .rot-red{color:#b91c1c}
        @media(max-width:560px){.att-item{flex-direction:column}.att-actions{justify-content:flex-start}}
    </style>

    @if($page)
        <h4 class="mb-1 font-semibold">Needs attention</h4>
        <p class="text-slate-500 text-sm mb-4">What to act on today. Everyone in the {{ $line === 'optical' ? 'shop' : 'clinic' }} sees this list; marking an item done or snoozing it clears it for the whole team.</p>
    @endif

    <div class="att-card">
        <div class="att-head">
            <h5><i class="fas fa-bell text-amber-600 mr-1"></i> Needs attention @if($total)<span class="att-badge">{{ $total }}</span>@endif</h5>
            @if($compact && $total)<a href="{{ $allUrl }}" class="text-sm">See all &rarr;</a>@endif
        </div>

        @forelse($groups as $rule => $group)
            <div class="att-group" wire:key="att-g-{{ $rule }}">
                <h6>{{ $group['title'] }} <span class="text-slate-500 font-normal">({{ $group['items']->count() }})</span></h6>
                <div class="att-hint">{{ $group['hint'] }}</div>

                @foreach($perGroup ? $group['items']->take($perGroup) : $group['items'] as $item)
                    @php
                        $key = $item['rule'].'|'.$item['type'].'|'.$item['id'];
                        $stock = str_starts_with($rule, 'stock_');
                        $args = "'{$item['rule']}', '{$item['type']}', {$item['id']}";
                    @endphp
                    <div class="att-item" wire:key="att-{{ $key }}">
                        <div style="min-width:0">
                            <div class="att-title">{{ $item['title'] }}</div>
                            <div class="att-detail {{ in_array($rule, ['order_late', 'appt_missed', 'stock_expired'], true) ? 'rot-red' : '' }}">{{ $item['detail'] }}</div>
                        </div>
                        <div class="att-actions">
                            @if($item['phone'])<a href="tel:{{ preg_replace('/[^\d+]/', '', $item['phone']) }}" title="Call {{ $item['phone'] }}"><i class="fas fa-phone"></i> Call</a>@endif
                            @if($item['url'])<a href="{{ $item['url'] }}">Open</a>@endif
                            @if($rule !== 'stock_expired')
                                <button type="button" class="att-done" wire:click="start({{ $args }}, 'done')" @if($stock) title="Noted: hide it until it gets closer to expiry" @endif>{{ $stock ? 'Noted' : 'Done' }}</button>
                            @endif
                            <button type="button" wire:click="start({{ $args }}, 'snoozed')" title="Hide until tomorrow">Snooze</button>
                            @if($stock && $canWriteOff)
                                <button type="button" class="att-remove" wire:click="start({{ $args }}, 'writeoff')" title="Take what is left of this batch out of stock">Remove from stock</button>
                            @endif
                        </div>
                    </div>
                    @if($acting && str_starts_with($acting, $key.'|'))
                        @php $mode = \Illuminate\Support\Str::afterLast($acting, '|'); @endphp
                        <form class="att-note" wire:submit.prevent="confirm">
                            <input type="text" wire:model="note" maxlength="500" autofocus placeholder="{{ match ($mode) {
                                'done' => $stock ? 'e.g. Moved to the front, selling first (optional)' : 'What happened? e.g. Called, coming Friday (optional)',
                                'writeoff' => 'e.g. Disposed of, returned to supplier (optional)',
                                default => 'Why snooze? (optional)',
                            } }}">
                            <button type="submit" @if($mode === 'writeoff') style="background:#b91c1c;border-color:#b91c1c" @endif>{{ match ($mode) {
                                'done' => $stock ? 'Mark noted' : 'Mark done',
                                'writeoff' => 'Remove ' . ($item['quantity'] ?? '') . ' from stock',
                                default => 'Snooze till tomorrow',
                            } }}</button>
                            <button type="button" class="att-cancel" wire:click="cancel">Cancel</button>
                        </form>
                    @endif
                @endforeach

                @if($perGroup && $group['items']->count() > $perGroup)
                    <a href="{{ $allUrl }}" class="att-more">+ {{ $group['items']->count() - $perGroup }} more</a>
                @endif
            </div>
        @empty
            <div class="att-empty"><i class="fas fa-check-circle text-green-700 mr-1"></i> Nothing needs attention right now.</div>
        @endforelse

        @if($stale)
            <div class="att-stale">
                <span><i class="fas fa-broom mr-1"></i> <strong>{{ $stale }}</strong> older open {{ \Illuminate\Support\Str::plural('order', $stale) }}, more than {{ $staleDays }} days late or not collected, no longer chased here.</span>
                <a href="{{ $tidyUrl }}">Tidy up &rarr;</a>
            </div>
        @endif
    </div>
</div>
