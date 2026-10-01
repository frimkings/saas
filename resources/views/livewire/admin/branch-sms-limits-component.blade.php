<div class="card border-0 shadow-sm rounded-lg mb-4">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="mb-1 font-weight-bold"><i class="fas fa-code-branch text-primary mr-2"></i> SMS per branch</h5>
        <small class="text-muted">
            Give a branch a limit to stop it using more than its share{{ $hosted ? ' of the clinic\'s ' . number_format($credits) . ' credits' : '' }}.
            When a branch reaches its limit, none of its messages are sent until you add more. Birthday, recall and broadcast messages count against the patient's home branch.
        </small>
    </div>
    <div class="card-body pt-0">
        <div class="table-responsive">
            <style>@media (max-width:767px){.bsl-table thead{display:none}.bsl-table tr{display:block;border-bottom:1px solid #dee2e6;padding:.5rem 0}.bsl-table td{display:flex;justify-content:space-between;align-items:center;border:0;text-align:right;padding:.25rem .5rem}.bsl-table td::before{content:attr(data-label);font-weight:600;color:#6c757d;margin-right:1rem;text-align:left}.bsl-table td.bsl-change{display:block;text-align:left}.bsl-table td.bsl-change::before{display:block;margin-bottom:.35rem}.bsl-table th.bsl-change{min-width:0!important}}</style>
            <table class="table table-sm mb-0 bsl-table">
                <thead class="thead-light">
                    <tr><th>Branch</th><th class="text-right">Used</th><th class="text-right">Limit</th><th class="text-right">Left</th><th class="bsl-change" style="min-width:340px">Change</th></tr>
                </thead>
                <tbody>
                    @foreach($branches as $branch)
                        @php($left = $branch->sms_limit === null ? null : max(0, $branch->sms_limit - $branch->sms_used))
                        <tr wire:key="branch-sms-{{ $branch->id }}">
                            <td class="align-middle font-weight-bold" data-label="Branch">{{ $branch->name }}</td>
                            <td class="align-middle text-right" data-label="Used">{{ number_format($branch->sms_used) }}</td>
                            <td class="align-middle text-right" data-label="Limit">{{ $branch->sms_limit === null ? 'No limit' : number_format($branch->sms_limit) }}</td>
                            <td class="align-middle text-right" data-label="Left">
                                @if($left === null)
                                    <span class="text-muted">—</span>
                                @elseif($left === 0)
                                    <span class="badge badge-danger">Out · not sending</span>
                                @elseif($left <= $branch->sms_limit * (1 - \App\Services\Messaging\BranchSmsLimits::WARN_AT))
                                    <span class="badge badge-warning">{{ number_format($left) }}</span>
                                @else
                                    {{ number_format($left) }}
                                @endif
                            </td>
                            <td class="align-middle bsl-change" data-label="Change">
                                <div class="d-flex flex-wrap" style="gap:.4rem">
                                    <div class="input-group input-group-sm" style="width:150px">
                                        <input type="number" min="1" class="form-control" id="add-{{ $branch->id }}" wire:model="add.{{ $branch->id }}" placeholder="SMS" aria-label="SMS to add for {{ $branch->name }}">
                                        <div class="input-group-append"><button type="button" class="btn btn-primary" wire:click="addSms({{ $branch->id }})">Add</button></div>
                                    </div>
                                    <div class="input-group input-group-sm" style="width:170px">
                                        <input type="number" min="0" class="form-control" id="limit-{{ $branch->id }}" wire:model="limit.{{ $branch->id }}" placeholder="Exact limit" aria-label="Exact SMS limit for {{ $branch->name }}">
                                        <div class="input-group-append"><button type="button" class="btn btn-outline-secondary" wire:click="setLimit({{ $branch->id }})">Set</button></div>
                                    </div>
                                    @if($branch->sms_limit !== null)
                                        <button type="button" class="btn btn-sm btn-link text-muted px-1" wire:click="removeLimit({{ $branch->id }})">No limit</button>
                                    @endif
                                </div>
                                @error("add.$branch->id")<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                                @error("limit.$branch->id")<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
