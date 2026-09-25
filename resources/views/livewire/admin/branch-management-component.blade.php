<div class="p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-0">Branches</h4><small class="text-muted">{{ $clinic->name }}</small></div>
        <div class="text-right small">
            <strong>{{ $allowance['subscription']?->plan?->name ?? 'No subscription' }}</strong><br>
            <span class="text-muted">{{ $allowance['activeCount'] }} active / {{ $allowance['limit'] === null ? 'Unlimited' : $allowance['limit'] }} branches</span>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-8">
            <div class="card"><div class="card-body table-responsive p-0">
                <table class="table table-hover mb-0"><thead><tr><th>Code</th><th>Branch</th><th>Timezone</th><th>Staff</th><th>Status</th><th></th></tr></thead><tbody>
                @foreach($branches as $branch)
                    <tr><td>{{ $branch->code }}</td><td>{{ $branch->name }} @if($branch->is_default)<span class="badge badge-primary">Default</span>@endif</td><td>{{ $branch->timezone }}</td><td>{{ $branch->users_count }}</td><td>{{ $branch->is_active ? 'Active' : 'Inactive' }}</td><td><button wire:click="toggle({{ $branch->id }})" class="btn btn-sm btn-outline-secondary" @disabled($branch->is_default && $branch->is_active)>{{ $branch->is_active ? 'Disable' : 'Enable' }}</button></td></tr>
                @endforeach
                </tbody></table>
            </div></div>
        </div>
        <div class="col-lg-4"><div class="card"><div class="card-header"><strong>Add branch</strong></div><form wire:submit="save" class="card-body">
            <div class="form-group"><label>Code</label><input wire:model="code" class="form-control" placeholder="EAST">@error('code')<small class="text-danger">{{ $message }}</small>@enderror</div>
            <div class="form-group"><label>Name</label><input wire:model="name" class="form-control">@error('name')<small class="text-danger">{{ $message }}</small>@enderror</div>
            <div class="form-group"><label>Address</label><input wire:model="address" class="form-control"></div>
            <div class="form-group"><label>Contact</label><input wire:model="contact" class="form-control"></div>
            <div class="form-group"><label>Email</label><input wire:model="email" type="email" class="form-control"></div>
            <div class="form-group"><label>Timezone</label><input wire:model="timezone" class="form-control"></div>
            @unless($allowance['can_add'])
                <div class="alert alert-warning small">{{ $allowance['permitted'] ? 'Your branch allowance is fully used.' : 'An active subscription is required.' }}</div>
            @endunless
            <button class="btn btn-primary btn-block" @disabled(!$allowance['can_add'])>Create branch</button>
        </form></div></div>
    </div>
</div>
