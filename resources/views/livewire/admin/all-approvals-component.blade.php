<div class="clinic-ui ui-page">
    {{-- Header --}}
    <div class="mb-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="!mb-1 text-xl font-semibold text-slate-900">Approvals</h1>
                <p class="text-slate-500 text-sm mb-0">Manage all pending approvals from one place.</p>
            </div>
            @if($total > 0)
                <span class="inline-flex items-center rounded text-xs font-semibold bg-amber-100 text-amber-800 px-4 py-2" style="font-size:0.85rem;">
                    <i class="fas fa-clock mr-1"></i> {{ $total }} total pending
                </span>
            @endif
        </div>

        {{-- Type tabs --}}
        <ul class="flex flex-wrap gap-1 border-b border-slate-200" role="tablist">
            @if($this->canAccess('discount'))
                <li class="">
                    <a href="#" wire:click.prevent="switchType('discount')"
                       class="-mb-px flex items-center gap-1 border-b-2 px-3 py-2 text-sm {{ $activeType === 'discount' ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                        <i class="fas fa-percentage mr-1"></i> Discount
                        @if($counts['discount'] > 0)
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-1">{{ $counts['discount'] }}</span>
                        @endif
                    </a>
                </li>
            @endif

            @if($this->canAccess('refund'))
                <li class="">
                    <a href="#" wire:click.prevent="switchType('refund')"
                       class="-mb-px flex items-center gap-1 border-b-2 px-3 py-2 text-sm {{ $activeType === 'refund' ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                        <i class="fas fa-undo mr-1"></i> Refunds
                        @if($counts['refund'] > 0)
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-1">{{ $counts['refund'] }}</span>
                        @endif
                    </a>
                </li>
            @endif

            @if($this->canAccess('revoke'))
                <li class="">
                    <a href="#" wire:click.prevent="switchType('revoke')"
                       class="-mb-px flex items-center gap-1 border-b-2 px-3 py-2 text-sm {{ $activeType === 'revoke' ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                        <i class="fas fa-ban mr-1"></i> Clearance Revokes
                        @if($counts['revoke'] > 0)
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 ml-1">{{ $counts['revoke'] }}</span>
                        @endif
                    </a>
                </li>
            @endif

            @if($this->canAccess('password_reset'))
                <li class="">
                    <a href="#" wire:click.prevent="switchType('password_reset')"
                       class="-mb-px flex items-center gap-1 border-b-2 px-3 py-2 text-sm {{ $activeType === 'password_reset' ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                        <i class="fas fa-key mr-1"></i> Password Resets
                        @if($counts['password_reset'] > 0)
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 ml-1">{{ $counts['password_reset'] }}</span>
                        @endif
                    </a>
                </li>
            @endif

            @if($this->canAccess('spectacle_renewal'))
                <li class="">
                    <a href="#" wire:click.prevent="switchType('spectacle_renewal')"
                       class="-mb-px flex items-center gap-1 border-b-2 px-3 py-2 text-sm {{ $activeType === 'spectacle_renewal' ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                        <i class="fas fa-redo mr-1"></i> Spectacle Renewals
                        @if($counts['spectacle_renewal'] > 0)
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-1">{{ $counts['spectacle_renewal'] }}</span>
                        @endif
                    </a>
                </li>
            @endif
        </ul>
    </div>

    {{-- Active panel — only the selected component is mounted --}}
    <div>
        @if($activeType === 'discount' && $this->canAccess('discount'))
            @livewire('admin.discount-approvals-component', [], key('discount'))
        @elseif($activeType === 'refund' && $this->canAccess('refund'))
            @livewire('admin.refund-approvals-component', [], key('refund'))
        @elseif($activeType === 'revoke' && $this->canAccess('revoke'))
            @livewire('admin.clearance-revoke-approvals-component', [], key('revoke'))
        @elseif($activeType === 'password_reset' && $this->canAccess('password_reset'))
            @livewire('admin.password-reset-approvals-component', [], key('password_reset'))
        @elseif($activeType === 'spectacle_renewal' && $this->canAccess('spectacle_renewal'))
            @livewire('admin.spectacle-renewal-approvals-component', [], key('spectacle_renewal'))
        @else
            <div class="text-center py-12 text-slate-500">
                <i class="fas fa-lock fa-2x mb-2 block"></i>
                You do not have access to this approval type.
            </div>
        @endif
    </div>
</div>
