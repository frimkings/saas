@php use App\Services\Attendance\AttendanceAnalyzer; @endphp
{{-- Leave waiting for an answer, one row each: who, what, how much of their allowance, and the answer. --}}
<div class="space-y-2 p-3">
    <p class="!mb-0 text-sm text-slate-600">Time off staff asked for from their profile. It counts on their attendance only once approved; they are told your answer by email.</p>
    @if($requests->isEmpty())
        <p class="!mb-0 py-3 text-center text-sm text-slate-500">No requests are waiting.</p>
    @else
        <div class="ui-table-wrap rounded-lg border border-slate-200">
            <table class="table ui-table ui-table-sm mb-0">
                <thead>
                    <tr><th>Staff</th><th>Type</th><th>When</th><th class="text-right">Days</th><th>Reason</th><th>Note to them</th><th class="text-right">Answer</th></tr>
                </thead>
                <tbody>
                    @foreach($requests as $request)
                        @php $take = $requestDays[$request->id] ?? null; $over = $take && $take['before'] !== null && $take['days'] > $take['before']; @endphp
                        <tr wire:key="request-{{ $request->id }}">
                            <td class="whitespace-nowrap"><span class="font-semibold text-slate-900">{{ $request->staff->name ?? 'Unknown' }}</span><span class="block text-[11px] text-slate-400">asked {{ $request->created_at->diffForHumans() }}</span></td>
                            <td class="whitespace-nowrap">{{ $request->typeName() }}</td>
                            <td class="whitespace-nowrap">{{ $request->when() }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if($take)
                                    <span class="font-semibold tabular-nums">{{ rtrim(rtrim(number_format($take['days'], 2), '0'), '.') }}</span>
                                    @if($take['before'] !== null)<span @class(['block text-[11px]', 'text-red-700 font-medium' => $over, 'text-slate-400' => ! $over])>{{ rtrim(rtrim(number_format(max(0, $take['before']), 2), '0'), '.') }} left</span>@endif
                                @endif
                            </td>
                            <td class="max-w-[14rem] text-sm text-slate-600">{{ $request->note ? '“'.$request->note.'”' : '' }}</td>
                            <td><input type="text" maxlength="255" class="ui-input !w-full !min-w-[9rem] !py-1 !text-sm" wire:model="reviewNotes.{{ $request->id }}" placeholder="Optional" aria-label="Note to {{ $request->staff->name ?? 'them' }}"></td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" class="ui-button ui-button-secondary !px-2.5 !py-1 !text-xs" wire:click="reviewLeave({{ $request->id }}, false)" wire:loading.attr="disabled" wire:target="reviewLeave">Don't approve</button>
                                <button type="button" class="ui-button ui-button-primary !px-2.5 !py-1 !text-xs" wire:click="reviewLeave({{ $request->id }}, true)" wire:loading.attr="disabled" wire:target="reviewLeave">Approve</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
