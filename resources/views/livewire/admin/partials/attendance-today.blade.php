{{--
    The Today tab: who is in, not in yet, due later, out and away, as counts. With departments, one row
    each; clicking a row slides its people open right under it. Clicking a count lists those people.
--}}
@php
    $states = [
        'in' => ['In now', 'bg-emerald-50 text-emerald-800', ['in']],
        'missing' => ['Not in yet', 'bg-red-100 text-red-800', ['missing']],
        'due' => ['Due later', 'bg-slate-100 text-slate-700', ['due']],
        'out' => ['Out', 'bg-slate-100 text-slate-700', ['out']],
        'away' => ['On leave or off', 'bg-indigo-50 text-indigo-800', ['leave', 'holiday', 'off']],
    ];
    $count = fn ($people, array $kinds) => $people->whereIn('state', $kinds)->count();
    $everyone = collect($board);
    $inUnit = fn (string $key) => $everyone->filter(fn ($person) => (string) ($person['staff']->attendance_department_id ?: 'none') === $key);
    // Who a department shows when opened: everyone but those away, unless a count is picked; those not in first.
    $order = ['missing' => 0, 'due' => 1, 'in' => 2, 'out' => 3, 'leave' => 4, 'holiday' => 5, 'off' => 6];
    $listed = fn ($people) => $people->whereIn('state', $boardShow !== '' ? $states[$boardShow][2] : ['in', 'missing', 'due', 'out'])
        ->sortBy(fn ($person) => [$order[$person['state']] ?? 9, $person['staff']->name])->values();
