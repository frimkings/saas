{{-- Profile → My attendance: the signed-in person's own month from the attendance terminal. $mine = ['label', 'row', 'report', 'requests']. --}}
@php
    use App\Services\Attendance\AttendanceAnalyzer;

    $tone = ['on_time' => 'bg-emerald-50 text-emerald-800', 'late' => 'bg-amber-100 text-amber-900', 'absent' => 'bg-red-100 text-red-800',
        'holiday' => 'bg-violet-100 text-violet-800', 'leave' => 'bg-indigo-100 text-indigo-800', 'worked_off' => 'bg-sky-50 text-sky-800'];
    $t = $mine['row']['totals'];
    $shown = collect($mine['row']['days'])->reject(fn ($day) => in_array($day['status'], ['none', 'off'], true));
@endphp
<div class="card-body p-4 px-6 py-6">
    <div class="mb-3"><x-date-range from="attendanceFrom" to="attendanceTo" presets="finance" label="Days" /></div>
    <p class="mb-3 text-sm text-slate-500">
        Your attendance for {{ $mine['label'] }}, from the attendance terminal.
        @if($mine['report']['covered_until'])Recorded up to {{ \Carbon\CarbonImmutable::parse($mine['report']['covered_until'])->format('D j M') }}.@endif
    </p>
    <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach(['Present' => $t['present'].' / '.$t['expected'], 'Late' => $t['late'], 'Absent' => $t['absent'], 'Usual arrival' => AttendanceAnalyzer::time($t['average_arrival']) ?? '–'] as $label => $value)
            <div class="rounded-lg border border-slate-200 px-3 py-2">
                <p class="mb-0 text-xs text-slate-500">{{ $label }}</p>
                <p class="mb-0 text-lg font-semibold text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>
    @if($mine['shifts'])
        <div class="mb-4 rounded-lg border border-slate-200 p-3">
            <p class="mb-2 text-sm font-semibold text-slate-800">My shifts, the next two weeks</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach($mine['shifts'] as $key => $day)
                    <div @class(['w-[4.6rem] rounded-lg border px-1.5 py-1 text-center', 'border-teal-600 ring-1 ring-teal-600' => $loop->first, 'border-slate-200' => ! $loop->first]) title="{{ $day['note'] ?? '' }}">
                        <p class="mb-0 text-[11px] text-slate-500">{{ $loop->first ? 'Today' : $day['date']->format('D j') }}</p>
                        @if(! $day['hours'])<p class="mb-0 text-sm font-semibold text-slate-400">Off</p>
                        @elseif($day['code'])<p class="mb-0 text-sm"><span class="rounded px-1.5 py-0.5 font-semibold {{ $day['code']['class'] }}">{{ $day['code']['code'] }}</span></p>
                        @else<p class="mb-0 text-sm font-semibold text-slate-700">Hrs</p>
                        @endif
                        @if($day['hours'])<p class="mb-0 text-[10px] leading-tight text-slate-500">{{ \App\Services\Attendance\AttendanceAnalyzer::time($day['hours']['start']) }}</p>@endif
                        @if($day['note'])<p class="mb-0 text-[10px] leading-tight text-indigo-700">Swapped</p>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    @if($mine['clock'])
        @php $clock = $mine['clock']; @endphp
        <div class="mb-4 rounded-lg border border-teal-200 bg-teal-50/50 p-3" x-data="{ busy: false, problem: '' }">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="mb-0 text-sm font-semibold text-slate-800">Clock {{ $clock['next'] }} from this phone</p>
                    <p class="mb-0 text-xs text-slate-600">
                        @if($clock['punches']->isEmpty()) No punches yet today.
                        @else Today: {{ $clock['punches']->map(fn ($punch, $i) => ($i % 2 === 0 ? 'In ' : 'Out ').$punch->punched_at->format('g:i A'))->implode(' · ') }}.
                        @endif
                        You must be within {{ \App\Services\Attendance\AttendancePhoneClock::metres($clock['radius']) }} of the branch; your location is saved with the punch.
                    </p>
                </div>
                <button type="button" class="ui-button ui-button-primary" :disabled="busy"
                        @click="problem = ''; if (! navigator.geolocation) { problem = 'This device cannot give a location.'; return; } busy = true;
                            navigator.geolocation.getCurrentPosition(
                                (p) => { $wire.clockIn(p.coords.latitude, p.coords.longitude, p.coords.accuracy).finally(() => busy = false); },
                                (e) => { problem = e.code === 1 ? 'Allow location for this site in your phone\'s browser, then try again.' : 'Could not find where you are. Turn on location (GPS) and try again.'; busy = false; },
                                { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 })">
                    <i class="fas fa-map-marker-alt" aria-hidden="true"></i><span x-text="busy ? 'Finding you…' : 'Clock {{ $clock['next'] }}'"></span></button>
            </div>
            <p class="mb-0 mt-2 text-sm text-red-700" x-show="problem" x-text="problem" x-cloak role="alert"></p>
        </div>
    @endif
    <div class="mb-4 rounded-lg border border-slate-200 p-3">
        <p class="mb-2 text-sm font-semibold text-slate-800">Ask for time off</p>
        @if($mine['balances'])
            <div class="mb-2 flex flex-wrap gap-1.5 text-xs">
                @foreach($mine['balances'] as $balance)
                    <span class="rounded border border-slate-200 px-2 py-1 text-slate-700">
                        <strong>{{ $balance['type']->name }}</strong>
                        @if($balance['allowance'] !== null) {{ rtrim(rtrim(number_format(max(0, $balance['left']), 2), '0'), '.') }} of {{ rtrim(rtrim(number_format($balance['allowance'], 1), '0'), '.') }} days left
                        @else {{ rtrim(rtrim(number_format($balance['used'], 2), '0'), '.') }} days taken @endif
                        @if($balance['pending'] > 0)<span class="text-amber-700">· {{ rtrim(rtrim(number_format($balance['pending'], 2), '0'), '.') }} waiting</span>@endif
                    </span>
                @endforeach
                <span class="self-center text-slate-400">{{ $mine['balanceYear'] }}, working days</span>
            </div>
        @endif
        <form wire:submit="requestLeave" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="my-leave-kind" class="mb-1 block text-xs font-medium text-slate-600">How long</label>
                <select id="my-leave-kind" class="ui-input !w-48 !py-1.5 !text-sm" wire:model.live="leaveKind">
                    <option value="days">Whole day or days</option>
                    <option value="morning">A morning</option>
                    <option value="afternoon">An afternoon</option>
                    <option value="hours">A few hours</option>
                </select>
            </div>
            <div>
                <label for="my-leave-from" class="mb-1 block text-xs font-medium text-slate-600">{{ $leaveKind === 'days' ? 'First day' : 'Day' }}</label>
                <input id="my-leave-from" type="date" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="leaveFrom">
            </div>
            @if($leaveKind === 'days')
                <div>
                    <label for="my-leave-to" class="mb-1 block text-xs font-medium text-slate-600">Last day (if more than one)</label>
                    <input id="my-leave-to" type="date" class="ui-input !w-40 !py-1.5 !text-sm" wire:model="leaveTo">
                </div>
            @elseif($leaveKind === 'hours')
                <div>
                    <label for="my-leave-from-time" class="mb-1 block text-xs font-medium text-slate-600">From</label>
                    <input id="my-leave-from-time" type="time" class="ui-input !w-32 !py-1.5 !text-sm" wire:model="leaveFromTime">
                </div>
                <div>
                    <label for="my-leave-to-time" class="mb-1 block text-xs font-medium text-slate-600">To</label>
                    <input id="my-leave-to-time" type="time" class="ui-input !w-32 !py-1.5 !text-sm" wire:model="leaveToTime">
                </div>
            @endif
            <div>
                <label for="my-leave-type" class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                <select id="my-leave-type" class="ui-input !w-36 !py-1.5 !text-sm" wire:model="leaveType">
                    @foreach($mine['leaveTypes'] as $key => $type)<option value="{{ $key }}">{{ $type->name }}</option>@endforeach
                </select>
            </div>
            <div class="min-w-[12rem] flex-1">
                <label for="my-leave-note" class="mb-1 block text-xs font-medium text-slate-600">Reason (optional)</label>
                <input id="my-leave-note" type="text" maxlength="255" class="ui-input !py-1.5 !text-sm" wire:model="leaveNote">
            </div>
            <button type="submit" class="ui-button ui-button-primary !py-1.5" wire:loading.attr="disabled" wire:target="requestLeave">Send request</button>
            @foreach(['leaveFrom', 'leaveTo', 'leaveFromTime', 'leaveToTime', 'leaveType', 'leaveNote'] as $field)
                @error($field)<p class="ui-error w-full" role="alert">{{ $message }}</p>@enderror
            @endforeach
        </form>
        @foreach($mine['requests'] as $request)
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2 text-sm" wire:key="my-leave-{{ $request->id }}">
                <span>{{ $request->when() }} · {{ $request->typeName() }}
                    <span @class(['ml-1 rounded px-1.5 py-0.5 text-xs font-medium', 'bg-amber-100 text-amber-900' => $request->status === 'pending', 'bg-emerald-50 text-emerald-800' => $request->status === 'approved', 'bg-red-100 text-red-800' => $request->status === 'rejected'])>{{ \App\Models\AttendanceLeave::STATUSES[$request->status] ?? $request->status }}</span>
                    @if($request->review_note)<span class="ml-1 text-xs text-slate-600">“{{ $request->review_note }}”</span>@endif
                </span>
                @if($request->status === 'pending')
                    <button type="button" class="ui-button ui-button-secondary !px-2 !py-1 !text-xs" wire:click="withdrawLeave({{ $request->id }})" wire:confirm="Withdraw this request?">Withdraw</button>
                @endif
            </div>
        @endforeach
    </div>

    @if($shown->isEmpty())
        <p class="mb-0 text-sm text-slate-500">Nothing recorded for you for these days.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500"><th class="py-2 pr-3">Day</th><th class="py-2 pr-3">Punches</th><th class="py-2 pr-3">On site</th><th class="py-2">Status</th></tr></thead>
                <tbody>
                    @foreach($shown as $key => $day)
                        <tr class="border-b border-slate-100">
                            <td class="py-1.5 pr-3">{{ $mine['report']['days'][$key]->format('D j M') }}</td>
                            <td class="py-1.5 pr-3 tabular-nums">{{ implode(' · ', array_map(fn ($punch) => ($punch['kind'] === 'in' ? 'In ' : 'Out ').AttendanceAnalyzer::time($punch['time']), $day['punches'])) ?: '–' }}</td>
                            <td class="py-1.5 pr-3 tabular-nums">{{ $day['minutes_worked'] !== null ? AttendanceAnalyzer::duration($day['minutes_worked']) : '–' }}</td>
                            <td class="py-1.5">
                                <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $tone[$day['status']] ?? '' }}">{{ AttendanceAnalyzer::STATUSES[$day['status']] }}{{ $day['late_minutes'] ? ' · '.AttendanceAnalyzer::duration($day['late_minutes']) : '' }}</span>
                                @if($day['note'])<span class="ml-1 text-xs text-slate-600">{{ $day['note'] }}</span>@endif
                                @if($day['left_early'])<span class="ml-1 text-xs text-slate-600">Left {{ AttendanceAnalyzer::duration($day['early_minutes']) }} early</span>@endif
                                @if($day['no_clock_out'])<span class="ml-1 text-xs text-slate-600">A punch is missing</span>@endif
                                @if($day['long_break'])<span class="ml-1 text-xs text-slate-600">Long break ({{ AttendanceAnalyzer::duration($day['break_minutes']) }})</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
