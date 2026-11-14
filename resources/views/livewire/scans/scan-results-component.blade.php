<div class="space-y-3" data-tour="scan-results">
    <section class="oo-section">
        <h3>Results</h3>
        @if($order->documents->isNotEmpty())
            <table class="oo-lines">
                <tbody>
                    @foreach($order->documents as $document)
                        <tr wire:key="sr-doc-{{ $document->id }}">
                            <td><a href="{{ route('patient-documents.show', $document) }}" target="_blank" rel="noopener" class="oo-link">{{ $document->title }}</a>
                                <span class="oo-sub">{{ $types[$document->document_type] ?? 'File' }} · {{ $document->original_name }} · {{ $document->created_at->format('d M, H:i') }}</span></td>
                            <td class="oo-num" style="width:90px">{{ number_format(max(1, $document->file_size / 1024), 0) }} KB</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="ds-hint" style="margin:6px 0 0">Also in the patient's file under Clinical Documents.</p>
        @elseif(! $order->sale_id || $order->status === 'cancelled')
            <p class="ui-muted text-sm m-0">Results can be added once the order is billed.</p>
        @else
            <p class="ui-muted text-sm m-0">No result files yet.</p>
        @endif

        @if($order->sale_id && $order->status !== 'cancelled')
            <form wire:submit="saveResults" class="ds-2 mt-2.5">
                <label class="ds-field" style="grid-column:1/-1"><span>Result files (PDF, JPG or PNG)</span>
                    <input type="file" data-max-kb="{{ \App\Support\UploadLimits::CLINIC_KB }}" multiple accept=".pdf,.jpg,.jpeg,.png" wire:model="resultFiles" wire:key="sr-input-{{ $inputKey }}" class="ui-input">
                    <span wire:loading wire:target="resultFiles" class="ds-hint">Uploading…</span>
                    @error('resultFiles')<small class="ds-err">{{ $message }}</small>@enderror
                    @error('resultFiles.*')<small class="ds-err">{{ $message }}</small>@enderror</label>
                <label class="ds-field"><span>What it is</span><select wire:model="resultType" class="ui-input">@foreach($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label class="ds-field"><span>Title (optional)</span><input type="text" wire:model="resultTitle" maxlength="150" class="ui-input" placeholder="e.g. OCT RNFL both eyes"></label>
                <div style="grid-column:1/-1" class="flex justify-end">
                    <button type="submit" class="oo-btn" wire:loading.attr="disabled" wire:target="saveResults,resultFiles">Add results</button>
                </div>
            </form>
        @endif
    </section>

    @if($order->source === 'partner')
        <section class="oo-section" data-tour="scan-send">
            <h3>Send to {{ $order->partner?->name ?? 'the referring clinic' }}</h3>
            @error('send')<div class="oo-note red">{{ $message }}</div>@enderror
            @if($order->results_sent_at)
                <div class="oo-note green" style="margin-bottom:8px">Sent {{ $order->results_sent_at->format('d M Y, H:i') }} · {{ $channels[$order->results_sent_via] ?? $order->results_sent_via }}@if($order->sender) · {{ $order->sender->name }}@endif</div>
            @endif
            @if(! $order->share_consent)
                <p class="text-sm m-0">The patient has not agreed to share their results with the referring clinic, so they are not sent.</p>
                @if($order->status !== 'cancelled')
                    <button type="button" class="oo-btn mt-2" wire:click="recordConsent" wire:confirm="Record that {{ $order->patient?->name ?? 'the patient' }} now agrees to share their results with {{ $order->partner?->name ?? 'the referring clinic' }}?">The patient agrees now</button>
                @endif
            @elseif(! in_array($order->status, ['reported', 'sent'], true))
                <p class="ui-muted text-sm m-0">The patient agreed. Add the results and mark the scans reported, then send them from here.</p>
            @elseif(! $canSend)
                <p class="ui-muted text-sm m-0">Add the result files above, then send them from here.</p>
            @else
                <p class="ds-hint" style="margin:0 0 8px">They get a link to the result files that works for {{ $days }} days. No files go in the message itself.</p>
                <div class="flex flex-wrap gap-2">
                    @if($whatsApp)
                        <a href="{{ $whatsApp }}" target="_blank" rel="noopener" class="oo-btn primary" wire:click="send('whatsapp')">WhatsApp</a>
                    @endif
                    @if($order->partner?->messagingPhone())
                        <button type="button" class="oo-btn" wire:click="send('sms')" wire:confirm="Text the results link to {{ $order->partner->messagingPhone() }}? It uses SMS credit.">SMS</button>
                    @endif
                    @if($order->partner?->email)
                        <button type="button" class="oo-btn" wire:click="send('email')" wire:confirm="Email the results link to {{ $order->partner->email }}?">Email</button>
                    @endif
                    <button type="button" class="oo-btn" x-data x-on:click="navigator.clipboard?.writeText(@js($shareLink)); $wire.send('link')">Copy link</button>
                </div>
                @if(! $order->partner?->messagingPhone() && ! $order->partner?->email)
                    <p class="ds-hint" style="margin:8px 0 0">{{ $order->partner?->name }} has no phone or email for messages. Add one under Partner Clinics, or copy the link.</p>
                @elseif($order->partner?->notify_via && $order->partner->notify_via !== 'none')
                    <p class="ds-hint" style="margin:8px 0 0">They prefer {{ \App\Models\ScanPartnerClinic::NOTIFY_VIA[$order->partner->notify_via] ?? $order->partner->notify_via }}.</p>
                @endif
            @endif
        </section>
    @endif
</div>
