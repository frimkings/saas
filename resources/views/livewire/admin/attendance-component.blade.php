@php
    use App\Services\Attendance\AttendanceAnalyzer;

    $tone = [
        'on_time' => 'bg-emerald-50 text-emerald-800',
        'late' => 'bg-amber-100 text-amber-900',
        'absent' => 'bg-red-100 text-red-800',
        'off' => 'bg-slate-100 text-slate-400',
        'holiday' => 'bg-violet-100 text-violet-800',
        'leave' => 'bg-indigo-100 text-indigo-800',
        'worked_off' => 'bg-sky-50 text-sky-800',
        'none' => 'text-slate-300',
    ];
    $totals = $report['totals'];
    $coveredUntil = $report['covered_until'] ? \Carbon\CarbonImmutable::parse($report['covered_until']) : null;
    $settings = $report['settings'];
@endphp
<div class="clinic-ui ui-page">
    <div class="w-full">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="!mb-0 text-xl font-semibold text-slate-900">Attendance</h1>
                <p class="!mb-0 text-sm text-slate-500">Who was on time, late or absent, from the attendance terminal.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="ui-button ui-button-secondary" wire:click="openRoster"><i class="fas fa-table" aria-hidden="true"></i>Roster</button>
                <button type="button" class="ui-button ui-button-secondary" wire:click="$set('showOvertime', true)"><i class="fas fa-hourglass-half" aria-hidden="true"></i>Overtime</button>
                @if($canPay)<button type="button" class="ui-button ui-button-secondary" wire:click="openPayroll"><i class="fas fa-money-bill-wave" aria-hidden="true"></i>Payroll</button>@endif
                {{-- Things set once and seldom changed. --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" class="ui-button ui-button-secondary" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="true"><i class="fas fa-sliders" aria-hidden="true"></i>Set up<i class="fas fa-chevron-down ml-1 text-xs" aria-hidden="true"></i></button>
                    <div x-show="open" x-cloak @click="open = false" class="absolute right-0 z-20 mt-1 w-56 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg" role="menu">
                        @foreach([
                            ['editHours', 'fa-clock', 'Working hours'],
                            ["\$set('showShifts', true)", 'fa-layer-group', 'Shifts'],
                            ['openLeaveTypes', 'fa-umbrella-beach', 'Leave types'],
                            ["\$set('showHolidays', true)", 'fa-calendar-day', 'Holidays'],
                            ["\$set('showDevices', true)", 'fa-fingerprint', 'Terminals'],
                            ['openPhone', 'fa-mobile-screen', 'Phone clock-in'],
                        ] as [$action, $icon, $label])
                            <button type="button" role="menuitem" wire:click="{{ $action }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50"><i class="fas {{ $icon }} w-4 text-slate-400" aria-hidden="true"></i>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <button type="button" class="ui-button ui-button-primary" wire:click="$set('showUpload', true)"><i class="fas fa-upload" aria-hidden="true"></i>Upload report</button>
            </div>
        </div>

        @foreach($silent as $device)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900" role="alert">
                <span><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i><strong>{{ $device->name }}</strong> has sent no punches since {{ $device->last_seen_at->setTimezone($timezone)->format('D j M, g:i A') }}. Either it is cut off or nobody has punched. Check it has power and is on the network; punches made meanwhile stay on the terminal and normally arrive once it is back.</span>
                <button type="button" class="ui-button ui-button-secondary !py-1.5" wire:click="$set('showDevices', true)">Terminals</button>
            </div>
        @endforeach

        @if($requests->isNotEmpty())
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900">
                <span><i class="fas fa-envelope-open-text mr-1" aria-hidden="true"></i><strong>{{ $requests->count() }}</strong> leave {{ $requests->count() === 1 ? 'request is' : 'requests are' }} waiting for an answer.</span>
                <button type="button" class="ui-button ui-button-primary !py-1.5" wire:click="$set('showRequests', true)">Review</button>
            </div>
        @endif

        @if($overtimeWaiting)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <span><i class="fas fa-hourglass-half mr-1" aria-hidden="true"></i><strong>{{ $overtimeWaiting }}</strong> {{ $overtimeWaiting === 1 ? 'day of overtime is' : 'days of overtime are' }} waiting for your answer in {{ $periodLabel }}.</span>
                <button type="button" class="ui-button ui-button-primary !py-1.5" wire:click="$set('showOvertime', true)">Review</button>
            </div>
        @endif

        @if($board)
            @php
                $groups = [
                    'in' => ['In now', 'bg-emerald-50 text-emerald-800', collect($board)->where('state', 'in')],
                    'out' => ['Out', 'bg-slate-100 text-slate-700', collect($board)->where('state', 'out')],
                    'missing' => ['Not in', 'bg-red-100 text-red-800', collect($board)->whereIn('state', ['missing', 'due'])],
                    'away' => ['On leave or off', 'bg-indigo-100 text-indigo-800', collect($board)->whereIn('state', ['leave', 'holiday', 'off'])],
                ];
            @endphp
            <div class="mb-4 rounded-xl border border-slate-200 bg-white p-3 shadow-sm" wire:poll.60s.visible>
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="!mb-0 text-sm font-semibold text-slate-900">Today, {{ $today->format('D j M') }}</h2>
                    <span class="text-xs text-slate-500">As of {{ $today->format('g:i A') }}, from the terminal's punches. Updates every minute.</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($groups as $group => [$label, $colour, $people])
                        <div>
                            <p class="!mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }} <span class="ml-1 rounded px-1.5 py-0.5 {{ $colour }}">{{ $people->count() }}</span></p>
                            @forelse($people as $person)
                                <button type="button" class="mb-1 block w-full rounded-lg border border-slate-100 px-2 py-1 text-left text-sm hover:bg-slate-50" wire:click="openStaff({{ $person['staff']->id }})" wire:key="board-{{ $person['staff']->id }}">
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
                    @endforeach
                </div>
            </div>
        @endif

        @if(! $hasData)
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <p class="!mb-1 text-base font-semibold text-slate-800">No attendance uploaded yet</p>
                <p class="!mb-4 text-sm text-slate-500">Connect the terminal under <strong>Terminals</strong> so punches arrive by themselves, or export its <strong>Attendance Record</strong> report for the month to a USB drive and upload the .xls file here.</p>
                <button type="button" class="ui-button ui-button-primary" wire:click="$set('showUpload', true)"><i class="fas fa-upload" aria-hidden="true"></i>Upload report</button>
            </div>
        @else
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <x-date-range from="from" to="to" presets="finance" />
                <div class="flex flex-wrap items-center gap-2">
                    <select class="ui-input !w-48 !py-1.5 !text-sm" wire:model.live="show" aria-label="Show">
                        <option value="all">Everyone</option>
                        <option value="late">Late at least once</option>
                        <option value="absent">Absent at least once</option>
                    </select>
                    <div class="inline-flex overflow-hidden rounded-lg border border-slate-200" role="group" aria-label="View">
                        @foreach(['summary' => 'Summary', 'grid' => 'Day by day', 'trends' => 'Trends'] as $key => $label)
                            <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['px-3 py-1.5 text-sm font-medium', 'bg-slate-800 text-white' => $view === $key, 'bg-white text-slate-600 hover:bg-slate-50' => $view !== $key]) aria-pressed="{{ $view === $key ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <a class="ui-button ui-button-secondary !py-1.5" href="{{ route('admin.attendance.export', ['format' => 'csv', 'from' => $first->format('Y-m-d'), 'to' => $last->format('Y-m-d')]) }}"><i class="fas fa-file-excel" aria-hidden="true"></i>Excel</a>
                    <a class="ui-button ui-button-secondary !py-1.5" href="{{ route('admin.attendance.export', ['format' => 'pdf', 'from' => $first->format('Y-m-d'), 'to' => $last->format('Y-m-d')]) }}"><i class="fas fa-file-pdf" aria-hidden="true"></i>PDF</a>
                </div>
            </div>

            @if(! $coveredUntil)
                <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    Nothing has been received for {{ $periodLabel }}, so nobody is marked late or absent in it.
                </div>
            @else
                <div class="ui-stats ui-stats--inline">
                    <x-ui.stat label="Staff followed" :value="$totals['staff']" />
                    <x-ui.stat label="Late arrivals" :value="$totals['late']" />
                    <x-ui.stat label="Absences" :value="$totals['absent']" />
                    <x-ui.stat label="Days on leave" :value="$totals['leave']" />
                    <x-ui.stat label="Attendance" :value="$totals['expected'] ? round($totals['present'] / $totals['expected'] * 100).'%' : '–'" />
                </div>
                <p class="!mb-3 text-xs text-slate-500">
                    Recorded up to {{ $coveredUntil->format('D j M Y') }}{{ ($report['coverage'][$report['covered_until']] ?? '') === 'partial' ? ', a day still in progress' : '' }}.
                    Later days show no data until the terminal sends more or a newer report is uploaded.
                </p>
            @endif

            @if($view === 'summary')
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="ui-table-wrap">
                        <table class="table ui-table mb-0">
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th class="text-right">Present</th>
                                    <th class="text-right">On time</th>
                                    <th class="text-right">Late</th>
                                    <th class="text-right">Time late</th>
                                    <th class="text-right">Absent</th>
                                    <th class="text-right">Leave</th>
                                    <th class="text-right">Left early</th>
                                    <th class="text-right">Long breaks</th>
                                    <th class="text-right">Missing punch</th>
                                    <th class="text-right">Overtime</th>
                                    <th class="text-right">Usual arrival</th>
                                    <th><span class="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rows as $row)
                                    @php $t = $row['totals']; $staff = $row['staff']; @endphp
                                    <tr wire:key="summary-{{ $staff->id }}" @class(['opacity-60' => ! $staff->is_tracked])>
                                        <td>
                                            <strong>{{ $staff->name }}</strong>
                                            @unless($staff->is_tracked)<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Not followed</span>@endunless
                                            @if($staff->hours)<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">Own hours</span>@endif
                                            <br><small class="text-slate-500">ID {{ $staff->employee_no }}@if($staff->department) · {{ $staff->department }}@endif</small>
                                        </td>
                                        @if($staff->is_tracked)
                                            <td class="text-right ui-number">{{ $t['present'] }}<span class="text-slate-400"> / {{ $t['expected'] }}</span></td>
                                            <td class="text-right ui-number">{{ $t['on_time'] }}</td>
                                            <td class="text-right ui-number">@if($t['late'])<span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-900">{{ $t['late'] }}</span>@else 0 @endif</td>
                                            <td class="text-right ui-number">{{ $t['late_minutes'] ? AttendanceAnalyzer::duration($t['late_minutes']) : '–' }}</td>
                                            <td class="text-right ui-number">@if($t['absent'])<span class="rounded bg-red-100 px-1.5 py-0.5 font-semibold text-red-800">{{ $t['absent'] }}</span>@else 0 @endif</td>
                                            <td class="text-right ui-number">{{ $t['leave'] }}</td>
                                            <td class="text-right ui-number">{{ $t['left_early'] }}</td>
                                            <td class="text-right ui-number">@if($t['long_breaks'])<span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-900">{{ $t['long_breaks'] }}</span>@else 0 @endif</td>
                                            <td class="text-right ui-number">{{ $t['no_clock_out'] }}</td>
                                            <td class="text-right ui-number">{{ $t['overtime_minutes'] ? AttendanceAnalyzer::duration($t['overtime_minutes']) : '–' }}@if($t['overtime_pending'])<span class="block text-xs font-normal text-amber-700">{{ AttendanceAnalyzer::duration($t['overtime_pending']) }} waiting</span>@endif</td>
                                            <td class="text-right ui-number">{{ AttendanceAnalyzer::time($t['average_arrival']) ?? '–' }}</td>
                                        @else
                                            <td colspan="11" class="text-right text-sm text-slate-400">–</td>
                                        @endif
                                        <td class="text-right"><button type="button" class="ui-button ui-button-secondary !px-2.5 !py-1 !text-xs" wire:click="openStaff({{ $staff->id }})">Open</button></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="13" class="py-6 text-center text-slate-500">Nobody matches this filter.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @elseif($view === 'grid')
                {{-- A month at a time, Monday to Sunday: how each day went for the branch. Clicking a day lists everyone. --}}
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
                        <button type="button" class="ui-button ui-button-secondary !px-2.5 !py-1.5" wire:click="shiftCalendar(-1)" @disabled(! $calendar['previous']) aria-label="Previous month"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                        <h2 class="!mb-0 text-sm font-semibold text-slate-900" aria-live="polite">{{ $calendar['month']->format('F Y') }}</h2>
                        <button type="button" class="ui-button ui-button-secondary !px-2.5 !py-1.5" wire:click="shiftCalendar(1)" @disabled(! $calendar['next']) aria-label="Next month"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                    </div>
                    <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">
                        @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $name)<div class="px-1 py-1.5">{{ $name }}</div>@endforeach
                    </div>
                    @foreach($calendar['weeks'] as $week)
                        <div class="grid grid-cols-7 border-b border-slate-100 last:border-b-0">
                            @foreach($week as $cell)
                                @if(! $cell)
                                    <div class="min-h-[5.5rem] border-r border-slate-100 bg-slate-50/60 last:border-r-0"></div>
                                @else
                                    @php $c = $cell['counts']; @endphp
                                    <button type="button" wire:click="openDay('{{ $cell['key'] }}')" wire:key="cal-{{ $cell['key'] }}" @disabled(! $cell['shown'])
                                            @class(['flex min-h-[5.5rem] flex-col items-stretch justify-start border-r border-slate-100 p-1.5 text-left last:border-r-0', 'hover:bg-slate-50' => $cell['shown'], 'cursor-default bg-slate-50/60' => ! $cell['shown'], 'ring-2 ring-inset ring-teal-600' => $cell['today']])
                                            aria-label="{{ $cell['date']->format('l j F') }}{{ $cell['shown'] ? ': '.$cell['summary'] : '' }}">
                                        <span @class(['block text-xs font-semibold', 'text-slate-800' => $cell['shown'], 'text-slate-300' => ! $cell['shown']])>{{ $cell['date']->day }}</span>
                                        @if($cell['shown'])
                                            @if($cell['holiday'])
                                                <span class="mt-1 block truncate rounded px-1 py-0.5 text-xs {{ $tone['holiday'] }}" title="{{ $cell['holiday'] }}">{{ $cell['holiday'] }}</span>
                                            @elseif($cell['state'] === 'none')
                                                <span class="mt-1 block text-xs text-slate-300">No data</span>
                                            @elseif($cell['state'] === 'off' && ! $c['in'])
                                                <span class="mt-1 block text-xs text-slate-400">Day off</span>
                                            @endif
                                            <span class="mt-1 flex flex-wrap gap-1 text-xs font-medium">
                                                @if($c['in'])<span class="rounded px-1 py-0.5 {{ $tone['on_time'] }}">{{ $c['in'] }} in</span>@endif
                                                @if($c['late'])<span class="rounded px-1 py-0.5 {{ $tone['late'] }}">{{ $c['late'] }} late</span>@endif
                                                @if($c['absent'])<span class="rounded px-1 py-0.5 {{ $tone['absent'] }}">{{ $c['absent'] }} absent</span>@endif
                                                @if($c['leave'])<span class="rounded px-1 py-0.5 {{ $tone['leave'] }}">{{ $c['leave'] }} on leave</span>@endif
                                                @if($c['flags'])<span class="rounded px-1 py-0.5 text-slate-600 ring-1 ring-inset ring-slate-400">{{ $c['flags'] }} to check</span>@endif
                                            </span>
                                        @endif
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-200 px-3 py-2 text-xs text-slate-600">
                        <span>Click a day to see everyone's punches. "In" counts everyone who came, late or not.</span>
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded ring-1 ring-inset ring-slate-400"></span>To check: left early, a long break or a missing punch</span>
                    </div>
                </div>
            @else
                {{-- Six months side by side: is attendance getting better or worse, and for whom. --}}
                @php
                    $trendMonths = $trends['months'];
                    $worst = max(1, max(array_map(fn ($m) => $m['late'] + $m['absent'], $trendMonths)));
                    $lateDays = max(1, max(array_map(fn ($d) => $d['present'] ? $d['late'] / $d['present'] : 0, $trends['weekdays'])));
                @endphp
                <div class="grid gap-4 xl:grid-cols-3">
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm xl:col-span-2">
                        <h2 class="!mb-0 border-b border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">Month by month</h2>
                        <div class="ui-table-wrap">
                            <table class="table ui-table ui-table-sm mb-0">
                                <thead><tr><th>Month</th><th class="text-right">Attendance</th><th class="text-right">Late</th><th class="text-right">Absent</th><th class="w-[28%]">Late and absent</th><th class="text-right">Time late</th><th class="text-right">Leave days</th><th class="text-right">Overtime</th></tr></thead>
                                <tbody>
                                    @foreach($trendMonths as $key => $month)
                                        <tr wire:key="trend-{{ $key }}">
                                            <td class="whitespace-nowrap font-medium text-slate-800">{{ $month['label'] }}</td>
                                            @if($month['expected'])
                                                <td class="text-right ui-number"><span @class(['rounded px-1.5 py-0.5 font-semibold', 'bg-emerald-50 text-emerald-800' => $month['rate'] >= 95, 'bg-amber-100 text-amber-900' => $month['rate'] < 95 && $month['rate'] >= 85, 'bg-red-100 text-red-800' => $month['rate'] < 85])>{{ $month['rate'] }}%</span></td>
                                                <td class="text-right ui-number">{{ $month['late'] }}</td>
                                                <td class="text-right ui-number">{{ $month['absent'] }}</td>
                                                <td>
                                                    <span class="flex h-3 w-full overflow-hidden rounded bg-slate-100" role="img" aria-label="{{ $month['late'] }} late, {{ $month['absent'] }} absent">
                                                        <span class="bg-amber-400" style="width: {{ round($month['late'] / $worst * 100, 1) }}%"></span><span class="bg-red-400" style="width: {{ round($month['absent'] / $worst * 100, 1) }}%"></span>
                                                    </span>
                                                </td>
                                                <td class="text-right ui-number">{{ $month['late_minutes'] ? AttendanceAnalyzer::duration($month['late_minutes']) : '–' }}</td>
                                                <td class="text-right ui-number">{{ $month['leave'] ?: '–' }}</td>
                                                <td class="text-right ui-number">{{ $month['overtime_minutes'] ? AttendanceAnalyzer::duration($month['overtime_minutes']) : '–' }}</td>
                                            @else
                                                <td colspan="7" class="text-sm text-slate-400">Nothing recorded</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="!mb-0 border-t border-slate-200 px-3 py-2 text-xs text-slate-500"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-amber-400"></span>Late arrivals <span class="ml-3 mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-red-400"></span>Absences. Attendance is days present out of days expected. Overtime is what was approved.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                        <h2 class="!mb-2 text-sm font-semibold text-slate-900">Lateness by weekday</h2>
                        @foreach($trends['weekdays'] as $weekday => $count)
                            @continue(! $count['present'])
                            @php $share = $count['late'] / $count['present']; @endphp
                            <div class="mb-1.5 flex items-center gap-2 text-xs">
                                <span class="w-9 font-medium text-slate-700">{{ \App\Services\Attendance\AttendanceTrends::WEEKDAYS[$weekday] }}</span>
                                <span class="h-3 flex-1 overflow-hidden rounded bg-slate-100"><span class="block h-3 bg-amber-400" style="width: {{ round($share / $lateDays * 100, 1) }}%"></span></span>
                                <span class="w-24 text-right tabular-nums text-slate-600">{{ round($share * 100) }}% · {{ $count['late'] }} of {{ $count['present'] }}</span>
                            </div>
                        @endforeach
                        <p class="!mb-0 mt-2 text-xs text-slate-500">Of the times people came in on each weekday over these months, how often they were late.</p>
                    </div>
                </div>
                <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="!mb-0 border-b border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">Person by person</h2>
                    <div class="ui-table-wrap">
                        <table class="table ui-table ui-table-sm mb-0">
                            <thead><tr><th>Staff</th>@foreach($trendMonths as $month)<th class="text-center">{{ \Illuminate\Support\Str::before($month['label'], ' ') }}</th>@endforeach<th class="text-right">Late</th><th class="text-right">Absent</th><th class="text-right">Time late</th><th>Latest month</th></tr></thead>
                            <tbody>
                                @forelse($trends['people'] as $person)
                                    <tr wire:key="trend-person-{{ $person['staff']->id }}">
                                        <td class="font-medium text-slate-800"><button type="button" class="text-left underline decoration-slate-300 hover:decoration-slate-600" wire:click="openStaff({{ $person['staff']->id }})">{{ $person['staff']->name }}</button></td>
                                        @foreach($trendMonths as $key => $month)
                                            @php $cell = $person['months'][$key] ?? null; @endphp
                                            <td class="text-center text-xs tabular-nums" title="{{ $month['label'] }}">
                                                @if(! $cell || $cell['rate'] === null)<span class="text-slate-300">–</span>
                                                @else<span @class(['rounded px-1.5 py-0.5', 'bg-emerald-50 text-emerald-800' => ! $cell['late'] && ! $cell['absent'], 'bg-amber-100 text-amber-900' => ($cell['late'] || $cell['absent']) && $cell['rate'] >= 85, 'bg-red-100 text-red-800' => $cell['rate'] < 85])>{{ $cell['late'] }}L {{ $cell['absent'] }}A</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-right ui-number">{{ $person['late'] }}</td>
                                        <td class="text-right ui-number">{{ $person['absent'] }}</td>
                                        <td class="text-right ui-number">{{ $person['late_minutes'] ? AttendanceAnalyzer::duration($person['late_minutes']) : '–' }}</td>
                                        <td class="whitespace-nowrap text-xs">
                                            @if($person['change'] > 0)<span class="font-medium text-red-700"><i class="fas fa-arrow-up" aria-hidden="true"></i> {{ $person['change'] }} more than the month before</span>
                                            @elseif($person['change'] < 0)<span class="font-medium text-emerald-700"><i class="fas fa-arrow-down" aria-hidden="true"></i> {{ abs($person['change']) }} fewer than the month before</span>
                                            @else<span class="text-slate-400">No change</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ count($trendMonths) + 5 }}" class="py-6 text-center text-slate-500">Nobody is followed yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="!mb-0 border-t border-slate-200 px-3 py-2 text-xs text-slate-500">Each month shows late arrivals (L) and absences (A). People with the most of both come first. The six months end at the last day picked above.</p>
                </div>
            @endif

            <p class="!mb-0 mt-3 text-xs text-slate-500">
                Punches are read in order as in, out, in, out. Late means arriving more than {{ $settings->grace_minutes }} minutes after the start of the working day; a break is long when it is over {{ AttendanceAnalyzer::duration($settings->break_minutes ?? 60) }}.
            </p>
        @endif
    </div>

    @if($showUpload)
        <x-ui.modal title="Upload attendance report" state="showUpload">
            <form wire:submit="importReport" class="space-y-4 p-4">
                <p class="!mb-0 text-sm text-slate-600">On the terminal, export the <strong>Attendance Record</strong> report for the month to a USB drive, and choose that .xls file here. You can upload the same month again as it fills up; only new punches are added.</p>
                <div>
                    <label for="attendance-file" class="mb-1 block text-sm font-medium text-slate-700">Report file (.xls)</label>
                    <input id="attendance-file" type="file" accept=".xls,application/vnd.ms-excel" wire:model="file" class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                    <p wire:loading wire:target="file" class="!mb-0 mt-1 text-sm text-slate-500" role="status">Reading the file…</p>
                    @error('file')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-center justify-end gap-2">
                    <x-ui.button wire:click="$set('showUpload', false)">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="importReport,file">Upload</x-ui.button>
                </div>
                @if($imports->isNotEmpty())
                    <div class="border-t border-slate-200 pt-3">
                        <p class="!mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Recent uploads</p>
                        <ul class="!mb-0 list-none space-y-1 !pl-0 text-sm text-slate-600">
                            @foreach($imports as $import)
                                <li>{{ $import->period_start->format('M Y') }} · report made {{ $import->exported_at->format('j M Y g:i A') }} · {{ $import->new_punches }} new · {{ $import->uploader->name ?? 'Unknown' }}, {{ $import->created_at->diffForHumans() }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </form>
        </x-ui.modal>
    @endif

    @if($showHours)
        <x-ui.modal title="Working hours" state="showHours">
            <form wire:submit="saveHours" class="space-y-3 p-4">
                <p class="!mb-0 text-sm text-slate-600">Tick the days this branch works and when the day starts and ends. Unticked days are days off. Staff who work different hours can be given their own: open the person from the list.</p>
                @include('livewire.admin.partials.attendance-hours', ['model' => 'hours', 'values' => $hours])
                <div>
                    <label for="attendance-grace" class="mb-1 block text-sm font-medium text-slate-700">Grace period (minutes)</label>
                    <input id="attendance-grace" type="number" min="0" max="240" class="ui-input !w-32" wire:model="graceMinutes">
                    <p class="!mb-0 mt-1 text-xs text-slate-500">Arriving within this many minutes of the start still counts as on time.</p>
                    @error('graceMinutes')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="attendance-break" class="mb-1 block text-sm font-medium text-slate-700">Break allowed (minutes)</label>
                    <input id="attendance-break" type="number" min="0" max="480" class="ui-input !w-32" wire:model="breakMinutes">
                    <p class="!mb-0 mt-1 text-xs text-slate-500">Staff punch out when they leave and in when they return. Time out longer than this in a day is flagged.</p>
                    @error('breakMinutes')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="attendance-overtime-after" class="mb-1 block text-sm font-medium text-slate-700">Overtime starts after (minutes)</label>
                    <input id="attendance-overtime-after" type="number" min="1" max="480" class="ui-input !w-32" wire:model="overtimeAfter">
                    <p class="!mb-0 mt-1 text-xs text-slate-500">Staying at least this long past the end of the day is overtime, counted from the end of the day. Time on site on a day off or a holiday is overtime too.</p>
                    @error('overtimeAfter')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    <label class="mt-2 flex items-center gap-2 text-sm text-slate-800"><input type="checkbox" wire:model="overtimeNeedsApproval"> Overtime counts only when a manager approves it</label>
                </div>
                <div class="flex items-center justify-end gap-2">
                    <x-ui.button wire:click="$set('showHours', false)">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveHours">Save</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if($showDevices)
        @php
            $base = parse_url(url('/'));
            $secure = ($base['scheme'] ?? 'http') === 'https';
        @endphp
        <x-ui.modal title="Terminals" state="showDevices">
            <div class="space-y-4 p-4">
                <p class="!mb-0 text-sm text-slate-600">A connected terminal sends each punch here as it happens, so there is nothing to export. Add the terminal, then enter its settings on the terminal's own web page under <strong>HTTP Listening</strong> (in the network settings).</p>
                <form wire:submit="addDevice" class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[14rem] flex-1">
                        <label for="device-name" class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                        <input id="device-name" type="text" maxlength="100" class="ui-input !py-1.5 !text-sm" wire:model="deviceName" placeholder="Front door terminal">
                    </div>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="addDevice">Add terminal</x-ui.button>
                    @error('deviceName')<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
                </form>
                @forelse($devices as $device)
                    <div class="rounded-lg border border-slate-200 p-3" wire:key="device-{{ $device->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <strong class="text-sm text-slate-900">{{ $device->name }}</strong>
                                @if($device->isOnline())
                                    <span class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-800">Connected</span>
                                @elseif($device->last_seen_at)
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">Quiet since {{ $device->last_seen_at->diffForHumans() }}</span>
                                @else
                                    <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-600">Nothing received yet</span>
                                @endif
                                <p class="!mb-0 text-xs text-slate-500">{{ number_format($device->punches_received) }} {{ $device->punches_received === 1 ? 'punch' : 'punches' }} received{{ $device->last_event_at ? ' · latest event '.$device->last_event_at->format('j M Y g:i A') : '' }}</p>
                            </div>
                            <button type="button" class="ui-button ui-button-danger !px-2 !py-1 !text-xs" wire:click="removeDevice({{ $device->id }})" wire:confirm="Remove this terminal? It will stop being accepted. Punches already received are kept.">Remove</button>
                        </div>
                        <dl class="!mb-0 mt-2 grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1 rounded bg-slate-50 p-2 text-xs">
                            <dt class="text-slate-500">Protocol</dt><dd class="!mb-0 font-mono text-slate-800">{{ $secure ? 'HTTPS' : 'HTTP' }}</dd>
                            <dt class="text-slate-500">IP / domain</dt><dd class="!mb-0 font-mono text-slate-800">{{ $base['host'] ?? '' }}</dd>
                            <dt class="text-slate-500">Port</dt><dd class="!mb-0 font-mono text-slate-800">{{ $base['port'] ?? ($secure ? 443 : 80) }}</dd>
                            <dt class="text-slate-500">URL</dt><dd class="!mb-0 break-all font-mono text-slate-800">{{ $device->pushPath() }}</dd>
                        </dl>
                    </div>
                @empty
                    <p class="!mb-0 text-sm text-slate-500">No terminal connected yet.</p>
                @endforelse
                <p class="!mb-0 text-xs text-slate-500">Each person's Employee ID on the terminal identifies them here. Keep the terminal's clock right: its time is what is recorded. Treat the URL like a password.</p>
            </div>
        </x-ui.modal>
    @endif

    @if($showHolidays)
        <x-ui.modal title="Holidays" state="showHolidays">
            <div class="space-y-4 p-4">
                <p class="!mb-0 text-sm text-slate-600">Days this branch is closed. Nobody is marked absent on a holiday.</p>
                <form wire:submit="addHoliday" class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="holiday-date" class="mb-1 block text-xs font-medium text-slate-600">Date</label>
                        <input id="holiday-date" type="date" class="ui-input !w-44 !py-1.5 !text-sm" wire:model="holidayDate">
                    </div>
                    <div class="min-w-[12rem] flex-1">
                        <label for="holiday-name" class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                        <input id="holiday-name" type="text" maxlength="100" class="ui-input !py-1.5 !text-sm" wire:model="holidayName" placeholder="Independence Day">
                    </div>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="addHoliday">Add</x-ui.button>
                    @error('holidayDate')<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
                    @error('holidayName')<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
                </form>
                <div>
                    <p class="!mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Holidays in {{ $last->year }}</p>
                    @forelse($holidayList as $holiday)
                        <div class="flex items-center justify-between gap-2 border-t border-slate-100 py-1.5 text-sm" wire:key="holiday-{{ $holiday->id }}">
                            <span><strong>{{ $holiday->date->format('D j M') }}</strong> · {{ $holiday->name }}</span>
                            <button type="button" class="ui-button ui-button-danger !px-2 !py-1 !text-xs" wire:click="removeHoliday({{ $holiday->id }})" wire:confirm="Remove this holiday?">Remove</button>
                        </div>
                    @empty
                        <p class="!mb-0 text-sm text-slate-500">None added yet.</p>
                    @endforelse
                </div>
            </div>
        </x-ui.modal>
    @endif

    @if($showShifts)
        <x-ui.modal title="Shifts" state="showShifts">
            <div class="space-y-4 p-4">
                <p class="!mb-0 text-sm text-slate-600">Name the shifts people work at this branch. An end time earlier than the start is the next morning, for night shifts. Give people a shift per weekday on their own page, or set single days on the roster.</p>
                <div class="ui-table-wrap">
                    <table class="table ui-table ui-table-sm mb-0">
                        <thead><tr><th>Shift</th><th>Hours</th><th>Break allowed</th><th class="text-right">Action</th></tr></thead>
                        <tbody>
                            @forelse($shifts as $shift)
                                <tr wire:key="shift-{{ $shift->id }}" @class(['text-slate-400' => ! $shift->is_active])>
                                    <td class="font-medium">{{ $shift->name }}@unless($shift->is_active) <span class="text-xs font-normal">(not offered)</span>@endunless</td>
                                    <td class="whitespace-nowrap">{{ AttendanceAnalyzer::time($shift->starts_at) }} to {{ AttendanceAnalyzer::time($shift->ends_at) }}@if($shift->overnight()) <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-800">next morning</span>@endif</td>
                                    <td>{{ $shift->break_minutes !== null ? AttendanceAnalyzer::duration($shift->break_minutes) : 'Branch allowance' }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        <button type="button" wire:click="toggleShift({{ $shift->id }})" class="text-xs text-teal-700 underline">{{ $shift->is_active ? 'Stop offering' : 'Offer again' }}</button>
                                        <button type="button" wire:click="removeShift({{ $shift->id }})" wire:confirm="Delete the {{ $shift->name }} shift?" class="ml-2 text-xs text-red-700 underline">Delete</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-sm text-slate-500">No shifts yet. Everyone follows the branch's working hours.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <form wire:submit="addShift" class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <div class="min-w-[10rem] flex-1"><label for="shift-name" class="mb-1 block text-xs font-semibold text-slate-600">Name</label><input id="shift-name" type="text" maxlength="60" wire:model="shiftName" placeholder="e.g. Morning" class="ui-input w-full"></div>
                    <div><label for="shift-start" class="mb-1 block text-xs font-semibold text-slate-600">Starts</label><input id="shift-start" type="time" wire:model="shiftStart" class="ui-input !w-32"></div>
                    <div><label for="shift-end" class="mb-1 block text-xs font-semibold text-slate-600">Ends</label><input id="shift-end" type="time" wire:model="shiftEnd" class="ui-input !w-32"></div>
                    <div><label for="shift-break" class="mb-1 block text-xs font-semibold text-slate-600">Break (minutes)</label><input id="shift-break" type="number" min="0" max="600" wire:model="shiftBreak" placeholder="Branch" class="ui-input !w-28"></div>
                    <x-ui.button type="submit" variant="primary">Add shift</x-ui.button>
                    @foreach(['shiftName', 'shiftStart', 'shiftEnd', 'shiftBreak'] as $field)@error($field)<p class="!mb-0 w-full text-xs text-red-600">{{ $message }}</p>@enderror @endforeach
                </form>
            </div>
        </x-ui.modal>
    @endif

    @if($showRoster && $rosterGrid)
        <style>.clinic-ui .ui-dialog:has(.attendance-roster) { width: min(1100px, calc(100% - 32px)); }</style>
        <x-ui.modal title="Roster" state="showRoster">
            <div class="attendance-roster space-y-3 p-4">
                @php $rosterDays = array_values($rosterGrid['days']); @endphp
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" wire:click="shiftRosterWeek(-1)" aria-label="Previous week">‹</x-ui.button>
                    <strong class="text-sm text-slate-800">Week of {{ $rosterDays[0]->format('j M') }} to {{ $rosterDays[6]->format('j M Y') }}</strong>
                    <x-ui.button type="button" wire:click="shiftRosterWeek(1)" aria-label="Next week">›</x-ui.button>
                    <span class="text-xs text-slate-500">Each box shows what the person is due to work. Change one to set that day only; "As usual" goes back to their weekly shifts or hours.</span>
                </div>
                @if($shifts->isEmpty())<p class="!mb-0 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">Add shifts first (Shifts button), then roster people onto them here.</p>@endif
                <div class="ui-table-wrap">
                    <table class="table ui-table ui-table-sm mb-0">
                        <thead><tr><th>Staff</th>@foreach($rosterDays as $day)<th class="text-center">{{ $day->format('D j') }}@if(isset($rosterGrid['holidays'][$day->format('Y-m-d')]))<span class="block text-[10px] font-normal text-slate-500">Holiday</span>@endif</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach($rosterGrid['rows'] as $row)
                                <tr wire:key="roster-{{ $row['staff']->id }}">
                                    <td class="whitespace-nowrap font-medium text-slate-800">{{ $row['staff']->name }}</td>
                                    @foreach($rosterDays as $day)
                                        @php
                                            $key = $day->format('Y-m-d');
                                            $due = $row['days'][$key]['hours'];
                                            $chosen = $rosterGrid['set'][$row['staff']->id][$key] ?? '';
                                        @endphp
                                        <td class="text-center align-top">
                                            <select class="ui-input !py-1 !text-xs {{ $chosen !== '' ? '!border-teal-600 !bg-teal-50' : '' }}" aria-label="{{ $row['staff']->name }} on {{ $day->format('l j F') }}"
                                                    wire:change="setRoster({{ $row['staff']->id }}, '{{ $key }}', $event.target.value)" wire:key="roster-{{ $row['staff']->id }}-{{ $key }}-{{ $chosen }}">
                                                <option value="" @selected($chosen === '')>As usual</option>
                                                @foreach($shifts as $shift)
                                                    @if($shift->is_active || $chosen === (string) $shift->id)<option value="{{ $shift->id }}" @selected($chosen === (string) $shift->id)>{{ $shift->name }}</option>@endif
                                                @endforeach
                                                <option value="0" @selected($chosen === '0')>Day off</option>
                                            </select>
                                            <span class="mt-0.5 block text-[10px] leading-tight text-slate-500">@if($due){{ $due['shift'] ?? 'Hours' }} {{ AttendanceAnalyzer::time($due['start']) }}–{{ AttendanceAnalyzer::time($due['end']) }}@else Off @endif</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </x-ui.modal>
    @endif

    @if($showPunches && $punchDay)
        <style>.clinic-ui .ui-dialog:has(.attendance-punches) { width: min(720px, calc(100% - 32px)); }</style>
        <x-ui.modal :title="'Correct punches · '.$punchDay['staff']->name.' · '.$punchDay['date']->format('D j M Y')" state="showPunches">
            <div class="attendance-punches space-y-4 p-4">
                @if($punchDay['day'])
                    @php $counted = $punchDay['day']; @endphp
                    <p class="!mb-0 text-sm text-slate-700">Now counted as
                        <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $counted['status'] === 'none' ? 'bg-slate-100 text-slate-600' : $tone[$counted['status']] }}">{{ AttendanceAnalyzer::STATUSES[$counted['status']] }}</span>
                        @if($counted['minutes_worked'] !== null) · {{ AttendanceAnalyzer::duration($counted['minutes_worked']) }} on site @endif
                        @if($counted['no_clock_out']) · <span class="text-amber-800">a punch is missing</span>@endif
                    </p>
                @endif

                <div class="ui-table-wrap">
                    <table class="table ui-table ui-table-sm mb-0">
                        <thead><tr><th>Time</th><th>From</th><th>Note</th><th class="text-right">Action</th></tr></thead>
                        <tbody>
                            @forelse($punchDay['punches'] as $punch)
                                <tr wire:key="punch-{{ $punch->id }}" @class(['text-slate-400' => $punch->voided_at])>
                                    <td class="whitespace-nowrap tabular-nums @if($punch->voided_at) line-through @endif">{{ $punch->punched_at->format('g:i A') }}</td>
                                    <td class="whitespace-nowrap">{{ \App\Models\AttendancePunch::SOURCES[$punch->source] ?? 'Terminal' }}</td>
                                    <td class="text-xs">
                                        @if($punch->source === 'manual')Added by {{ $punch->creator?->name ?? 'a manager' }}: {{ $punch->reason }}@endif
                                        @if($punch->source === 'phone' && $punch->latitude !== null)
                                            {{ \App\Services\Attendance\AttendancePhoneClock::metres((int) $punch->distance) }} from the branch{{ $punch->accuracy !== null ? ' (phone sure to within '.$punch->accuracy.' m)' : '' }} ·
                                            <a class="text-teal-700 underline" target="_blank" rel="noopener" href="https://www.google.com/maps?q={{ $punch->latitude }},{{ $punch->longitude }}">map</a>
                                        @endif
                                        @if($punch->voided_at)<span class="block">Removed by {{ $punch->voider?->name ?? 'a manager' }} on {{ $punch->voided_at->setTimezone($timezone)->format('j M, g:i A') }}: {{ $punch->void_reason }}</span>@endif
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        @unless($punch->voided_at)
                                            <button type="button" wire:click="editPunch({{ $punch->id }})" class="text-xs text-teal-700 underline">Change time</button>
                                            <button type="button" wire:click="removePunch({{ $punch->id }})" wire:confirm="Remove the {{ $punch->punched_at->format('g:i A') }} punch? It stays in the history as removed." class="ml-2 text-xs text-red-700 underline">Remove</button>
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-sm text-slate-500">No punches on this day.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <form wire:submit="savePunch" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="!mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $punchEditId ? 'Change the time of a punch' : 'Add a missed punch' }}</p>
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label for="punch-time" class="mb-1 block text-xs font-semibold text-slate-600">{{ $punchEditId ? 'Correct time' : 'Time' }}</label>
                            <input id="punch-time" type="time" wire:model="punchTime" class="ui-input !w-32">
                        </div>
                        <div class="min-w-[14rem] flex-1">
                            <label for="punch-reason" class="mb-1 block text-xs font-semibold text-slate-600">Reason (also needed to remove a punch)</label>
                            <input id="punch-reason" type="text" wire:model="punchReason" maxlength="255" placeholder="e.g. Forgot to clock out, left at 5 PM" class="ui-input w-full">
                        </div>
                        <x-ui.button type="submit" variant="primary">{{ $punchEditId ? 'Save new time' : 'Add punch' }}</x-ui.button>
                        @if($punchEditId)<x-ui.button type="button" wire:click="cancelPunchEdit">Cancel</x-ui.button>@endif
                    </div>
                    @error('punchTime')<p class="!mb-0 mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('punchReason')<p class="!mb-0 mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="!mb-0 mt-2 text-xs text-slate-500">Nothing is deleted: a removed or changed punch stays here, crossed out, with who changed it and why. Every correction is in the audit trail.</p>
                </form>

                @if($punchBack)<div class="text-right"><x-ui.button type="button" wire:click="closePunches">Back to {{ $punchBack === 'day' ? 'the day' : $punchDay['staff']->name }}</x-ui.button></div>@endif
            </div>
        </x-ui.modal>
    @endif

    @if($showOvertime)
        <style>.clinic-ui .ui-dialog:has(.attendance-overtime) { width: min(900px, calc(100% - 32px)); }</style>
        <x-ui.modal :title="'Overtime · '.$periodLabel" state="showOvertime">
            <div class="attendance-overtime space-y-3 p-4">
                <p class="!mb-0 text-sm text-slate-600">
                    Staying {{ AttendanceAnalyzer::duration($settings->overtime_after_minutes ?? 30) }} or more past the end of the working day is overtime, as is time on site on a day off or a holiday.
                    @if($settings->overtime_needs_approval ?? true) It counts only once you approve it; you can approve fewer minutes than were worked. @else It counts by itself here; turn a day down if it should not. @endif
                    Change the rule under Working hours.
                </p>
                @forelse($overtime as $item)
                    @php $day = $item['day']; $decision = $item['decision']; @endphp
                    <div class="rounded-lg border border-slate-200 p-3" wire:key="overtime-{{ $item['key'] }}-{{ $day['overtime_status'] }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="!mb-0 text-sm"><strong class="text-slate-900">{{ $item['staff']->name }}</strong> · {{ $item['date']->format('D j M Y') }}</p>
                            <span @class(['rounded px-1.5 py-0.5 text-xs font-medium', 'bg-teal-50 text-teal-800' => $day['overtime_status'] === 'approved', 'bg-amber-100 text-amber-900' => $day['overtime_status'] === 'pending', 'bg-slate-100 text-slate-600' => $day['overtime_status'] === 'declined'])>
                                {{ ['pending' => 'Waiting', 'approved' => 'Approved '.AttendanceAnalyzer::duration($day['overtime_approved']), 'declined' => 'Not approved'][$day['overtime_status']] ?? '' }}
                            </span>
                        </div>
                        <p class="!mb-0 text-sm text-slate-700">
                            {{ AttendanceAnalyzer::duration($day['overtime_minutes']) }} overtime ·
                            @if($day['status'] === 'worked_off') on site {{ AttendanceAnalyzer::time($day['in']) }} to {{ AttendanceAnalyzer::time($day['out']) }} on {{ $day['note'] ?: 'a day off' }}
                            @else left at {{ AttendanceAnalyzer::time($day['out']) }}, the day ends at {{ AttendanceAnalyzer::time($day['hours']['end']) }}@if($day['hours']['shift'] ?? null) ({{ $day['hours']['shift'] }} shift)@endif
                            @endif
                        </p>
                        @if($decision)
                            <p class="!mb-0 mt-1 text-xs text-slate-500">{{ $decision->decider->name ?? 'A manager' }}, {{ $decision->updated_at->diffForHumans() }}@if($decision->note) · “{{ $decision->note }}”@endif
                                <button type="button" class="ml-1 text-teal-700 underline" wire:click="reopenOvertime({{ $item['staff']->id }}, '{{ $item['date']->format('Y-m-d') }}')">Change</button></p>
                        @else
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <label class="text-xs text-slate-600" for="overtime-minutes-{{ $item['key'] }}">Minutes to approve</label>
                                <input id="overtime-minutes-{{ $item['key'] }}" type="number" min="1" max="{{ $day['overtime_minutes'] }}" class="ui-input !w-24 !py-1.5 !text-sm" wire:model="overtimeMinutes.{{ $item['key'] }}" placeholder="{{ $day['overtime_minutes'] }}">
                                <input type="text" maxlength="255" class="ui-input min-w-[10rem] flex-1 !py-1.5 !text-sm" wire:model="overtimeNotes.{{ $item['key'] }}" placeholder="Note (optional)" aria-label="Note">
                                <button type="button" class="ui-button ui-button-secondary !py-1.5" wire:click="decideOvertime({{ $item['staff']->id }}, '{{ $item['date']->format('Y-m-d') }}', false)" wire:loading.attr="disabled" wire:target="decideOvertime">Don't approve</button>
                                <button type="button" class="ui-button ui-button-primary !py-1.5" wire:click="decideOvertime({{ $item['staff']->id }}, '{{ $item['date']->format('Y-m-d') }}', true)" wire:loading.attr="disabled" wire:target="decideOvertime">Approve</button>
                            </div>
                            @error('overtimeMinutes.'.$item['key'])<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        @endif
                    </div>
                @empty
                    <p class="!mb-0 text-sm text-slate-500">Nobody worked overtime in {{ $periodLabel }}.</p>
                @endforelse
            </div>
        </x-ui.modal>
    @endif

    @if($showLeaveTypes)
        <x-ui.modal title="Leave types" state="showLeaveTypes">
            <div class="space-y-4 p-4">
                <p class="!mb-0 text-sm text-slate-600">The kinds of leave at this branch and how many working days of each a person may take in a calendar year. Leave the days empty for no limit. Days off and holidays inside a leave are not counted; part of a day counts by its share of the day.</p>
                <div class="ui-table-wrap">
                    <table class="table ui-table ui-table-sm mb-0">
                        <thead><tr><th>Type</th><th class="text-right">Days a year</th><th>Paid</th><th class="text-right">Action</th></tr></thead>
                        <tbody>
                            @foreach($leaveTypes as $type)
                                <tr wire:key="leave-type-{{ $type->id }}" @class(['text-slate-400' => ! $type->is_active])>
                                    <td class="font-medium">{{ $type->name }}@unless($type->is_active) <span class="text-xs font-normal">(not offered)</span>@endunless</td>
                                    <td class="text-right ui-number">{{ $type->days_per_year === null ? 'No limit' : rtrim(rtrim(number_format($type->days_per_year, 1), '0'), '.') }}</td>
                                    <td>{{ $type->is_paid ? 'Paid' : 'Unpaid' }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        <button type="button" wire:click="editLeaveType({{ $type->id }})" class="text-xs text-teal-700 underline">Edit</button>
                                        <button type="button" wire:click="toggleLeaveType({{ $type->id }})" class="ml-2 text-xs text-teal-700 underline">{{ $type->is_active ? 'Stop offering' : 'Offer again' }}</button>
                                        <button type="button" wire:click="removeLeaveType({{ $type->id }})" wire:confirm="Delete {{ $type->name }}?" class="ml-2 text-xs text-red-700 underline">Delete</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <form wire:submit="saveLeaveType" class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="!mb-0 w-full text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $leaveTypeEditId ? 'Change this type' : 'Add a type' }}</p>
                    <div class="min-w-[10rem] flex-1"><label for="leave-type-name" class="mb-1 block text-xs font-semibold text-slate-600">Name</label><input id="leave-type-name" type="text" maxlength="60" wire:model="leaveTypeName" placeholder="e.g. Maternity leave" class="ui-input w-full"></div>
                    <div><label for="leave-type-days" class="mb-1 block text-xs font-semibold text-slate-600">Days a year</label><input id="leave-type-days" type="number" min="0" max="366" step="0.5" wire:model="leaveTypeDays" placeholder="No limit" class="ui-input !w-28"></div>
                    <label class="flex items-center gap-2 pb-2 text-sm text-slate-800"><input type="checkbox" wire:model="leaveTypePaid"> Paid</label>
                    <x-ui.button type="submit" variant="primary">{{ $leaveTypeEditId ? 'Save' : 'Add type' }}</x-ui.button>
                    @if($leaveTypeEditId)<x-ui.button type="button" wire:click="openLeaveTypes">Cancel</x-ui.button>@endif
                    @foreach(['leaveTypeName', 'leaveTypeDays'] as $field)@error($field)<p class="!mb-0 w-full text-xs text-red-600">{{ $message }}</p>@enderror @endforeach
                </form>
            </div>
        </x-ui.modal>
    @endif

    @if($showPayroll && $payroll)
        <style>.clinic-ui .ui-dialog:has(.attendance-payroll) { width: min(1150px, calc(100% - 32px)); }</style>
        <x-ui.modal :title="'Payroll · '.$periodLabel" state="showPayroll">
            <form wire:submit="savePayroll" class="attendance-payroll space-y-3 p-4">
                <p class="!mb-0 text-sm text-slate-600">Pay worked out from attendance for the days on show, before tax and pension. Pick a whole month for a month's pay. Only the owner sees this window.</p>
                <div class="flex flex-wrap items-end gap-x-5 gap-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <div>
                        <label for="pay-overtime-rate" class="mb-1 block text-xs font-semibold text-slate-600">Overtime is paid at (times the normal rate)</label>
                        <input id="pay-overtime-rate" type="number" min="1" max="5" step="0.05" class="ui-input !w-28" wire:model="overtimeMultiplier">
                    </div>
                    <label class="flex items-center gap-2 pb-2 text-sm text-slate-800"><input type="checkbox" wire:model="deductAbsence"> Take absences off a salary</label>
                    <label class="flex items-center gap-2 pb-2 text-sm text-slate-800"><input type="checkbox" wire:model="deductLateness"> Take lateness off a salary, by the minute</label>
                    @error('overtimeMultiplier')<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
                </div>
                @if($payroll['pending'])<p class="!mb-0 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ AttendanceAnalyzer::duration($payroll['pending']) }} of overtime is still waiting for an answer and is not paid below. Answer it under Overtime first.</p>@endif
                @if($payroll['unrecorded'])<p class="!mb-0 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $payroll['unrecorded'] }} working {{ $payroll['unrecorded'] === 1 ? 'day has' : 'days have' }} nothing recorded yet in these dates, so these figures are not final.</p>@endif
                <div class="ui-table-wrap">
                    <table class="table ui-table ui-table-sm mb-0">
                        <thead><tr><th>Staff</th><th>Paid</th><th>Rate ({{ $currency }})</th><th class="text-right">Present</th><th class="text-right">Absent</th><th class="text-right">Late</th><th class="text-right">Overtime</th>
                            <th class="text-right">Basic</th><th class="text-right">Overtime pay</th><th class="text-right">Taken off</th><th class="text-right">Gross</th></tr></thead>
                        <tbody>
                            @foreach($payroll['rows'] as $line)
                                @php $id = $line['staff']->id; @endphp
                                <tr wire:key="pay-{{ $id }}">
                                    <td class="font-medium text-slate-800">{{ $line['staff']->name }}</td>
                                    <td><select class="ui-input !w-36 !py-1 !text-sm" wire:model="pay.{{ $id }}.type" aria-label="How {{ $line['staff']->name }} is paid">
                                        <option value="">Not set</option><option value="monthly">Monthly salary</option><option value="hourly">By the hour</option></select></td>
                                    <td><input type="number" min="0" step="0.01" class="ui-input !w-28 !py-1 !text-sm" wire:model="pay.{{ $id }}.rate" aria-label="Rate for {{ $line['staff']->name }}">
                                        @error('pay.'.$id.'.rate')<p class="!mb-0 text-xs text-red-600">{{ $message }}</p>@enderror</td>
                                    <td class="text-right ui-number">{{ $line['present'] }}<span class="text-slate-400"> / {{ $line['expected'] }}</span></td>
                                    <td class="text-right ui-number">{{ $line['absent'] ?: '–' }}</td>
                                    <td class="text-right ui-number">{{ $line['late_minutes'] ? AttendanceAnalyzer::duration($line['late_minutes']) : '–' }}</td>
                                    <td class="text-right ui-number">{{ $line['overtime_minutes'] ? AttendanceAnalyzer::duration($line['overtime_minutes']) : '–' }}</td>
                                    @if($line['pay_type'])
                                        <td class="text-right ui-number">{{ number_format($line['basic'], 2) }}</td>
                                        <td class="text-right ui-number">{{ $line['overtime_pay'] ? number_format($line['overtime_pay'], 2) : '–' }}</td>
                                        <td class="text-right ui-number">{{ ($off = $line['absence'] + $line['unpaid'] + $line['lateness']) ? number_format($off, 2) : '–' }}</td>
                                        <td class="text-right ui-number font-semibold">{{ number_format($line['gross'], 2) }}</td>
                                    @else
                                        <td colspan="4" class="text-right text-sm text-slate-400">Set how they are paid</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr><td colspan="10" class="text-right font-semibold">Total gross</td><td class="text-right ui-number font-semibold">{{ number_format(array_sum(array_map(fn ($line) => $line['gross'] ?? 0, $payroll['rows'])), 2) }}</td></tr></tfoot>
                    </table>
                </div>
                <p class="!mb-0 text-xs text-slate-500">On a salary, a working day is worth the month's salary divided by that month's working days. By the hour, people are paid for hours on site up to the end of the day, plus approved overtime; leave and holidays are not added.</p>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a class="ui-button ui-button-secondary" href="{{ route('admin.attendance.export', ['format' => 'payroll', 'from' => $first->format('Y-m-d'), 'to' => $last->format('Y-m-d')]) }}"><i class="fas fa-file-excel" aria-hidden="true"></i>Download for Excel</a>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="savePayroll">Save and work out again</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if($showPhone)
        <x-ui.modal title="Phone clock-in" state="showPhone">
            <div class="space-y-4 p-4">
                <form wire:submit="savePhone" class="space-y-3">
                    <p class="!mb-0 text-sm text-slate-600">Staff punch in and out from <strong>Profile → My attendance</strong> on their own phone. The punch is taken only when the phone is within the distance below of the branch, and where they were is kept on the punch. A phone's location can be faked by someone determined, so a terminal remains the stronger proof.</p>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-800"><input type="checkbox" wire:model="phoneOn"> Let staff clock in from their phones</label>
                    <div class="flex flex-wrap items-end gap-3" x-data="{ busy: false, problem: '' }">
                        <div><label for="phone-lat" class="mb-1 block text-xs font-semibold text-slate-600">Branch latitude</label><input id="phone-lat" type="text" inputmode="decimal" class="ui-input !w-40" wire:model="phoneLatitude" placeholder="5.6037"></div>
                        <div><label for="phone-lng" class="mb-1 block text-xs font-semibold text-slate-600">Branch longitude</label><input id="phone-lng" type="text" inputmode="decimal" class="ui-input !w-40" wire:model="phoneLongitude" placeholder="-0.1870"></div>
                        <button type="button" class="ui-button ui-button-secondary" :disabled="busy"
                                @click="problem = ''; if (! navigator.geolocation) { problem = 'This device cannot give a location.'; return; } busy = true;
                                    navigator.geolocation.getCurrentPosition(
                                        (p) => { $wire.set('phoneLatitude', p.coords.latitude.toFixed(7), false); $wire.set('phoneLongitude', p.coords.longitude.toFixed(7)); busy = false; },
                                        () => { problem = 'Could not read this device\'s location. Allow location for this site, or type the numbers in.'; busy = false; },
                                        { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 })">
                            <i class="fas fa-location-crosshairs" aria-hidden="true"></i><span x-text="busy ? 'Finding…' : 'Use where I am now'"></span></button>
                        <p class="!mb-0 w-full text-xs text-slate-500">Stand at the branch and press "Use where I am now", or copy the two numbers from a map (in Google Maps, press and hold on the building).</p>
                        <p class="!mb-0 w-full text-xs text-red-600" x-show="problem" x-text="problem" x-cloak></p>
                    </div>
                    <div>
                        <label for="phone-radius" class="mb-1 block text-xs font-semibold text-slate-600">How near a phone must be (metres)</label>
                        <input id="phone-radius" type="number" min="30" max="5000" class="ui-input !w-32" wire:model="phoneRadius">
                        <p class="!mb-0 mt-1 text-xs text-slate-500">150 m suits most buildings: phones are often 20 to 50 m out indoors.</p>
                    </div>
                    @foreach(['phoneLatitude', 'phoneLongitude', 'phoneRadius'] as $field)@error($field)<p class="ui-error" role="alert">{{ $message }}</p>@enderror @endforeach
                    <div class="flex justify-end"><x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="savePhone">Save</x-ui.button></div>
                </form>
                <div class="border-t border-slate-200 pt-3">
                    <p class="!mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Who can clock in by phone</p>
                    <p class="!mb-2 text-sm text-slate-600">Anyone followed here whose record is linked to their user account: {{ $phonePeople->whereNotNull('user_id')->pluck('name')->implode(', ') ?: 'nobody yet' }}.
                        @if($phonePeople->whereNull('user_id')->isNotEmpty()) Not linked yet: {{ $phonePeople->whereNull('user_id')->pluck('name')->implode(', ') }}; open the person from the list and pick their user account. @endif</p>
                    <form wire:submit="addPhonePerson" class="flex flex-wrap items-end gap-2">
                        <div>
                            <label for="phone-user" class="mb-1 block text-xs font-semibold text-slate-600">Add someone who is not on a terminal</label>
                            <select id="phone-user" class="ui-input !w-64" wire:model="phoneUserId">
                                <option value="">Choose a user</option>
                                @foreach($phoneAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach
                            </select>
                        </div>
                        <x-ui.button type="submit">Add</x-ui.button>
                        @error('phoneUserId')<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
                    </form>
                </div>
            </div>
        </x-ui.modal>
    @endif

    @if($showDay && $dayList)
        <style>.clinic-ui .ui-dialog:has(.attendance-day) { width: min(900px, calc(100% - 32px)); }</style>
        <x-ui.modal :title="$dayList['date']->format('l j F Y').($dayList['holiday'] ? ' · '.$dayList['holiday'] : '')" state="showDay">
            <div class="attendance-day p-4">
                <div class="ui-table-wrap">
                    <table class="table ui-table ui-table-sm mb-0">
                        <thead><tr><th class="w-[30%]">Staff</th><th class="w-[30%] text-center">Punches</th><th class="w-[12%] text-center">On site</th><th class="w-[10%] text-center">Break</th><th class="w-[18%] text-center">Status</th></tr></thead>
                        <tbody>
                            @foreach($dayList['people'] as $person)
                                @php $day = $person['day']; @endphp
                                <tr wire:key="day-{{ $person['staff']->id }}">
                                    <td class="font-medium text-slate-800">{{ $person['staff']->name }}
                                        <button type="button" wire:click="openPunches({{ $person['staff']->id }}, '{{ $dayList['date']->format('Y-m-d') }}')" class="ml-1 text-xs font-normal text-teal-700 underline" aria-label="Correct {{ $person['staff']->name }}'s punches">Correct</button></td>
                                    <td class="text-center">
                                        @foreach($day['punches'] as $punch)
                                            <span @class(['mr-1 inline-block whitespace-nowrap rounded px-1.5 py-0.5 text-xs tabular-nums', 'bg-emerald-50 text-emerald-800' => $punch['kind'] === 'in', 'bg-slate-100 text-slate-700' => $punch['kind'] === 'out'])>{{ $punch['kind'] === 'in' ? 'In' : 'Out' }} {{ AttendanceAnalyzer::time($punch['time']) }}</span>
                                        @endforeach
                                        @if(! $day['punches'])–@endif
                                    </td>
                                    <td class="text-center ui-number">{{ $day['minutes_worked'] !== null ? AttendanceAnalyzer::duration($day['minutes_worked']) : '–' }}</td>
                                    <td class="text-center ui-number">@if($day['break_minutes'])<span @class(['rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-900' => $day['long_break']])>{{ AttendanceAnalyzer::duration($day['break_minutes']) }}</span>@else – @endif</td>
                                    <td class="text-center">
                                        <span class="inline-block rounded px-1.5 py-0.5 text-xs font-medium {{ $day['status'] === 'none' ? 'bg-slate-100 text-slate-600' : $tone[$day['status']] }}">{{ $day['status'] === 'none' ? 'Not in yet' : AttendanceAnalyzer::STATUSES[$day['status']] }}@if($day['late_minutes']) · {{ AttendanceAnalyzer::duration($day['late_minutes']) }}@endif</span>
                                        @if($day['note'] && $day['status'] !== 'holiday')<span class="ml-1 text-xs text-slate-600">{{ $day['note'] }}</span>@endif
                                        @if($day['left_early'])<span class="ml-1 text-xs text-slate-600">Left {{ AttendanceAnalyzer::duration($day['early_minutes']) }} early</span>@endif
                                        @if($day['no_clock_out'])<span class="ml-1 text-xs text-slate-600">A punch is missing</span>@endif
                                        @if($day['long_break'])<span class="ml-1 text-xs text-slate-600">Long break</span>@endif
                                        @if($day['overtime_minutes'])<span @class(['ml-1 rounded px-1.5 py-0.5 text-xs', 'bg-teal-50 text-teal-800' => $day['overtime_status'] === 'approved', 'bg-amber-50 text-amber-900' => $day['overtime_status'] === 'pending', 'bg-slate-100 text-slate-500' => $day['overtime_status'] === 'declined'])>Overtime {{ AttendanceAnalyzer::duration($day['overtime_status'] === 'approved' ? $day['overtime_approved'] : $day['overtime_minutes']) }}{{ ['pending' => ', waiting', 'declined' => ', not approved'][$day['overtime_status']] ?? '' }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($dayList['inProgress'])<p class="!mb-0 mt-2 text-xs text-slate-500">This day is still in progress, so nobody is absent or gone early on it yet.</p>@endif
            </div>
        </x-ui.modal>
    @endif

    @if($showRequests)
        <x-ui.modal title="Leave requests" state="showRequests">
            <div class="space-y-3 p-4">
                <p class="!mb-0 text-sm text-slate-600">Time off staff have asked for from their profile. It counts on their attendance only once approved; they are told your answer by email.</p>
                @forelse($requests as $request)
                    <div class="rounded-lg border border-slate-200 p-3" wire:key="request-{{ $request->id }}">
                        <p class="!mb-0 text-sm"><strong class="text-slate-900">{{ $request->staff->name ?? 'Unknown' }}</strong> · {{ $request->typeName() }}</p>
                        <p class="!mb-0 text-sm text-slate-700">{{ $request->when() }}
                            @if($take = $requestDays[$request->id] ?? null)
                                <span @class(['ml-1 rounded px-1.5 py-0.5 text-xs', 'bg-slate-100 text-slate-700' => $take['before'] === null || $take['days'] <= $take['before'], 'bg-red-100 text-red-800' => $take['before'] !== null && $take['days'] > $take['before']])>
                                    {{ \App\Services\Attendance\AttendanceLeaveBalances::days($take['days']).($take['before'] !== null ? ', '.rtrim(rtrim(number_format(max(0, $take['before']), 2), '0'), '.').' left this year' : '') }}
                                </span>
                            @endif
                        </p>
                        @if($request->note)<p class="!mb-0 mt-1 text-sm text-slate-600">“{{ $request->note }}”</p>@endif
                        <p class="!mb-2 text-xs text-slate-400">Asked {{ $request->created_at->diffForHumans() }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" maxlength="255" class="ui-input min-w-[10rem] flex-1 !py-1.5 !text-sm" wire:model="reviewNotes.{{ $request->id }}" placeholder="Note to them (optional)" aria-label="Note">
                            <button type="button" class="ui-button ui-button-secondary !py-1.5" wire:click="reviewLeave({{ $request->id }}, false)" wire:loading.attr="disabled" wire:target="reviewLeave">Don't approve</button>
                            <button type="button" class="ui-button ui-button-primary !py-1.5" wire:click="reviewLeave({{ $request->id }}, true)" wire:loading.attr="disabled" wire:target="reviewLeave">Approve</button>
                        </div>
                    </div>
                @empty
                    <p class="!mb-0 text-sm text-slate-500">No requests are waiting.</p>
                @endforelse
            </div>
        </x-ui.modal>
    @endif

    @if($showStaff && $detail)
        {{-- Wider than the other dialogs: a day's punches need the room. --}}
        <style>.clinic-ui .ui-dialog:has(.attendance-person) { width: min(900px, calc(100% - 32px)); }</style>
        <x-ui.modal :title="$detail['staff']->name.' · '.$periodLabel" state="showStaff">
            <div class="attendance-person space-y-4 p-4">
                <form wire:submit="saveStaff" class="space-y-3 rounded-lg bg-slate-50 p-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="flex items-center gap-2 text-sm text-slate-800"><input type="checkbox" wire:model="staffTracked"> Follow this person's attendance</label>
                        <div>
                            <label for="attendance-starts" class="mb-1 block text-xs font-medium text-slate-600">Count from (optional)</label>
                            <input id="attendance-starts" type="date" class="ui-input !w-44 !py-1.5 !text-sm" wire:model="staffStartsOn">
                        </div>
                    </div>
                    @error('staffStartsOn')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    <div>
                        <label for="attendance-account" class="mb-1 block text-xs font-medium text-slate-600">User account (optional)</label>
                        <select id="attendance-account" class="ui-input !w-72 !py-1.5 !text-sm" wire:model="staffUserId">
                            <option value="">Not linked</option>
                            @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach
                        </select>
                        <p class="!mb-0 mt-1 text-xs text-slate-500">Once linked, this person sees their own attendance on their profile page.</p>
                        @error('staffUserId')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-800"><input type="checkbox" wire:model.live="staffOwnHours"> Works different hours from the branch</label>
                    @if($staffOwnHours)
                        @include('livewire.admin.partials.attendance-hours', ['model' => 'staffHours', 'values' => $staffHours])
                    @endif
                    @if($shifts->isNotEmpty())
                        <div>
                            <p class="!mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Weekly shifts</p>
                            <p class="!mb-2 text-xs text-slate-500">The shift worked on each weekday. "Usual hours" follows the hours above; the roster can change single days.</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach(\App\Models\AttendanceSetting::DAYS as $weekday => $dayName)
                                    <div>
                                        <label for="shift-week-{{ $weekday }}" class="mb-1 block text-xs font-medium text-slate-600">{{ $dayName }}</label>
                                        <select id="shift-week-{{ $weekday }}" class="ui-input !py-1.5 !text-sm" wire:model="staffShiftWeek.{{ $weekday }}">
                                            <option value="">Usual hours</option>
                                            @foreach($shifts as $shift)
                                                @if($shift->is_active || (string) $shift->id === ($staffShiftWeek[$weekday] ?? ''))<option value="{{ $shift->id }}">{{ $shift->name }}</option>@endif
                                            @endforeach
                                            <option value="0">Day off</option>
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div class="flex justify-end">
                        <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveStaff">Save</x-ui.button>
                    </div>
                </form>

                <div>
                    <p class="!mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Leave</p>
                    @if($balances)
                        <div class="mb-2 flex flex-wrap gap-1.5 text-xs" aria-label="Leave in {{ $balanceYear }}">
                            @foreach($balances as $balance)
                                <span @class(['rounded border px-2 py-1', 'border-slate-200 text-slate-700' => $balance['left'] === null || $balance['left'] > 0, 'border-red-200 bg-red-50 text-red-800' => $balance['left'] !== null && $balance['left'] <= 0])>
                                    <strong>{{ $balance['type']->name }}</strong>
                                    @if($balance['allowance'] !== null) {{ rtrim(rtrim(number_format($balance['used'], 2), '0'), '.') }} of {{ rtrim(rtrim(number_format($balance['allowance'], 1), '0'), '.') }} days taken, {{ rtrim(rtrim(number_format($balance['left'], 2), '0'), '.') }} left
                                    @else {{ rtrim(rtrim(number_format($balance['used'], 2), '0'), '.') }} days taken @endif
                                    @if($balance['pending'] > 0)<span class="text-amber-700">· {{ rtrim(rtrim(number_format($balance['pending'], 2), '0'), '.') }} waiting</span>@endif
                                </span>
                            @endforeach
                            <span class="self-center text-slate-400">{{ $balanceYear }}, working days</span>
                        </div>
                    @endif
                    <form wire:submit="addLeave" class="flex flex-wrap items-end gap-2">
                        <div>
                            <label for="leave-from" class="mb-1 block text-xs font-medium text-slate-600">First day</label>
                            <input id="leave-from" type="date" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="leaveFrom">
                        </div>
                        <div>
                            <label for="leave-to" class="mb-1 block text-xs font-medium text-slate-600">Last day</label>
                            <input id="leave-to" type="date" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="leaveTo">
                        </div>
                        <div>
                            <label for="leave-type" class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                            <select id="leave-type" class="ui-input !w-36 !py-1.5 !text-sm" wire:model="leaveType">
                                @foreach($leaveTypes->where('is_active', true) as $key => $type)<option value="{{ $key }}">{{ $type->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="leave-from-time" class="mb-1 block text-xs font-medium text-slate-600">From (part of a day)</label>
                            <input id="leave-from-time" type="time" class="ui-input !w-32 !py-1.5 !text-sm" wire:model="leaveFromTime">
                        </div>
                        <div>
                            <label for="leave-to-time" class="mb-1 block text-xs font-medium text-slate-600">To</label>
                            <input id="leave-to-time" type="time" class="ui-input !w-32 !py-1.5 !text-sm" wire:model="leaveToTime">
                        </div>
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="addLeave">Add leave</x-ui.button>
                        <p class="!mb-0 w-full text-xs text-slate-500">Leave the times empty for whole days. With times, the person is expected for the rest of that day.</p>
                        @foreach(['leaveFrom', 'leaveTo', 'leaveType', 'leaveFromTime', 'leaveToTime'] as $field)
                            @error($field)<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
                        @endforeach
                    </form>
                    @foreach($leaves as $leave)
                        <div class="mt-1 flex items-center justify-between gap-2 border-t border-slate-100 pt-1.5 text-sm" wire:key="leave-{{ $leave->id }}">
                            <span>{{ $leave->when() }} · {{ $leave->typeName() }}
                                @if($leave->status !== \App\Models\AttendanceLeave::APPROVED)<span @class(['ml-1 rounded px-1.5 py-0.5 text-xs font-medium', 'bg-amber-100 text-amber-900' => $leave->status === 'pending', 'bg-slate-100 text-slate-600' => $leave->status === 'rejected'])>{{ \App\Models\AttendanceLeave::STATUSES[$leave->status] ?? $leave->status }}</span>@endif
                            </span>
                            <button type="button" class="ui-button ui-button-danger !px-2 !py-1 !text-xs" wire:click="removeLeave({{ $leave->id }})" wire:confirm="Remove this leave?">Remove</button>
                        </div>
                    @endforeach
                </div>

                <div>
                    <p class="!mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Days, {{ $periodLabel }}</p>
                    <div class="ui-table-wrap">
                        <table class="table ui-table ui-table-sm mb-0">
                            <thead><tr><th>Day</th><th>Punches</th><th class="text-right">On site</th><th class="text-right">Break</th><th>Status</th></tr></thead>
                            <tbody>
                                @foreach($detail['days'] as $key => $day)
                                    @continue($day['status'] === 'none' || $day['status'] === 'off')
                                    <tr wire:key="detail-{{ $key }}">
                                        <td class="whitespace-nowrap">{{ $report['days'][$key]->format('D j M') }}
                                            <button type="button" wire:click="openPunches({{ $detail['staff']->id }}, '{{ $key }}')" class="ml-1 text-xs text-teal-700 underline" aria-label="Correct punches on {{ $report['days'][$key]->format('j F') }}">Correct</button></td>
                                        <td>
                                            @foreach($day['punches'] as $punch)
                                                <span @class(['mr-1 inline-block whitespace-nowrap rounded px-1.5 py-0.5 text-xs tabular-nums', 'bg-emerald-50 text-emerald-800' => $punch['kind'] === 'in', 'bg-slate-100 text-slate-700' => $punch['kind'] === 'out'])>{{ $punch['kind'] === 'in' ? 'In' : 'Out' }} {{ AttendanceAnalyzer::time($punch['time']) }}</span>
                                            @endforeach
                                            @if(! $day['punches'])–@endif
                                        </td>
                                        <td class="text-right ui-number">{{ $day['minutes_worked'] !== null ? AttendanceAnalyzer::duration($day['minutes_worked']) : '–' }}</td>
                                        <td class="text-right ui-number">@if($day['break_minutes'])<span @class(['rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-900' => $day['long_break']])>{{ AttendanceAnalyzer::duration($day['break_minutes']) }}</span>@else – @endif</td>
                                        <td>
                                            <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $tone[$day['status']] }}">{{ AttendanceAnalyzer::STATUSES[$day['status']] }}@if($day['late_minutes']) · {{ AttendanceAnalyzer::duration($day['late_minutes']) }}@endif</span>
                                            @if($day['note'])<span class="ml-1 text-xs text-slate-600">{{ $day['note'] }}</span>@endif
                                            @if($day['left_early'])<span class="ml-1 text-xs text-slate-600">Left {{ AttendanceAnalyzer::duration($day['early_minutes']) }} early</span>@endif
                                            @if($day['no_clock_out'])<span class="ml-1 text-xs text-slate-600">A punch is missing</span>@endif
                                            @if($day['long_break'])<span class="ml-1 text-xs text-slate-600">Long break</span>@endif
                                            @if($day['overtime_minutes'])<span @class(['ml-1 rounded px-1.5 py-0.5 text-xs', 'bg-teal-50 text-teal-800' => $day['overtime_status'] === 'approved', 'bg-amber-50 text-amber-900' => $day['overtime_status'] === 'pending', 'bg-slate-100 text-slate-500' => $day['overtime_status'] === 'declined'])>Overtime {{ AttendanceAnalyzer::duration($day['overtime_status'] === 'approved' ? $day['overtime_approved'] : $day['overtime_minutes']) }}{{ ['pending' => ', waiting', 'declined' => ', not approved'][$day['overtime_status']] ?? '' }}</span>@endif
                                            @if($day['in_progress'])<span class="ml-1 text-xs text-slate-400">Day in progress</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if(collect($detail['days'])->every(fn ($day) => in_array($day['status'], ['none', 'off'], true)))
                        <p class="!mb-0 mt-2 text-sm text-slate-500">Nothing recorded for this person for {{ $periodLabel }}.</p>
                    @endif
                </div>
            </div>
        </x-ui.modal>
    @endif
</div>
