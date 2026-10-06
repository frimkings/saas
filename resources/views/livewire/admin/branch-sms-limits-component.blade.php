<div class="rounded-lg border border-slate-200 bg-white shadow-sm">
    <div class="px-4 pt-4 pb-2">
        <h3 class="mb-1 flex items-center gap-2 text-sm font-semibold text-slate-800"><i class="fas fa-code-branch text-teal-700"></i> SMS per branch</h3>
        <small class="block text-xs text-slate-500">
            Give a branch a limit to stop it using more than its share{{ $hosted ? ' of the clinic\'s ' . number_format($credits) . ' credits' : '' }}.
            When a branch reaches its limit, none of its messages are sent until you add more. Birthday, recall and broadcast messages count against the patient's home branch.
        </small>
    </div>
    <div class="px-4 pb-3">
        <div class="ui-table-wrap">
            <style>@media (max-width:767px){.bsl-table thead{display:none}.bsl-table tr{display:block;border-bottom:1px solid #dee2e6;padding:.5rem 0}.bsl-table td{display:flex;justify-content:space-between;align-items:center;border:0;text-align:right;padding:.25rem .5rem}.bsl-table td::before{content:attr(data-label);font-weight:600;color:#6c757d;margin-right:1rem;text-align:left}.bsl-table td.bsl-change{display:block;text-align:left}.bsl-table td.bsl-change::before{display:block;margin-bottom:.35rem}.bsl-table th.bsl-change{min-width:0!important}}</style>
            <table class="table ui-table ui-table-sm mb-0 bsl-table">
                <thead class="">
                    <tr><th>Branch</th><th class="text-right">Used</th><th class="text-right">Limit</th><th class="text-right">Left</th><th class="bsl-change" style="min-width:340px">Change</th></tr>
                </thead>
                <tbody>
                    @foreach($branches as $branch)
                        @php($left = $branch->sms_limit === null ? null : max(0, $branch->sms_limit - $branch->sms_used))
                        <tr wire:key="branch-sms-{{ $branch->id }}">
                            <td class="align-middle font-semibold" data-label="Branch">{{ $branch->name }}</td>
                            <td class="align-middle text-right" data-label="Used">{{ number_format($branch->sms_used) }}</td>
                            <td class="align-middle text-right" data-label="Limit">{{ $branch->sms_limit === null ? 'No limit' : number_format($branch->sms_limit) }}</td>
                            <td class="align-middle text-right" data-label="Left">
                                @if($left === null)
                                    <span class="text-slate-500">—</span>
                                @elseif($left === 0)
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800">Out · not sending</span>
                                @elseif($left <= $branch->sms_limit * (1 - \App\Services\Messaging\BranchSmsLimits::WARN_AT))
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">{{ number_format($left) }}</span>
                                @else
                                    {{ number_format($left) }}
                                @endif
                            </td>
                            <td class="align-middle bsl-change" data-label="Change">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="flex w-40">
                                        <input type="number" min="1" class="h-8 w-full min-w-0 rounded-l-md border border-slate-300 px-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" id="add-{{ $branch->id }}" wire:model="add.{{ $branch->id }}" placeholder="SMS" aria-label="SMS to add for {{ $branch->name }}">
                                        <button type="button" class="h-8 whitespace-nowrap rounded-r-md bg-teal-600 px-3 text-sm font-semibold text-white hover:bg-teal-700" wire:click="addSms({{ $branch->id }})">Add</button>
                                    </div>
                                    <div class="flex w-44">
                                        <input type="number" min="0" class="h-8 w-full min-w-0 rounded-l-md border border-slate-300 px-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500" id="limit-{{ $branch->id }}" wire:model="limit.{{ $branch->id }}" placeholder="Exact limit" aria-label="Exact SMS limit for {{ $branch->name }}">
                                        <button type="button" class="h-8 whitespace-nowrap rounded-r-md border border-l-0 border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50" wire:click="setLimit({{ $branch->id }})">Set</button>
                                    </div>
                                    @if($branch->sms_limit !== null)
                                        <button type="button" class="px-1 text-sm text-slate-500 hover:underline" wire:click="removeLimit({{ $branch->id }})">No limit</button>
                                    @endif
                                </div>
                                @error("add.$branch->id")<div class="text-sm text-red-700 mt-1">{{ $message }}</div>@enderror
                                @error("limit.$branch->id")<div class="text-sm text-red-700 mt-1">{{ $message }}</div>@enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
