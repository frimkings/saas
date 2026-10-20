{{-- One person on the Today board: their name and where they are. $person from AttendanceAnalyzer::today(). --}}
<button type="button" class="block w-full rounded-lg border border-slate-100 bg-white px-2 py-1 text-left text-sm hover:bg-slate-50" wire:click="openStaff({{ $person['staff']->id }})" wire:key="board-{{ $person['staff']->id }}">
    <span class="flex items-center justify-between gap-2">
        <span class="truncate font-medium text-slate-800">{{ $person['staff']->name }}</span>
        @if(($person['shift'] ?? null) && ($look = $shiftCodes[$person['shift']] ?? null))<span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-bold" style="{{ $look['style'] }}" title="{{ $person['shift'] }}">{{ $look['code'] }}</span>@endif
    </span>
    <span class="block text-xs text-slate-500">
        @switch($person['state'])
            @case('in')
                In since {{ \App\Services\Attendance\AttendanceAnalyzer::time($person['time']) }}{{ $person['time'] !== $person['in'] ? ', first in '.\App\Services\Attendance\AttendanceAnalyzer::time($person['in']) : '' }}{{ $person['late_minutes'] ? ' · '.\App\Services\Attendance\AttendanceAnalyzer::duration($person['late_minutes']).' late' : '' }}
                @break
            @case('out')
                {{ $person['left'] ? 'Left at' : 'Out since' }} {{ \App\Services\Attendance\AttendanceAnalyzer::time($person['time']) }}
                @break
            @case('missing')
                <span class="font-medium text-red-700">Expected by {{ \App\Services\Attendance\AttendanceAnalyzer::time($person['expected']) }}</span>{{ $person['note'] ? ' · '.$person['note'] : '' }}
                @break
            @case('due')
                Due at {{ \App\Services\Attendance\AttendanceAnalyzer::time($person['expected']) }}{{ $person['note'] ? ' · '.$person['note'] : '' }}
                @break
            @case('off')
                Day off
                @break
            @default
                {{ $person['note'] ?: ($person['state'] === 'holiday' ? 'Holiday' : 'On leave') }}
        @endswitch
    </span>
</button>
