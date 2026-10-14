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
                        <tr wire:key="board-unit-{{ $unit['key'] }}" wire:click="$set('boardUnit', '{{ $open ? '' : $unit['key'] }}')" @class(['cursor-pointer', 'bg-teal-50/60' => $open, 'hover:bg-slate-50' => ! $open]) aria-expanded="{{ $open ? 'true' : 'false' }}">
                            <td class="font-medium text-slate-800"><i class="fas fa-chevron-{{ $open ? 'down' : 'right' }} mr-1.5 w-3 text-xs text-slate-400" aria-hidden="true"></i>{{ $unit['name'] }}</td>
                            @foreach($states as $key => [$label, $tone, $kinds])
                                @php $n = $count($people, $kinds); @endphp
                                <td class="text-right ui-number">@if($n)<span @class(['rounded px-1.5 py-0.5', $tone => $key === 'missing'])>{{ $n }}</span>@else <span class="text-slate-300">0</span> @endif</td>
                            @endforeach
                            <td class="text-sm">@forelse($unit['short'] as $gap)<span class="mr-1 inline-block rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-800">{{ $gap['shift'] }}: {{ $gap['in'] }} of {{ $gap['need'] }}</span>@empty<span class="text-slate-300">–</span>@endforelse</td>
                        </tr>
                        @if($open)
                            {{-- The department's people, sliding open right under its row. --}}
                            @php $names = $listed($people); @endphp
                            <tr wire:key="board-unit-{{ $unit['key'] }}-people" class="!bg-slate-50">
                                <td colspan="{{ count($states) + 2 }}" class="!p-0">
                                    <div x-data="{ open: false }" x-init="$nextTick(() => open = true)" x-show="open" x-collapse>
                                        <div class="grid gap-1.5 p-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                            @forelse($names as $person)
                                                @include('livewire.admin.partials.attendance-person-chip')
                                            @empty
                                                <p class="!mb-0 text-sm text-slate-400">Nobody{{ $boardShow !== '' ? ' '.strtolower($states[$boardShow][0]) : ' in, out or due' }} here.</p>
                                            @endforelse
                                        </div>
                                        @if($boardShow === '' && $count($people, $states['away'][2]))
                                            <p class="!mb-0 px-3 pb-2 text-xs text-slate-500">{{ $count($people, $states['away'][2]) }} on leave or off today are not listed; click "On leave or off" above to see them.</p>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
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
    @elseif($boardUnit === '')
        <p class="!mb-0 border-t border-slate-200 px-3 py-2 text-xs text-slate-500">Click a department to see its people, or a count above.</p>
    @endif
</div>
