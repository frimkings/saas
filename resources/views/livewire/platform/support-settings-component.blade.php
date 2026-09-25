<x-platform.page active="support" title="Support Contact" subtitle="Shown to clinic staff when their clinic is locked for non-payment, and to clinic admins on the renewal page.">
    <style>.sp-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(260px,1fr);gap:16px;align-items:start}.sp-preview{background:#0b1526;border:1px dashed #334560;border-radius:10px;padding:16px;line-height:1.7}.sp-preview b{display:block;font-size:14px;margin-bottom:4px}.sp-preview .pp-actions{margin-top:10px}@media(max-width:900px){.sp-grid{grid-template-columns:1fr}}</style>

    @if(session('support_message'))<div class="pp-ok" role="status">{{ session('support_message') }}</div>@endif

    <div class="sp-grid">
        <form wire:submit="save" class="pp-card pp-form">
            <div class="pp-card-head" style="margin:0"><div><h2>Contact details</h2><p>Leave a field empty to hide it from clinics.</p></div></div>

            <label class="pp-field"><span>Name shown to clinics</span>
                <input type="text" wire:model.live.debounce.300ms="name" placeholder="e.g. EyeClinic Support" required>
                @error('name')<small class="pp-err">{{ $message }}</small>@enderror
            </label>

            <div class="pp-row">
                <label class="pp-field"><span>Phone</span>
                    <input type="tel" wire:model.live.debounce.300ms="phone" placeholder="e.g. 0241234567">
                    @error('phone')<small class="pp-err">{{ $message }}</small>@enderror
                </label>
                <label class="pp-field"><span>WhatsApp number</span>
                    <input type="tel" wire:model.live.debounce.300ms="whatsapp" placeholder="e.g. 0241234567">
                    @error('whatsapp')<small class="pp-err">{{ $message }}</small>@enderror
                </label>
            </div>
            <small class="pp-hint">Locked clinics get a WhatsApp button that opens a chat with this number, with the clinic's name already typed.</small>

            <label class="pp-field"><span>Email</span>
                <input type="email" wire:model.live.debounce.300ms="email" placeholder="support@example.com">
                @error('email')<small class="pp-err">{{ $message }}</small>@enderror
            </label>

            <div class="pp-foot">
                <span class="pp-hint" wire:dirty>Unsaved changes</span>
                <button type="submit" class="pp-btn" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">Save</span><span wire:loading wire:target="save">Saving…</span></button>
            </div>
        </form>

        <section class="pp-card" aria-label="Preview">
            <div class="pp-card-head"><div><h2>What clinics see</h2><p>Preview of the contact box on the locked and renewal pages.</p></div></div>
            <div class="sp-preview">
                <b>Need help? Contact {{ $name ?: 'support' }}</b>
                @if($phone)<div>☏ {{ $phone }}</div>@endif
                @if($email)<div>✉ {{ $email }}</div>@endif
                @if(!$phone && !$email && !$whatsapp)<div class="pp-hint">No contact details yet, so clinics only see the name.</div>@endif
                @if($whatsapp)<div class="pp-actions"><span class="pp-btn sm" style="background:#128c4a;cursor:default">WhatsApp {{ $whatsapp }}</span></div>@endif
            </div>
        </section>
    </div>
</x-platform.page>
