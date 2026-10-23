<x-platform.page active="support" title="Support Contact" subtitle="Shown to clinic staff when their clinic is locked for non-payment, and to clinic admins on the renewal page.">
    <style>.sp-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(260px,1fr);gap:16px;align-items:start}.sp-preview{background:#0b1526;border:1px dashed #334560;border-radius:10px;padding:16px;line-height:1.7}.sp-preview b{display:block;font-size:14px;margin-bottom:4px}.sp-preview .ui-actions{margin-top:10px}@media(max-width:900px){.sp-grid{grid-template-columns:1fr}}</style>

    <x-ui.flash :map="['support_message' => 'success']" />

    <div class="sp-grid">
        <form wire:submit="save" class="ui-card grid gap-3" data-tour="sp-form">
            <div class="ui-card-head" style="margin:0"><div><h2>Contact details</h2><p>Leave a field empty to hide it from clinics.</p></div></div>

            <label class="ui-field"><span>Name shown to clinics</span>
                <input class="ui-input" type="text" wire:model.live.debounce.300ms="name" placeholder="e.g. EyeClinic Support" required>
                @error('name')<small class="ui-error">{{ $message }}</small>@enderror
            </label>

            <div class="ui-row">
                <label class="ui-field"><span>Phone</span>
                    <input class="ui-input" type="tel" wire:model.live.debounce.300ms="phone" placeholder="e.g. 0241234567">
                    @error('phone')<small class="ui-error">{{ $message }}</small>@enderror
                </label>
                <label class="ui-field"><span>WhatsApp number</span>
                    <input class="ui-input" type="tel" wire:model.live.debounce.300ms="whatsapp" placeholder="e.g. 0241234567">
                    @error('whatsapp')<small class="ui-error">{{ $message }}</small>@enderror
                </label>
            </div>
            <small class="ui-hint">Locked clinics get a WhatsApp button that opens a chat with this number, with the clinic's name already typed.</small>

            <label class="ui-field"><span>Email</span>
                <input class="ui-input" type="email" wire:model.live.debounce.300ms="email" placeholder="support@example.com">
                @error('email')<small class="ui-error">{{ $message }}</small>@enderror
            </label>

            <label class="ui-field"><span>Requests inbox (optional)</span>
                <input class="ui-input" type="email" wire:model.live.debounce.300ms="requestsEmail" placeholder="Same as the support email">
                @error('requestsEmail')<small class="ui-error">{{ $message }}</small>@enderror
            </label>
            <small class="ui-hint">Plan change requests, SMS credit orders and sender ID requests from clinics are emailed here, with a reminder at 8:00 for any still waiting after a day. Leave it empty to receive them at the support email above. Clinics don't see this address.</small>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <span class="ui-hint" wire:dirty>Unsaved changes</span>
                <button type="submit" class="ui-button ui-button-primary" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">Save</span><span wire:loading wire:target="save">Saving…</span></button>
            </div>
        </form>

        <section class="ui-card" aria-label="Preview" data-tour="sp-preview">
            <div class="ui-card-head"><div><h2>What clinics see</h2><p>Preview of the contact box on the locked and renewal pages.</p></div></div>
            <div class="sp-preview">
                <b>Need help? Contact {{ $name ?: 'support' }}</b>
                @if($phone)<div>☏ {{ $phone }}</div>@endif
                @if($email)<div>✉ {{ $email }}</div>@endif
                @if(!$phone && !$email && !$whatsapp)<div class="ui-hint">No contact details yet, so clinics only see the name.</div>@endif
                @if($whatsapp)<div class="ui-actions"><span class="ui-button ui-button-primary ui-button-sm" style="background:#128c4a;cursor:default">WhatsApp {{ $whatsapp }}</span></div>@endif
            </div>
        </section>
    </div>
</x-platform.page>
