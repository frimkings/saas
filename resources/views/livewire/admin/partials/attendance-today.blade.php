{{-- The Today tab: who is in, not in yet, out and away, as counts; names on demand. With departments, one row each. --}}
@php
    use App\Services\Attendance\AttendanceAnalyzer;

    $states = [
        'in' => ['In now', 'bg-emerald-50 text-emerald-800', ['in']],
        'missing' => ['Not in yet', 'bg-red-100 text-red-800', ['missing']],
        'due' => ['Due later', 'bg-slate-100 text-slate-700', ['due']],
        'out' => ['Out', 'bg-slate-100 text-slate-700', ['out']],
        'away' => ['On leave or off', 'bg-indigo-50 text-indigo-800', ['leave', 'holiday', 'off']],
    ];
    $count = fn ($people, array $kinds) => $people->whereIn('state', $kinds)->count();
    $everyone = collect($board);
@endphp
<div class="mb-4 rounded-xl border border-slate-200 bg-white shadow-sm" wire:poll.60s.visible>
    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-3 py-2">
        <h2 class="!mb-0 text-sm font-semibold text-slate-900">Today, {{ $today->format('D j M') }}</h2>
        <span class="text-xs text-slate-500">As of {{ $today->format('g:i A') }}, from the terminal's punches. Updates every minute.</span>
    </div>

    {{-- The whole branch at a glance. --}}
    <div class="grid grid-cols-2 gap-2 p-3 sm:grid-cols-5">
        @foreach($states as $key => [$label, $tone, $kinds])
            <button type="button" wire:click="$set('boardShow', '{{ $boardShow === $key ? '' : $key }}')" @class(['rounded-lg border px-3 py-2 text-left', 'border-teal-600 ring-1 ring-teal-600' => $boardShow === $key, 'border-slate-200 hover:bg-slate-50' => $boardShow !== $key]) aria-pressed="{{ $boardShow === $key ? 'true' : 'false' }}">
                <span class="block text-xs text-slate-500">{{ $label }}</span>
                <span class="mt-0.5 inline-block rounded px-1.5 text-lg font-semibold tabular-nums {{ $tone }}">{{ $count($everyone, $kinds) }}</span>
            </button>
        @endforeach
    </div>

    @if($boardRows)
        <div class="ui-table-wrap border-t border-slate-200">
            <table class="table ui-table ui-table-sm mb-0">
                <thead><tr><th>Department</th>@foreach($states as [$label])<th class="text-right">{{ $label }}</th>@endforeach<th>Short now</th></tr></thead>
                <tbody>
                    @foreach($boardRows as $unit)
                        @php $people = $everyone->filter(fn ($person) => ($person['staff']->attendance_department_id ?? 'none') == $unit['key'] || ($unit['key'] === 'none' && ! $person['staff']->attendance_department_id)); @endphp
                        <tr wire:key="board-unit-{{ $unit['key'] }}" @class(['bg-teal-50/50' => $boardUnit === (string) $unit['key']])>
                            <td><button type="button" class="font-medium text-slate-800 underline decoration-slate-300 hover:decoration-slate-600" wire:click="$set('boardUnit', '{{ $boardUnit === (string) $unit['key'] ? '' : $unit['key'] }}')" aria-expanded="{{ $boardUnit === (string) $unit['key'] ? 'true' : 'false' }}">{{ $unit['name'] }}</button></td>
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

    {{-- Names: for a department picked, a count picked, or a small branch. --}}
    @php
        $shown = $everyone;
        if ($boardRows && $boardUnit !== '') {
            $shown = $shown->filter(fn ($person) => (string) ($person['staff']->attendance_department_id ?? 'none') === $boardUnit);
        }
        $kinds = $boardShow !== '' ? $states[$boardShow][2] : ['in', 'missing', 'due', 'out'];
        $shown = $shown->whereIn('state', $kinds);
        $named = ! $boardRows || $boardUnit !== '' || $boardShow !== '';
    @endphp
    @if($named)
        <div class="border-t border-slate-200 p-3">
            <p class="!mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ $boardShow !== '' ? $states[$boardShow][0] : 'In, out and not in yet' }}{{ $boardRows && $boardUnit !== '' ? ' · '.(collect($boardRows)->firstWhere('key', $boardUnit)['name'] ?? '') : '' }}
                <span class="normal-case font-normal text-slate-400">({{ $shown->count() }})</span>
                @if($boardUnit !== '' || $boardShow !== '')<button type="button" class="ml-2 normal-case font-normal text-teal-700 underline" wire:click="clearBoard">Clear</button>@endif
            </p>
            <div class="grid gap-1.5 sm:grid-cols-2 xl:grid-cols-4">
                @forelse($shown->take(80) as $person)
                    <button type="button" class="block w-full rounded-lg border border-slate-100 px-2 py-1 text-left text-sm hover:bg-slate-50" wire:click="openStaff({{ $person['staff']->id }})" wire:key="board-{{ $person['staff']->id }}">
                        <span class="font-medium text-slate-800">{{ $person['staff']->name }}</span>
                        <span class="block text-xs text-slate-500">
                            @switch($person['state'])
                                @case('in')
                                    In since {{ AttendanceAnalyzer::time($person['time']) }}{{ $person['time'] !== $person['in'] ? ', first in '.AttendanceAnalyzer::time($person['in']) : '' }}{{ $person['late_minutes'] ? ' · '.AttendanceAnalyzer::duration($person['late_minutes']).' late' : '' }}
                                    @break
                                @case('out')
                                    {{ $person['left'] ? 'Left at' : 'Out since' }} {{ AttendanceAnalyzer::time($person['time']) }}
                                    @break
                                @case('missing')
                                    <span class="font-medium text-red-700">Expected by {{ AttendanceAnalyzer::time($person['expected']) }}</span>{{ $person['note'] ? ' · '.$person['note'] : '' }}
                                    @break
                                @case('due')
                                    Due at {{ AttendanceAnalyzer::time($person['expected']) }}{{ $person['note'] ? ' · '.$person['note'] : '' }}
                                    @break
                                @case('off')
                                    Day off
                                    @break
                                @default
                                    {{ $person['note'] ?: ($person['state'] === 'holiday' ? 'Holiday' : 'On leave') }}
                            @endswitch
                        </span>
                    </button>
                @empty
                    <p class="!mb-0 text-sm text-slate-400">Nobody</p>
                @endforelse
            </div>
            @if($shown->count() > 80)<p class="!mb-0 mt-2 text-xs text-slate-500">And {{ $shown->count() - 80 }} more. Pick a department or a count above to narrow it.</p>@endif
        </div>
    @else
        <p class="!mb-0 border-t border-slate-200 px-3 py-2 text-xs text-slate-500">Click a department or a count to see the names.</p>
    @endif
</div>
