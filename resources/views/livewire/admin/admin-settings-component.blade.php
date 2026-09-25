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
        @if($this->canManageFullBackups())<li class="nav-item">
            <a class="nav-link {{ $activeTab === 'backup' ? 'active' : '' }}"
               wire:click.prevent="setTab('backup')" href="#">
                <i class="fas fa-database mr-1"></i> Database Backup
            </a>
        </li>@endif
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'report' ? 'active' : '' }}"
               wire:click.prevent="setTab('report')" href="#">
                <i class="fas fa-chart-line mr-1"></i> Report Delivery
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'mail' ? 'active' : '' }}"
               wire:click.prevent="setTab('mail')" href="#">
                <i class="fas fa-envelope mr-1"></i> Mail Settings
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'sms' ? 'active' : '' }}"
               wire:click.prevent="setTab('sms')" href="#">
                <i class="fas fa-sms mr-1"></i> SMS Settings
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'templates' ? 'active' : '' }}"
               wire:click.prevent="setTab('templates')" href="#">
                <i class="fas fa-comment-dots mr-1"></i> SMS Templates
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'whatsapp' ? 'active' : '' }}"
               wire:click.prevent="setTab('whatsapp')" href="#">
                <i class="fab fa-whatsapp mr-1" style="color:#25D366;"></i> WhatsApp
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
        @elseif($activeTab === 'backup')
            @livewire('admin.backup-manager-component', [], key('tab-backup'))
        @elseif($activeTab === 'report')
            @livewire('admin.report-delivery-component', [], key('tab-report'))
        @elseif($activeTab === 'mail')
            @livewire('admin.mail-settings-component', [], key('tab-mail'))
        @elseif($activeTab === 'sms')
            @livewire('admin.sms-settings-component', [], key('tab-sms'))
        @elseif($activeTab === 'templates')
            @livewire('admin.sms-templates-component', [], key('tab-templates'))
        @elseif($activeTab === 'whatsapp')
            @livewire('admin.whats-app-settings-component', [], key('tab-whatsapp'))
        @elseif($activeTab === 'license')
            @livewire('admin.license-component', [], key('tab-license'))
        @endif
    </div>
</div>
