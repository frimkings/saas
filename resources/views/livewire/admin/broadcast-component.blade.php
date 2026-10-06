<div class="clinic-ui ui-page" style="max-width:820px;margin:0 auto">
    <div class="mb-4">
        <p class="text-slate-500 text-sm uppercase font-semibold mb-0">Communications</p>
        <h2 class="text-teal-700 font-semibold mb-1">Broadcast</h2>
        <p class="text-slate-500 text-sm mb-0">Send one message to a group of patients — a holiday greeting, a new service, a screening day. Patients who opted out of marketing messages are skipped.</p>
    </div>

    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mb-4"
         x-data="{ text: $wire.entangle('message'), name: @js($sampleName), clinic: @js($clinicName),
                   get preview() { return (this.text || '').split('[NAME]').join(this.name).split('[CLINIC]').join(this.clinic); },
                   get parts() { const n = this.preview.length; return n <= 160 ? 1 : Math.ceil(n / 153); } }">
        <div class="card-body p-4">
            <label class="text-sm font-semibold text-slate-500 mb-1" for="broadcast-message">Message</label>
            <textarea id="broadcast-message" x-model="text" rows="4" maxlength="1000" class="form-control ui-input bg-slate-50 border-0 @error('message') is-invalid @enderror"></textarea>
            @error('message') <span class="text-red-700 text-sm block mt-1">{{ $message }}</span> @enderror
            <div class="mt-2 text-sm">
                <span class="font-semibold text-slate-500 mr-1">Insert:</span>
                @foreach($placeholders as $ph)<code class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 border border-slate-200 mr-1">{{ $ph }}</code>@endforeach
            </div>
            <label class="text-sm font-semibold text-slate-500 mt-4 mb-1">Preview</label>
            <div class="border border-slate-200 rounded-md p-2 bg-white text-sm" style="white-space:pre-wrap" x-text="preview"></div>
            <div class="text-sm text-slate-500 mt-1"><span x-text="preview.length"></span> characters · <strong x-text="parts"></strong> SMS credit(s) per patient</div>
            <div class="text-right mt-2">
                <button type="button" wire:click="saveMessage" class="btn ui-button ui-button-sm ui-button-secondary"><i class="fas fa-save mr-1"></i> Save message</button>
            </div>
        </div>
    </div>

    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm">
        <div class="card-body p-4">
            <label class="text-sm font-semibold text-slate-500 block mb-2">Send to</label>
            @foreach(['all' => ['All patients', 'with a contact number'], 'this_year' => ['Active this year', 'visited in the current calendar year'],
                      'last_24_months' => ['Active in the last 24 months', ''], 'custom' => ['Active in the last…', 'months you choose']] as $value => [$title, $hint])
                <div class="flex items-center gap-2 mb-1">
                    <input type="radio" id="brf_{{ $value }}" name="broadcastFilter" value="{{ $value }}" class="rounded border-slate-300 text-teal-700" wire:model.live="broadcastFilter">
                    <label class="text-sm" for="brf_{{ $value }}"><strong>{{ $title }}</strong>{{ $hint ? ' — ' . $hint : '' }}</label>
                </div>
            @endforeach
            @if($broadcastFilter === 'custom')
                <div class="mt-1 ml-6 flex items-center">
                    <input type="number" wire:model="broadcastCustomMonths" min="1" max="120" placeholder="e.g. 18" style="width:90px"
                           class="form-control ui-input ui-input-sm bg-slate-50 border-0 @error('broadcastCustomMonths') is-invalid @enderror">
                    <span class="ml-2 text-sm text-slate-500">months back</span>
                </div>
                @error('broadcastCustomMonths') <span class="text-red-700 text-sm block ml-6">{{ $message }}</span> @enderror
            @endif

            <div class="mt-4">
                @if($broadcastConfirmStep)
                    <div class="rounded-lg border text-sm border-amber-200 bg-amber-50 text-amber-900 border-0 py-2 px-4 mb-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i> This sends the message to <strong>{{ $broadcastRecipientCount }} patient(s)</strong> and uses that many SMS credits or more. Send now?
                    </div>
                    <button type="button" wire:click="sendCustomBroadcast" wire:loading.attr="disabled" wire:target="sendCustomBroadcast" class="btn ui-button ui-button-sm ui-button-danger font-semibold mr-2">
                        <span wire:loading.remove wire:target="sendCustomBroadcast"><i class="fas fa-paper-plane mr-1"></i> Yes, send now</span>
                        <span wire:loading wire:target="sendCustomBroadcast">Sending…</span>
                    </button>
                    <button type="button" wire:click="cancelBroadcast" class="btn ui-button ui-button-sm ui-button-secondary">Cancel</button>
                @else
                    <button type="button" wire:click="prepareBroadcast" wire:loading.attr="disabled" wire:target="prepareBroadcast" class="btn ui-button ui-button-sm ui-button-primary font-semibold">
                        <span wire:loading.remove wire:target="prepareBroadcast"><i class="fas fa-bullhorn mr-1"></i> Count recipients &amp; send</span>
                        <span wire:loading wire:target="prepareBroadcast">Counting…</span>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
