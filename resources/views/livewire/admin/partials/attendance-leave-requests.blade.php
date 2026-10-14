@php use App\Services\Attendance\AttendanceAnalyzer; @endphp
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
