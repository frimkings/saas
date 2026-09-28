<div class="ui-panel p-6 space-y-4 max-w-2xl">
    <div>
        <h2 class="text-base font-semibold text-slate-900">SMS per branch</h2>
        <p class="ui-muted text-sm">
            Give a branch a limit to stop it using more than its share{{ $hosted ? ' of the clinic\'s ' . number_format($credits) . ' credits' : '' }}.
            When a branch reaches its limit, none of its messages are sent until you add more.
        </p>
    </div>
    <div class="ui-table-wrap">
        <table class="ui-table">
            <thead><tr><th>Branch</th><th class="text-right">Used</th><th class="text-right">Limit</th><th class="text-right">Left</th></tr></thead>
            <tbody>
                @foreach($branches as $branch)
                    @php($left = $branch->sms_limit === null ? null : max(0, $branch->sms_limit - $branch->sms_used))
                    <tr wire:key="branch-sms-{{ $branch->id }}">
                        <td class="font-semibold">{{ $branch->name }}</td>
                        <td class="text-right tabular-nums">{{ number_format($branch->sms_used) }}</td>
                        <td class="text-right tabular-nums">{{ $branch->sms_limit === null ? 'No limit' : number_format($branch->sms_limit) }}</td>
                        <td class="text-right tabular-nums">
                            @if($left === null) <span class="ui-muted">—</span>
                            @elseif($left === 0) <span class="ui-badge" style="background:#fef3f2;color:#b42318">Out · not sending</span>
                            @else {{ number_format($left) }} @endif
                        </td>
                    </tr>
                    <tr wire:key="branch-sms-change-{{ $branch->id }}">
                        <td colspan="4">
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="number" min="1" class="ui-input" style="width:110px" id="add-{{ $branch->id }}" wire:model="add.{{ $branch->id }}" placeholder="SMS" aria-label="SMS to add for {{ $branch->name }}">
                                <button type="button" class="ui-button ui-button-primary" wire:click="addSms({{ $branch->id }})">Add</button>
                                <input type="number" min="0" class="ui-input" style="width:120px" id="limit-{{ $branch->id }}" wire:model="limit.{{ $branch->id }}" placeholder="Exact limit" aria-label="Exact SMS limit for {{ $branch->name }}">
                                <button type="button" class="ui-button" wire:click="setLimit({{ $branch->id }})">Set</button>
                                @if($branch->sms_limit !== null)
                                    <button type="button" class="ui-button" wire:click="removeLimit({{ $branch->id }})">No limit</button>
                                @endif
                            </div>
                            @error("add.$branch->id")<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @error("limit.$branch->id")<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
