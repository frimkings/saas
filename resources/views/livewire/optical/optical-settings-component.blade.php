@php
    $isManager = auth()->user()?->hasAnyRole(['Super Admin', 'Manager']);
    $isOwner = auth()->user()?->hasRole('Super Admin');
    // One tab per section; the URL hash keeps the tab across reloads (#payments, #reminders, ...).
    $tabs = array_filter([
        'general' => ['General', 'fa-sliders-h', true],
        'payments' => ['Payment methods', 'fa-wallet', $isManager],
        'reminders' => ['Reminders', 'fa-bell', $isManager],
        'links' => ['Links', 'fa-link', $isManager],
        'owner' => ['Owner emails', 'fa-envelope-open-text', $isOwner],
        'sms' => ['SMS per branch', 'fa-code-branch', $isOwner],
    ], fn ($tab) => $tab[2]);
@endphp
<div class="clinic-ui ui-page space-y-4"
     x-data="{ tab: @js(array_key_first($tabs)), tabs: @js(array_keys($tabs)) }"
     x-init="const h = location.hash.slice(1); if (tabs.includes(h)) tab = h; $watch('tab', t => history.replaceState(null, '', '#' + t))">
    <div class="ui-heading">
        <div>
            <h1>Optical Module Settings</h1>
            <p class="ui-muted">Configure default lab deposit rates, lens warranty policies, and optical receipt disclaimers.</p>
        </div>
    </div>

    <x-ui.flash />

    @if(count($tabs) > 1)
        <div class="flex flex-wrap gap-1 border-b border-slate-200" role="tablist" aria-label="Settings sections">
            @foreach($tabs as $key => [$label, $icon])
                <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'"
                        class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm"
                        :class="tab === '{{ $key }}' ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800'">
                    <i class="fas {{ $icon }}" aria-hidden="true"></i>{{ $label }}
                </button>
            @endforeach
        </div>
    @endif

    <div x-show="tab === 'general'" role="tabpanel">
        <div class="ui-panel max-w-3xl p-6">
            <form wire:submit.prevent="saveSettings" class="ui-form !p-0 space-y-5">
                <div class="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                    <x-ui.field label="Minimum Required Deposit (%)" name="min_deposit_percentage" type="number" wire:model="min_deposit_percentage" required />
                    <x-ui.field label="Standard Lens Warranty (Months)" name="warranty_months" type="number" wire:model="warranty_months" required />
                    <x-ui.field label="Quotations valid for (days)" name="quote_validity_days" type="number" min="1" max="365" wire:model="quote_validity_days" required />
                    <x-ui.field label="A job is stuck after (days without a status change)" name="stuck_job_days" type="number" min="1" max="365" wire:model="stuck_job_days" required />
                    <x-ui.field label="Largest POS discount staff can give (%)" name="pos_max_discount_percent" type="number" min="0" max="100" wire:model="pos_max_discount_percent" required />
                </div>

                <div class="ui-field">
                    <label for="optical_disclaimer">Optical Order Receipt Disclaimer</label>
                    <textarea id="optical_disclaimer" wire:model="optical_disclaimer" rows="3" class="ui-input"></textarea>
                </div>

                <fieldset class="space-y-3 border-t border-slate-200 pt-4">
                    <legend class="text-sm font-semibold text-slate-900">Collection messages</legend>
                    <p class="ui-muted text-xs">Messages use the "Spectacles Ready" and "Spectacles Pickup Reminder" SMS templates (partner jobs: "Partner Job Ready" and "Partner Jobs Awaiting Collection"). SMS needs SMS credits; WhatsApp buttons on Awaiting Collection work without them.</p>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="ready_sms_auto"> Text the customer automatically when glasses are marked ready</label>
                    <div class="ui-field">
                        <label for="reminder_schedule">Pickup reminder days</label>
                        <input autocomplete="off" id="reminder_schedule" type="text" wire:model="reminder_schedule" placeholder="e.g. 3, 10, 30" class="ui-input sm:max-w-xs" aria-describedby="reminder_schedule_help">
                        <p id="reminder_schedule_help" class="ui-muted text-xs">Days after the glasses are ready, up to 5. One SMS is sent on each day; leave blank to turn automatic reminders off. Partner clinics get one message listing all their waiting jobs.</p>
                        @error('reminder_schedule')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </fieldset>

                <div class="border-t border-slate-200 pt-4">
                    <button type="submit" class="ui-button ui-button-primary">Save Optical Settings</button>
                </div>
            </form>
        </div>
    </div>

    {{-- What the optical tills accept, and when staff and the owner are reminded --}}
    @if($isManager)
        <div x-show="tab === 'payments'" x-cloak role="tabpanel"><livewire:admin.payment-methods-component :optical="true" /></div>
        <div x-show="tab === 'reminders'" x-cloak role="tabpanel"><livewire:admin.reminder-settings-component :optical="true" /></div>
        <div x-show="tab === 'links'" x-cloak role="tabpanel"><livewire:admin.clinic-links-component :optical="true" /></div>
    @endif

    {{-- Where the owner's emails go and what has been sent. Optical-only clinics have no clinic Settings page. --}}
    @if($isOwner)
        <div x-show="tab === 'owner'" x-cloak role="tabpanel"><livewire:admin.owner-emails-component :optical="true" /></div>
        <div x-show="tab === 'sms'" x-cloak role="tabpanel"><livewire:admin.branch-sms-limits-component :optical="true" /></div>
    @endif
</div>
