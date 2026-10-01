<div class="container-fluid py-4" style="max-width:820px;margin:0 auto">
    <div class="mb-3">
        <p class="text-muted small text-uppercase font-weight-bold mb-0">Communications</p>
        <h2 class="text-primary font-weight-bold mb-1">Broadcast</h2>
        <p class="text-muted small mb-0">Send one message to a group of patients — a holiday greeting, a new service, a screening day. Patients who opted out of marketing messages are skipped.</p>
    </div>

    <div class="card border-0 shadow-sm mb-3"
         x-data="{ text: $wire.entangle('message'), name: @js($sampleName), clinic: @js($clinicName),
                   get preview() { return (this.text || '').split('[NAME]').join(this.name).split('[CLINIC]').join(this.clinic); },
                   get parts() { const n = this.preview.length; return n <= 160 ? 1 : Math.ceil(n / 153); } }">
        <div class="card-body">
            <label class="small font-weight-bold text-muted mb-1" for="broadcast-message">Message</label>
            <textarea id="broadcast-message" x-model="text" rows="4" maxlength="1000" class="form-control bg-light border-0 @error('message') is-invalid @enderror"></textarea>
            @error('message') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
            <div class="mt-2 small">
                <span class="font-weight-bold text-muted mr-1">Insert:</span>
                @foreach($placeholders as $ph)<code class="badge badge-light border mr-1">{{ $ph }}</code>@endforeach
            </div>
            <label class="small font-weight-bold text-muted mt-3 mb-1">Preview</label>
            <div class="border rounded p-2 bg-white small" style="white-space:pre-wrap" x-text="preview"></div>
            <div class="small text-muted mt-1"><span x-text="preview.length"></span> characters · <strong x-text="parts"></strong> SMS credit(s) per patient</div>
            <div class="text-right mt-2">
                <button type="button" wire:click="saveMessage" class="btn btn-sm btn-outline-primary"><i class="fas fa-save mr-1"></i> Save message</button>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <label class="small font-weight-bold text-muted d-block mb-2">Send to</label>
            @foreach(['all' => ['All patients', 'with a contact number'], 'this_year' => ['Active this year', 'visited in the current calendar year'],
                      'last_24_months' => ['Active in the last 24 months', ''], 'custom' => ['Active in the last…', 'months you choose']] as $value => [$title, $hint])
                <div class="custom-control custom-radio mb-1">
                    <input type="radio" id="brf_{{ $value }}" name="broadcastFilter" value="{{ $value }}" class="custom-control-input" wire:model.live="broadcastFilter">
                    <label class="custom-control-label small" for="brf_{{ $value }}"><strong>{{ $title }}</strong>{{ $hint ? ' — ' . $hint : '' }}</label>
                </div>
            @endforeach
            @if($broadcastFilter === 'custom')
                <div class="mt-1 ml-4 d-flex align-items-center">
                    <input type="number" wire:model="broadcastCustomMonths" min="1" max="120" placeholder="e.g. 18" style="width:90px"
                           class="form-control form-control-sm bg-light border-0 @error('broadcastCustomMonths') is-invalid @enderror">
                    <span class="ml-2 small text-muted">months back</span>
                </div>
                @error('broadcastCustomMonths') <span class="text-danger small d-block ml-4">{{ $message }}</span> @enderror
            @endif

            <div class="mt-3">
                @if($broadcastConfirmStep)
                    <div class="alert alert-warning border-0 py-2 px-3 small mb-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i> This sends the message to <strong>{{ $broadcastRecipientCount }} patient(s)</strong> and uses that many SMS credits or more. Send now?
                    </div>
                    <button type="button" wire:click="sendCustomBroadcast" wire:loading.attr="disabled" wire:target="sendCustomBroadcast" class="btn btn-sm btn-danger font-weight-bold mr-2">
                        <span wire:loading.remove wire:target="sendCustomBroadcast"><i class="fas fa-paper-plane mr-1"></i> Yes, send now</span>
                        <span wire:loading wire:target="sendCustomBroadcast">Sending…</span>
                    </button>
                    <button type="button" wire:click="cancelBroadcast" class="btn btn-sm btn-outline-secondary">Cancel</button>
                @else
                    <button type="button" wire:click="prepareBroadcast" wire:loading.attr="disabled" wire:target="prepareBroadcast" class="btn btn-sm btn-primary font-weight-bold">
                        <span wire:loading.remove wire:target="prepareBroadcast"><i class="fas fa-bullhorn mr-1"></i> Count recipients &amp; send</span>
                        <span wire:loading wire:target="prepareBroadcast">Counting…</span>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
