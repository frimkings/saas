<div class="p-4 bg-light min-vh-100">
    <div class="mb-3">
        <h2 class="text-primary font-weight-bold mb-0">Settings</h2>
        <p class="text-muted small text-uppercase font-weight-bold mb-0">Clinic Configuration</p>
    </div>

    {{-- Tab bar --}}
    <ul class="nav nav-tabs mb-0">
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'system' ? 'active' : '' }}"
               wire:click.prevent="setTab('system')" href="#">
                <i class="fas fa-cogs mr-1"></i> Clinic Profile &amp; Branding
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'receipts' ? 'active' : '' }}"
               wire:click.prevent="setTab('receipts')" href="#">
                <i class="fas fa-receipt mr-1"></i> Receipts &amp; Payments
            </a>
        </li>
        @if($this->canManageFullBackups())<li class="nav-item">
            <a class="nav-link {{ $activeTab === 'backup' ? 'active' : '' }}"
               wire:click.prevent="setTab('backup')" href="#">
                <i class="fas fa-database mr-1"></i> Database Backup
            </a>
        </li>@endif
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'report' ? 'active' : '' }}"
               wire:click.prevent="setTab('report')" href="#">
                <i class="fas fa-envelope-open-text mr-1"></i> Owner Emails
            </a>
        </li>
        <li class="nav-item ml-auto">
            <a class="nav-link {{ $activeTab === 'license' ? 'active' : '' }}"
               wire:click.prevent="setTab('license')" href="#">
                <i class="fas fa-key mr-1 text-warning"></i> License &amp; Subscription
            </a>
        </li>
    </ul>

    {{-- Tab content --}}
    <div class="tab-content bg-white border border-top-0 rounded-bottom shadow-sm">
        @if($activeTab === 'system')
            @livewire('admin.settings-component', [], key('tab-system'))
        @elseif($activeTab === 'receipts')
            @livewire('admin.receipt-settings-component', [], key('tab-receipts'))
        @elseif($activeTab === 'backup')
            @livewire('admin.backup-manager-component', [], key('tab-backup'))
        @elseif($activeTab === 'report')
            @livewire('admin.owner-emails-component', [], key('tab-report'))
        @elseif($activeTab === 'license')
            @livewire('admin.license-component', [], key('tab-license'))
        @endif
    </div>
</div>
