{{-- Profile → My attendance: the signed-in person's own month from the attendance terminal. $mine = ['month', 'row', 'report']. --}}
@php
    use App\Services\Attendance\AttendanceAnalyzer;

    $tone = ['on_time' => 'bg-emerald-50 text-emerald-800', 'late' => 'bg-amber-100 text-amber-900', 'absent' => 'bg-red-100 text-red-800',
        'holiday' => 'bg-violet-100 text-violet-800', 'leave' => 'bg-indigo-100 text-indigo-800', 'worked_off' => 'bg-sky-50 text-sky-800'];
    $t = $mine['row']['totals'];
    $shown = collect($mine['row']['days'])->reject(fn ($day) => in_array($day['status'], ['none', 'off'], true));
@endphp
<div class="card-body p-4 px-6 py-6">
    <p class="mb-3 text-sm text-slate-500">
        Your attendance for {{ $mine['month']->format('F Y') }}, from the attendance terminal.
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
    <div class="mb-4 rounded-lg border border-slate-200 p-3">
        <p class="mb-2 text-sm font-semibold text-slate-800">Ask for time off</p>
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
                    @foreach(\App\Models\AttendanceLeave::TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
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
        <p class="mb-0 text-sm text-slate-500">Nothing recorded for you this month yet.</p>
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
