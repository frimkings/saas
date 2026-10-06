<div class="clinic-ui ui-page">
    <div class="flex justify-between items-center mb-4">
        <div><h4 class="mb-0">Branches</h4><small class="text-slate-500">{{ $clinic->name }}</small></div>
        <div class="text-right text-sm">
            <strong>{{ $allowance['subscription']?->plan?->name ?? 'No subscription' }}</strong><br>
            <span class="text-slate-500">{{ $allowance['activeCount'] }} active / {{ $allowance['limit'] === null ? 'Unlimited' : $allowance['limit'] }} branches</span>
        </div>
    </div>
    <div class="flex flex-wrap -mx-2">
        <div class="w-full lg:w-8/12 px-2">
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="card-body ui-table-wrap p-0">
                <table class="table ui-table mb-0"><thead><tr><th>Code</th><th>Branch</th><th>Timezone</th><th>Staff</th><th>Status</th><th></th></tr></thead><tbody>
                @foreach($branches as $branch)
                    <tr><td>{{ $branch->code }}</td><td>{{ $branch->name }} @if($branch->is_default)<span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-teal-100 text-teal-800">Default</span>@endif</td><td>{{ $branch->timezone }}</td><td>{{ $branch->users_count }}</td><td>{{ $branch->is_active ? 'Active' : 'Inactive' }}</td><td><button wire:click="toggle({{ $branch->id }})" class="btn ui-button ui-button-sm ui-button-secondary" @disabled($branch->is_default && $branch->is_active)>{{ $branch->is_active ? 'Disable' : 'Enable' }}</button></td></tr>
                @endforeach
                </tbody></table>
            </div></div>
        </div>
        <div class="w-full lg:w-4/12 px-2"><div class="card overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2"><strong>Add branch</strong></div><form wire:submit="save" class="card-body p-4">
            <div class="mb-4"><label>Code</label><input wire:model="code" class="form-control ui-input" placeholder="EAST">@error('code')<small class="text-red-700">{{ $message }}</small>@enderror</div>
            <div class="mb-4"><label>Name</label><input wire:model="name" class="form-control ui-input">@error('name')<small class="text-red-700">{{ $message }}</small>@enderror</div>
            <div class="mb-4"><label>Address</label><input wire:model="address" class="form-control ui-input"></div>
            <div class="mb-4"><label>Contact</label><input wire:model="contact" class="form-control ui-input"></div>
            <div class="mb-4"><label>Email</label><input wire:model="email" type="email" class="form-control ui-input"></div>
            <div class="mb-4"><label>Timezone</label><input wire:model="timezone" class="form-control ui-input"></div>
            @unless($allowance['can_add'])
                <div class="rounded-lg border px-3 py-2 text-sm border-amber-200 bg-amber-50 text-amber-900">{{ $allowance['permitted'] ? 'Your branch allowance is fully used.' : 'An active subscription is required.' }}</div>
            @endunless
            <button class="btn ui-button ui-button-primary w-full" @disabled(!$allowance['can_add'])>Create branch</button>
        </form></div></div>
    </div>
</div>
