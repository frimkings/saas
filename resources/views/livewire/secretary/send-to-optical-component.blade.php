<div>
    @if($open && $refraction)
        @php
            $patient = $refraction->consultation?->patient;
            $preview = new \App\Models\OpticalOrderRequest(['rx' => $rx]);
        @endphp
        <x-ui.modal title="Send to Optical Centre" state="open">
            <form wire:submit="send" class="space-y-4 p-4">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                    <p class="!mb-1"><b>{{ $patient?->name }}</b> <span class="text-slate-500">· {{ $patient?->pxnumber }}</span></p>
                    <p class="!mb-0 tabular-nums">R {{ $preview->eyeLine('od') }} · L {{ $preview->eyeLine('os') }} · PD {{ data_get($rx, 'pd') ?: '—' }}@if($refraction->lensType) · {{ $refraction->lensType }}@endif</p>
                </div>
                <p class="!mb-0 text-xs text-slate-500">The optical centre receives only the patient's name, phone number and this prescription. You charge the patient here; the optical centre bills your clinic.</p>

                <x-ui.field label="Optical centre" name="partnerId" wire:model="partnerId" :options="$targets->mapWithKeys(fn ($p) => [$p->id => $p->clinic?->name.(\App\Services\OpticalLinks\OpticalLinkTermsService::overLimit($p) ? ' (credit limit reached)' : ($p->hasTradeTerms() ? ' · trade '.$p->tradeSummary() : ''))])->all()" placeholder-option="Choose…" required />
                <x-ui.field label="Frame" name="frameChoice" wire:model.live="frameChoice" :options="\App\Models\OpticalOrderRequest::FRAME_CHOICES" required />
                @if($frameChoice !== 'patient_chooses')
                    <x-ui.field label="Frame details" name="frameNotes" type="textarea" rows="2" wire:model="frameNotes" placeholder="Brand, model, colour or condition of the patient's frame" />
                @endif
                <x-ui.field label="Lens notes" name="lensNotes" type="textarea" rows="2" wire:model="lensNotes" placeholder="e.g. photochromic, blue filter, 1.67 index" />
                <x-ui.field label="Urgency" name="urgency" wire:model="urgency" :options="['normal' => 'Normal', 'urgent' => 'Urgent']" />
                <x-ui.field label="Other notes" name="notes" type="textarea" rows="2" wire:model="notes" />

                <div class="flex items-center justify-end gap-2">
                    <x-ui.button wire:click="$set('open', false)">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="send"><i class="fas fa-paper-plane mr-1"></i>Send</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
