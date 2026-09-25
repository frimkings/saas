<div>
    {{-- Header --}}
    <div class="container-fluid pt-4 pb-0 px-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h4 class="font-weight-bold text-primary mb-1">Approvals</h4>
                <p class="text-muted small mb-0">Manage all pending approvals from one place.</p>
            </div>
            @if($total > 0)
                <span class="badge badge-warning px-3 py-2" style="font-size:0.85rem;">
                    <i class="fas fa-clock mr-1"></i> {{ $total }} total pending
                </span>
            @endif
        </div>

        {{-- Type tabs --}}
        <ul class="nav nav-tabs border-bottom-0">
            @if($this->canAccess('discount'))
                <li class="nav-item">
                    <a href="#" wire:click.prevent="switchType('discount')"
                       class="nav-link {{ $activeType === 'discount' ? 'active font-weight-bold' : 'text-muted' }}">
                        <i class="fas fa-percentage mr-1"></i> Discount
                        @if($counts['discount'] > 0)
                            <span class="badge badge-warning ml-1">{{ $counts['discount'] }}</span>
                        @endif
                    </a>
                </li>
            @endif

            @if($this->canAccess('refund'))
                <li class="nav-item">
                    <a href="#" wire:click.prevent="switchType('refund')"
                       class="nav-link {{ $activeType === 'refund' ? 'active font-weight-bold' : 'text-muted' }}">
                        <i class="fas fa-undo mr-1"></i> Refunds
                        @if($counts['refund'] > 0)
                            <span class="badge badge-warning ml-1">{{ $counts['refund'] }}</span>
                        @endif
                    </a>
                </li>
            @endif

            @if($this->canAccess('revoke'))
                <li class="nav-item">
                    <a href="#" wire:click.prevent="switchType('revoke')"
                       class="nav-link {{ $activeType === 'revoke' ? 'active font-weight-bold' : 'text-muted' }}">
                        <i class="fas fa-ban mr-1"></i> Clearance Revokes
                        @if($counts['revoke'] > 0)
                            <span class="badge badge-danger ml-1">{{ $counts['revoke'] }}</span>
                        @endif
                    </a>
                </li>
            @endif

            @if($this->canAccess('password_reset'))
                <li class="nav-item">
                    <a href="#" wire:click.prevent="switchType('password_reset')"
                       class="nav-link {{ $activeType === 'password_reset' ? 'active font-weight-bold' : 'text-muted' }}">
                        <i class="fas fa-key mr-1"></i> Password Resets
                        @if($counts['password_reset'] > 0)
                            <span class="badge badge-danger ml-1">{{ $counts['password_reset'] }}</span>
                        @endif
                    </a>
                </li>
            @endif

            @if($this->canAccess('spectacle_renewal'))
                <li class="nav-item">
                    <a href="#" wire:click.prevent="switchType('spectacle_renewal')"
                       class="nav-link {{ $activeType === 'spectacle_renewal' ? 'active font-weight-bold' : 'text-muted' }}">
                        <i class="fas fa-redo mr-1"></i> Spectacle Renewals
                        @if($counts['spectacle_renewal'] > 0)
                            <span class="badge badge-warning ml-1">{{ $counts['spectacle_renewal'] }}</span>
                        @endif
                    </a>
                </li>
            @endif
        </ul>
    </div>

    {{-- Active panel — only the selected component is mounted --}}
    <div class="border-top">
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
            <div class="text-center py-5 text-muted">
                <i class="fas fa-lock fa-2x mb-2 d-block"></i>
                You do not have access to this approval type.
            </div>
        @endif
    </div>
</div>
