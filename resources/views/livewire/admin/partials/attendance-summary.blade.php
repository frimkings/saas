{{--
    The Report's summary: one line per person, worst first on a large staff. Columns say what happened;
    zeros are left blank and the rarer things (left early, long breaks, missing punches, overtime)
    show as flags only for those who have them. A single day is a day sheet: in, out, hours, status.
--}}
@php
    use App\Services\Attendance\AttendanceAnalyzer;
    use App\Support\AttendanceTones;

    $dayKey = $oneDay ? $first->format('Y-m-d') : null;
    // A column heading that sorts the list by it, worst first.
    $sortable = function (string $key, string $label, string $align = 'text-right') use ($sort) {
        $on = $sort === $key;

        return '<th class="'.$align.'"><button type="button" wire:click="$set(\'sort\', \''.($on ? '' : $key).'\')" class="inline-flex items-center gap-1 font-semibold '.($on ? 'text-teal-800' : 'text-inherit hover:text-slate-900').'" aria-pressed="'.($on ? 'true' : 'false').'">'
            .e($label).'<i class="fas fa-arrow-down text-[10px] '.($on ? '' : 'opacity-25').'" aria-hidden="true"></i></button></th>';
    };
    $flags = function (array $t, ?array $day = null) use ($extras) {
        $out = [];
        $add = function ($text, $style) use (&$out) { $out[] = '<span class="mr-1 inline-block whitespace-nowrap rounded px-1.5 py-0.5 text-xs" style="'.$style.'">'.e($text).'</span>'; };
        if ($day) {
            if ($day['left_early']) $add('Left '.AttendanceAnalyzer::duration($day['early_minutes']).' early', AttendanceTones::flag('left_early'));
            if ($day['long_break']) $add('Long break '.AttendanceAnalyzer::duration($day['break_minutes']), AttendanceTones::flag('long_break'));
            if ($day['no_clock_out']) $add('Missing punch', AttendanceTones::flag('no_clock_out'));
            if ($extras['overtime'] && $day['overtime_minutes']) $add('Overtime '.AttendanceAnalyzer::duration($day['overtime_status'] === 'approved' ? $day['overtime_approved'] : $day['overtime_minutes']).($day['overtime_status'] === 'pending' ? ', waiting' : ''), AttendanceTones::flag(AttendanceTones::overtime($day['overtime_status'])));
            if ($day['note'] && in_array($day['status'], ['leave', 'holiday'], true)) $add($day['note'], AttendanceTones::flag('note'));
        } else {
            if ($t['left_early']) $add('Left early '.$t['left_early'].'×', AttendanceTones::flag('left_early'));
            if ($t['long_breaks']) $add('Long breaks '.$t['long_breaks'].'×', AttendanceTones::flag('long_break'));
            if ($t['no_clock_out']) $add('Missing punch '.$t['no_clock_out'].'×', AttendanceTones::flag('no_clock_out'));
            if ($extras['overtime'] && $t['overtime_minutes']) $add('Overtime '.AttendanceAnalyzer::duration($t['overtime_minutes']), AttendanceTones::flag('overtime'));
            if ($extras['overtime'] && $t['overtime_pending']) $add(AttendanceAnalyzer::duration($t['overtime_pending']).' overtime waiting', AttendanceTones::flag('overtime_pending'));
            if ($t['leave']) $add($t['leave'].' on leave', AttendanceTones::status('leave'));
        }

        return implode('', $out);
    };
@endphp
<div data-tour="att-people" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="ui-table-wrap">
        <table class="table ui-table ui-table-sm mb-0">
            <thead>
                <tr>
                    {!! $sortable('name', 'Staff', 'text-left') !!}
                    @if($oneDay)
                        {!! $sortable('arrival', 'In') !!}
                        <th class="text-right">Out</th>
                        <th class="text-right">On site</th>
                        {!! $sortable('issues', 'Status', 'text-left') !!}
                    @else
                        {!! $sortable('present', 'Present') !!}
                        {!! $sortable('late', 'Late') !!}
                        {!! $sortable('absent', 'Absent') !!}
                        {!! $sortable('arrival', 'Usual arrival') !!}
                        {!! $sortable('issues', 'Flags', 'text-left') !!}
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    @php $t = $row['totals']; $staff = $row['staff']; $day = $dayKey ? $row['days'][$dayKey] : null; @endphp
                    <tr wire:key="summary-{{ $staff->id }}" wire:click="openStaff({{ $staff->id }})" @class(['cursor-pointer hover:bg-slate-50', 'opacity-60' => ! $staff->is_tracked])>
                        <td class="whitespace-nowrap">
                            <span class="font-semibold text-slate-900">{{ $staff->name }}</span>
                            <span class="ml-1 text-xs text-slate-500">{{ $staff->employee_no }}{{ ($unitName = $staff->unit?->name ?? $staff->department) ? ' · '.$unitName : '' }}</span>
                            @unless($staff->is_tracked)<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Not followed</span>@endunless
                            @if($staff->hours)<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Own hours</span>@endif
                        </td>
                        @if(! $staff->is_tracked)
                            <td colspan="{{ $oneDay ? 4 : 5 }}" class="text-sm text-slate-400"></td>
                        @elseif($oneDay)
                            <td class="text-right ui-number">{{ AttendanceAnalyzer::time($day['in']) }}</td>
                            <td class="text-right ui-number">{{ AttendanceAnalyzer::time($day['out']) }}</td>
                            <td class="text-right ui-number">{{ $day['minutes_worked'] !== null ? AttendanceAnalyzer::duration($day['minutes_worked']) : '' }}</td>
                            <td>
                                <span class="mr-1 inline-block rounded px-1.5 py-0.5 text-xs font-medium " style="{{ AttendanceTones::status($day['status']) }}">{{ $day['status'] === 'none' ? 'No data' : AttendanceAnalyzer::STATUSES[$day['status']] }}{{ $day['late_minutes'] ? ' '.AttendanceAnalyzer::duration($day['late_minutes']) : '' }}</span>{!! $flags($t, $day) !!}
                            </td>
                        @else
                            <td class="text-right ui-number">{{ $t['present'] }}<span class="text-slate-400"> / {{ $t['expected'] }}</span></td>
                            <td class="text-right ui-number">@if($t['late'])<span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-900">{{ $t['late'] }}</span> <span class="text-xs text-slate-500">{{ AttendanceAnalyzer::duration($t['late_minutes']) }}</span>@endif</td>
                            <td class="text-right ui-number">@if($t['absent'])<span class="rounded bg-red-100 px-1.5 py-0.5 font-semibold text-red-800">{{ $t['absent'] }}</span>@endif</td>
                            <td class="text-right ui-number">{{ AttendanceAnalyzer::time($t['average_arrival']) }}</td>
                            <td>{!! $flags($t) !!}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $oneDay ? 5 : 6 }}" class="py-6 text-center text-slate-500">{{ $show === 'attention' ? 'Nothing needs a look: nobody was late, absent, left early, took a long break or missed a punch.' : 'Nobody matches this filter.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('livewire.admin.partials.attendance-pager', ['pager' => $pager, 'model' => 'page'])
</div>
