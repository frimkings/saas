<div class="clinic-ui ui-page">
    <div class="mb-4">
        <h2 class="text-teal-700 font-semibold mb-0">Settings</h2>
        <p class="text-slate-500 text-sm uppercase font-semibold mb-0">Clinic Configuration</p>
    </div>

    {{-- Tab bar --}}
    <ul class="flex flex-wrap border-b border-slate-200 mb-0">
        <li class="">
            <a class="block px-3 py-2 {{ $activeTab === 'system' ? 'active' : '' }}"
               wire:click.prevent="setTab('system')" href="#">
                <i class="fas fa-cogs mr-1"></i> Clinic Profile &amp; Branding
            </a>
        </li>
        <li class="">
            <a class="block px-3 py-2 {{ $activeTab === 'links' ? 'active' : '' }}"
               wire:click.prevent="setTab('links')" href="#">
                <i class="fas fa-link mr-1"></i> Clinic Links
            </a>
        </li>
        <li class="">
            <a class="block px-3 py-2 {{ $activeTab === 'receipts' ? 'active' : '' }}"
               wire:click.prevent="setTab('receipts')" href="#">
                <i class="fas fa-receipt mr-1"></i> Receipts &amp; Payments
            </a>
        </li>
        <li class="">
            <a class="block px-3 py-2 {{ $activeTab === 'reminders' ? 'active' : '' }}"
               wire:click.prevent="setTab('reminders')" href="#">
                <i class="fas fa-bell mr-1"></i> Reminders
            </a>
        </li>
        @if($this->canManageFullBackups())<li class="">
            <a class="block px-3 py-2 {{ $activeTab === 'backup' ? 'active' : '' }}"
               wire:click.prevent="setTab('backup')" href="#">
                <i class="fas fa-database mr-1"></i> Database Backup
            </a>
        </li>@endif
        <li class="">
            <a class="block px-3 py-2 {{ $activeTab === 'report' ? 'active' : '' }}"
               wire:click.prevent="setTab('report')" href="#">
                <i class="fas fa-envelope-open-text mr-1"></i> Owner Emails
            </a>
        </li>
        <li class="ml-auto">
            <a class="block px-3 py-2 {{ $activeTab === 'license' ? 'active' : '' }}"
               wire:click.prevent="setTab('license')" href="#">
                <i class="fas fa-key mr-1 text-amber-600"></i> License &amp; Subscription
            </a>
        </li>
    </ul>

    {{-- Tab content --}}
    <div class="tab-content bg-white border border-slate-200 border-t-0 rounded-bottom shadow-sm">
        @if($activeTab === 'system')
            @livewire('admin.settings-component', [], key('tab-system'))
        @elseif($activeTab === 'links')
            @livewire('admin.clinic-links-component', [], key('tab-links'))
        @elseif($activeTab === 'receipts')
            @livewire('admin.receipt-settings-component', [], key('tab-receipts'))
        @elseif($activeTab === 'reminders')
            @livewire('admin.reminder-settings-component', [], key('tab-reminders'))
        @elseif($activeTab === 'backup')
            @livewire('admin.backup-manager-component', [], key('tab-backup'))
        @elseif($activeTab === 'report')
            @livewire('admin.owner-emails-component', [], key('tab-report'))
        @elseif($activeTab === 'license')
            @livewire('admin.license-component', [], key('tab-license'))
        @endif
    </div>
</div>
