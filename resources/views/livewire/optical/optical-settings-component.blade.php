<div class="clinic-ui ui-page space-y-6">
    <div class="ui-heading">
        <div>
            <h1>Optical Module Settings</h1>
            <p class="ui-muted">Configure default lab deposit rates, lens warranty policies, and optical receipt disclaimers.</p>
        </div>
    </div>

    <x-ui.flash />

    <div class="ui-panel p-6 space-y-4 max-w-2xl">
        <form wire:submit.prevent="saveSettings" class="ui-form space-y-4">
            <x-ui.field label="Minimum Required Deposit (%)" name="min_deposit_percentage" type="number" wire:model="min_deposit_percentage" required />
            <x-ui.field label="Standard Lens Warranty (Months)" name="warranty_months" type="number" wire:model="warranty_months" required />
            <x-ui.field label="Quotations valid for (days)" name="quote_validity_days" type="number" min="1" max="365" wire:model="quote_validity_days" required />
            <x-ui.field label="A job is stuck after (days without a status change)" name="stuck_job_days" type="number" min="1" max="365" wire:model="stuck_job_days" required />
            <x-ui.field label="Largest POS discount staff can give (%)" name="pos_max_discount_percent" type="number" min="0" max="100" wire:model="pos_max_discount_percent" required />

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
                    <input autocomplete="off" id="reminder_schedule" type="text" wire:model="reminder_schedule" placeholder="e.g. 3, 10, 30" class="ui-input" aria-describedby="reminder_schedule_help">
                    <p id="reminder_schedule_help" class="ui-muted text-xs">Days after the glasses are ready, up to 5. One SMS is sent on each day; leave blank to turn automatic reminders off. Partner clinics get one message listing all their waiting jobs.</p>
                    @error('reminder_schedule')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </fieldset>

            <div class="pt-2">
                <button type="submit" class="ui-button ui-button-primary">Save Optical Settings</button>
            </div>
        </form>
    </div>

    {{-- What the optical tills accept --}}
    @if(auth()->user()?->hasAnyRole(['Super Admin', 'Manager']))
        <livewire:admin.payment-methods-component :optical="true" />
    @endif

    {{-- Where the owner's emails go and what has been sent. Optical-only clinics have no clinic Settings page. --}}
    @if(auth()->user()?->hasRole('Super Admin'))
        <livewire:admin.owner-emails-component :optical="true" />
        <livewire:admin.branch-sms-limits-component :optical="true" />
    @endif
</div>
