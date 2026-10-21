@php use App\Services\Attendance\AttendanceAnalyzer; @endphp
{{-- Overtime in the days on show, one row a day: waiting first, each approved (in full or part) or turned down. --}}
@php
    $waiting = collect($overtime)->where('day.overtime_status', 'pending');
@endphp
<div class="attendance-overtime space-y-2 p-3">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <p class="!mb-0 max-w-3xl text-sm text-slate-600">
            Staying {{ AttendanceAnalyzer::duration($settings->overtime_after_minutes ?? 30) }} or more past the end of the day is overtime, as is time on site on a day off or a holiday.
            @if($settings->overtime_needs_approval ?? true) It counts once approved; you can approve fewer minutes than were worked. @else It counts by itself here; turn a day down if it should not. @endif
        </p>
        @if($waiting->count() > 1)
            <button type="button" class="ui-button ui-button-secondary !py-1.5" wire:click="approveAllOvertime" wire:confirm="Approve all {{ $waiting->count() }} days waiting, each for the full time worked?" wire:loading.attr="disabled">Approve all {{ $waiting->count() }} in full</button>
        @endif
    </div>
    @if(! $overtime)
        <p class="!mb-0 py-3 text-center text-sm text-slate-500">Nobody worked overtime in {{ $periodLabel }}.</p>
    @else
        <div class="ui-table-wrap rounded-lg border border-slate-200">
            <table class="table ui-table ui-table-sm mb-0">
                <thead>
                    <tr><th>Staff</th><th>Day</th><th class="text-right">Overtime</th><th>Why</th><th>Status</th><th class="text-right">Minutes</th><th>Note</th><th class="text-right">Answer</th></tr>
                </thead>
                <tbody>
                    @foreach($overtime as $item)
                        @php $day = $item['day']; $decision = $item['decision']; $date = $item['date']->format('Y-m-d'); @endphp
                        <tr wire:key="overtime-{{ $item['key'] }}-{{ $day['overtime_status'] }}">
                            <td class="whitespace-nowrap font-semibold text-slate-900">{{ $item['staff']->name }}</td>
                            <td class="whitespace-nowrap">{{ $item['date']->format('D j M') }}</td>
                            <td class="whitespace-nowrap text-right font-semibold tabular-nums">{{ AttendanceAnalyzer::duration($day['overtime_minutes']) }}</td>
                            <td class="text-sm text-slate-600">
                                @if($day['status'] === 'worked_off')On site {{ AttendanceAnalyzer::time($day['in']) }}–{{ AttendanceAnalyzer::time($day['out']) }}, {{ $day['note'] ?: 'a day off' }}
                                @else Left {{ AttendanceAnalyzer::time($day['out']) }}; the day ends {{ AttendanceAnalyzer::time($day['hours']['end']) }}{{ ($day['hours']['shift'] ?? null) ? ' ('.$day['hours']['shift'].')' : '' }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="rounded px-1.5 py-0.5 text-xs font-medium " style="{{ \App\Support\AttendanceTones::flag(\App\Support\AttendanceTones::overtime($day['overtime_status'])) }}">{{ ['pending' => 'Waiting', 'approved' => 'Approved '.AttendanceAnalyzer::duration($day['overtime_approved']), 'declined' => 'Not approved'][$day['overtime_status']] ?? '' }}</span>
                                @if($decision)<span class="block text-[11px] text-slate-400">{{ $decision->decider->name ?? 'A manager' }}, {{ $decision->updated_at->diffForHumans() }}</span>@endif
                            </td>
                            @if($decision)
                                <td class="text-right text-sm text-slate-400">–</td>
                                <td class="text-sm text-slate-600">{{ $decision->note ? '“'.$decision->note.'”' : '' }}</td>
                                <td class="whitespace-nowrap text-right"><button type="button" class="text-xs text-teal-700 underline" wire:click="reopenOvertime({{ $item['staff']->id }}, '{{ $date }}')">Change</button></td>
                            @else
                                <td class="text-right">
                                    <input type="number" min="1" max="{{ $day['overtime_minutes'] }}" class="ui-input !w-20 !py-1 !text-sm" wire:model="overtimeMinutes.{{ $item['key'] }}" placeholder="{{ $day['overtime_minutes'] }}" aria-label="Minutes to approve for {{ $item['staff']->name }} on {{ $item['date']->format('j M') }}">
                                    @error('overtimeMinutes.'.$item['key'])<span class="block text-[11px] text-red-600">{{ $message }}</span>@enderror
                                </td>
                                <td><input type="text" maxlength="255" class="ui-input !w-full !min-w-[8rem] !py-1 !text-sm" wire:model="overtimeNotes.{{ $item['key'] }}" placeholder="Optional" aria-label="Note"></td>
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" class="ui-button ui-button-secondary !px-2.5 !py-1 !text-xs" wire:click="decideOvertime({{ $item['staff']->id }}, '{{ $date }}', false)" wire:loading.attr="disabled" wire:target="decideOvertime">Don't approve</button>
                                    <button type="button" class="ui-button ui-button-primary !px-2.5 !py-1 !text-xs" wire:click="decideOvertime({{ $item['staff']->id }}, '{{ $date }}', true)" wire:loading.attr="disabled" wire:target="decideOvertime">Approve</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($overtimeCount > count($overtime))<p class="!mb-0 text-sm text-slate-500">Showing the first {{ count($overtime) }} of {{ number_format($overtimeCount) }} days, those waiting first. Answer these, pick a department or choose fewer days to see the rest.</p>@endif
    @endif
</div>
