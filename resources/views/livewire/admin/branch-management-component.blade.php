<div class="clinic-ui ui-page">
    <div class="ui-heading">
        <div><h1 class="flex flex-wrap items-center gap-2">Branches</h1><p class="ui-muted">{{ $clinic->name }} · each branch's details and who works there.</p></div>
        <div class="text-right text-sm">
            <strong>{{ $allowance['subscription']?->plan?->name ?? 'No subscription' }}</strong><br>
            <span class="text-slate-500">{{ $allowance['activeCount'] }} active / {{ $allowance['limit'] === null ? 'Unlimited' : $allowance['limit'] }} branches</span>
        </div>
    </div>
    <div class="flex flex-wrap -mx-2">
        <div class="w-full lg:w-8/12 px-2">
            <div class="ui-panel"><div class="ui-table-wrap">
                <table class="ui-table mb-0"><thead><tr><th>Code</th><th>Branch</th><th>Timezone</th><th>Staff</th><th>Status</th><th></th></tr></thead><tbody>
                @foreach($branches as $branch)
                    <tr><td>{{ $branch->code }}</td><td>{{ $branch->name }} @if($branch->is_default)<span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-teal-100 text-teal-800">Default</span>@endif</td><td>{{ $branch->timezone }}</td><td>{{ $branch->users_count }}</td><td>{{ $branch->is_active ? 'Active' : 'Inactive' }}</td><td><button wire:click="toggle({{ $branch->id }})" class="ui-button ui-button-sm ui-button-secondary" @disabled($branch->is_default && $branch->is_active)>{{ $branch->is_active ? 'Disable' : 'Enable' }}</button></td></tr>
                @endforeach
                </tbody></table>
            </div></div>
        </div>
        <div class="w-full lg:w-4/12 px-2"><div class="ui-panel"><div class="ui-section-heading"><strong>Add branch</strong></div><form wire:submit="save" class="p-5">
            <div class="mb-4"><label for="branch-manag-code">Code</label><input id="branch-manag-code" wire:model="code" class="ui-input" placeholder="EAST">@error('code')<small class="text-red-700">{{ $message }}</small>@enderror</div>
            <div class="mb-4"><label for="branch-manag-name">Name</label><input id="branch-manag-name" wire:model="name" class="ui-input">@error('name')<small class="text-red-700">{{ $message }}</small>@enderror</div>
            <div class="mb-4"><label for="branch-manag-address">Address</label><input id="branch-manag-address" wire:model="address" class="ui-input"></div>
            <div class="mb-4"><label for="branch-manag-contact">Contact</label><input id="branch-manag-contact" wire:model="contact" class="ui-input"></div>
            <div class="mb-4"><label for="branch-manag-email">Email</label><input id="branch-manag-email" wire:model="email" type="email" class="ui-input"></div>
            <div class="mb-4"><label for="branch-manag-timezone">Timezone</label><input id="branch-manag-timezone" wire:model="timezone" class="ui-input"></div>
            @unless($allowance['can_add'])
                <div class="rounded-lg border px-3 py-2 text-sm border-amber-200 bg-amber-50 text-amber-900">{{ $allowance['permitted'] ? 'Your branch allowance is fully used.' : 'An active subscription is required.' }}</div>
            @endunless
            <button class="ui-button ui-button-primary w-full" @disabled(!$allowance['can_add'])>Create branch</button>
        </form></div></div>
    </div>
</div>
