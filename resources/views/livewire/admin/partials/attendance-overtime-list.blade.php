@php use App\Services\Attendance\AttendanceAnalyzer; @endphp
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
                @if($overtimeCount > count($overtime))<p class="!mb-0 text-sm text-slate-500">Showing the first {{ count($overtime) }} of {{ number_format($overtimeCount) }} days, those waiting first. Answer these, pick a department or choose fewer days to see the rest.</p>@endif
            </div>