@endphp
<div class="mb-4 rounded-xl border border-slate-200 bg-white shadow-sm" wire:poll.60s.visible>
    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-3 py-2">
        <h2 class="!mb-0 text-sm font-semibold text-slate-900">Today, {{ $today->format('D j M') }}</h2>
        <span class="text-xs text-slate-500">As of {{ $today->format('g:i A') }}, from the terminal's punches. Updates every minute.</span>
    </div>

    {{-- The whole branch at a glance; a count picked lists those people. --}}
    <div class="grid grid-cols-2 gap-2 p-3 sm:grid-cols-5">
        @foreach($states as $key => [$label, $tone, $kinds])
            <button type="button" wire:click="$set('boardShow', '{{ $boardShow === $key ? '' : $key }}')" @class(['rounded-lg border px-3 py-2 text-left', 'border-teal-600 ring-1 ring-teal-600' => $boardShow === $key, 'border-slate-200 hover:bg-slate-50' => $boardShow !== $key]) aria-pressed="{{ $boardShow === $key ? 'true' : 'false' }}">
                <span class="block text-xs text-slate-500">{{ $label }}</span>
                <span class="mt-0.5 inline-block rounded px-1.5 text-lg font-semibold tabular-nums {{ $tone }}">{{ $count($everyone, $kinds) }}</span>
            </button>
        @endforeach
    </div>
    @if($boardShow !== '' || $boardUnit !== '')
        <p class="!mb-0 px-3 pb-2 text-xs text-slate-500">
            {{ $boardShow !== '' ? 'Showing: '.$states[$boardShow][0].'.' : '' }}
            <button type="button" class="text-teal-700 underline" wire:click="clearBoard">Clear</button>
        </p>
    @endif

    @if($boardRows)
        <div class="ui-table-wrap border-t border-slate-200">
            <table class="table ui-table ui-table-sm mb-0">
                <thead><tr><th>Department</th>@foreach($states as [$label])<th class="text-right">{{ $label }}</th>@endforeach<th>Short now</th></tr></thead>
                <tbody>
                    @foreach($boardRows as $unit)
                        @php
                            $people = $inUnit($unit['key']);
                            $open = $boardUnit === (string) $unit['key'];
                        @endphp
                        <tr wire:key="board-unit-{{ $unit['key'] }}" wire:click="$set('boardUnit', '{{ $open ? '' : $unit['key'] }}')" @class(['cursor-pointer', 'bg-teal-50/60' => $open, 'hover:bg-slate-50' => ! $open]) aria-haspopup="dialog">
                            <td class="font-medium text-slate-800">{{ $unit['name'] }}<i class="fas fa-chevron-right ml-1.5 text-xs text-slate-400" aria-hidden="true"></i></td>
                            @foreach($states as $key => [$label, $tone, $kinds])
                                @php $n = $count($people, $kinds); @endphp
                                <td class="text-right ui-number">@if($n)<span @class(['rounded px-1.5 py-0.5', $tone => $key === 'missing'])>{{ $n }}</span>@else <span class="text-slate-300">0</span> @endif</td>
                            @endforeach
                            <td class="text-sm">@forelse($unit['short'] as $gap)<span class="mr-1 inline-block rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-800">{{ $gap['shift'] }}: {{ $gap['in'] }} of {{ $gap['need'] }}</span>@empty<span class="text-slate-300">–</span>@endforelse</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Without departments, or a count picked with no department open: the names under the counts. --}}
    @php $names = $listed($everyone); @endphp
    @if(! $boardRows || ($boardShow !== '' && $boardUnit === ''))
        <div class="border-t border-slate-200 p-3">
            <div class="grid gap-1.5 sm:grid-cols-2 xl:grid-cols-4">
                @forelse($names->take(80) as $person)
                    @include('livewire.admin.partials.attendance-person-chip')
                @empty
                    <p class="!mb-0 text-sm text-slate-400">Nobody</p>
                @endforelse
            </div>
            @if($names->count() > 80)<p class="!mb-0 mt-2 text-xs text-slate-500">And {{ $names->count() - 80 }} more. Open a department to see its people.</p>@endif
        </div>
    @else
        <p class="!mb-0 border-t border-slate-200 px-3 py-2 text-xs text-slate-500">Click a department to see its people, or a count above for the whole branch.</p>
    @endif
    @if($boardRows && $boardUnit !== '' && ($unit = collect($boardRows)->firstWhere('key', $boardUnit)))
        @php
            $people = $inUnit($boardUnit);
            $groups = collect($states)->map(fn ($state, $key) => [$state[0], $state[1], $people->whereIn('state', $state[2])->sortBy(fn ($person) => $person['staff']->name)->values()])
                ->filter(fn ($group, $key) => $boardShow === '' || $boardShow === $key);
        @endphp
        <div wire:key="board-drawer-{{ $boardUnit }}">
            <div class="att-overlay" data-sheet-overlay x-data x-on:click="dismissLocal($el.nextElementSibling, $wire, { boardUnit: '' })" aria-hidden="true"></div>
            <aside class="att-drawer" data-sheet role="dialog" aria-modal="true" aria-labelledby="att-drawer-title" tabindex="-1" x-data x-init="$el.focus()"
                   x-on:keydown.escape.window="dismissLocal($el, $wire, { boardUnit: '' })">
                <header class="att-drawer-head">
                    <div>
                        <h2 id="att-drawer-title">{{ $unit['name'] }}</h2>
                        <div class="flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="text-slate-500">Today, {{ $today->format('D j M') }} · as of {{ $today->format('g:i A') }}</span>
                            @foreach($unit['short'] as $gap)<span class="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-800">{{ $gap['shift'] }}: {{ $gap['in'] }} of {{ $gap['need'] }}</span>@endforeach
                        </div>
                    </div>
                    <button type="button" class="att-x" x-on:click="dismissLocal($el, $wire, { boardUnit: '' })" aria-label="Close">&times;</button>
                </header>
                <div class="att-drawer-body">
                    <div class="grid grid-cols-5 gap-2">
                        @foreach($states as $key => [$label, $tone, $kinds])
                            <div class="rounded-lg border border-slate-200 px-2 py-1.5">
                                <span class="block text-[11px] leading-tight text-slate-500">{{ $label }}</span>
                                <span class="inline-block rounded px-1 text-base font-semibold tabular-nums {{ $tone }}">{{ $count($people, $kinds) }}</span>
                            </div>
                        @endforeach
                    </div>
                    @foreach($groups as $key => [$label, $tone, $names])
                        @continue($names->isEmpty())
                        <section>
                            <h3 class="att-section-title">{{ $label }} <span class="rounded px-1.5 py-0.5 normal-case {{ $tone }}">{{ $names->count() }}</span></h3>
                            <div class="grid gap-1.5 sm:grid-cols-2">
                                @foreach($names as $person)
                                    @include('livewire.admin.partials.attendance-person-chip')
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                    @if($groups->every(fn ($group) => $group[2]->isEmpty()))<p class="!mb-0 text-sm text-slate-500">Nobody here{{ $boardShow !== '' ? ' is '.strtolower($states[$boardShow][0]) : '' }}.</p>@endif
                </div>
                <footer class="att-drawer-foot">
                    <button type="button" class="ui-button ui-button-secondary" wire:click="showDepartmentReport('{{ $boardUnit }}')">See in the report</button>
                    <button type="button" class="ui-button ui-button-primary" x-on:click="dismissLocal($el, $wire, { boardUnit: '' })">Close</button>
                </footer>
            </aside>
        </div>
    @endif
</div>
<style>
    .att-overlay{position:fixed;inset:0;z-index:1040;background:rgb(15 23 42 / .45)}
    .att-drawer{position:fixed;top:0;right:0;z-index:1041;height:100dvh;width:min(620px,100vw);background:#fff;display:flex;flex-direction:column;box-shadow:-16px 0 40px rgb(15 23 42 / .2);animation:attSlide .2s ease-out;outline:none}
    @keyframes attSlide{from{transform:translateX(40px);opacity:.4}to{transform:none;opacity:1}}
    @media(prefers-reduced-motion:reduce){.att-drawer{animation:none}}
    .att-drawer-head{padding:18px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
    .att-drawer-head h2{margin:0 0 6px;font-size:18px;font-weight:800;color:#0f172a}
    .att-drawer-body{flex:1;overflow-y:auto;padding:18px 20px;display:grid;gap:18px;align-content:start}
    .att-drawer-foot{border-top:1px solid #e2e8f0;padding:12px 20px;display:flex;gap:8px;justify-content:flex-end;background:#fafcfc}
    .att-section-title{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#64748b;font-weight:800}
    .att-x{border:0;background:none;font-size:22px;line-height:1;color:#64748b;cursor:pointer;padding:2px 6px;border-radius:6px}.att-x:hover{background:#f1f5f9}
</style>

