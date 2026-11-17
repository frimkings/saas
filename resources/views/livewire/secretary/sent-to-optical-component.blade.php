<div class="clinic-ui ui-page space-y-5">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Sent to Optical</h1>
            <p class="ui-muted text-sm">Spectacle orders sent to linked optical centres: approve their quotes, answer questions and follow each job. Send new ones from Spectacles → Refractions Needing Order.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-guide-tour page="clinic.optical-sent" />
            <x-ui.button :href="route('secretary.spectacles')" wire:navigate>Spectacles</x-ui.button>
            @if($canManageLinks)<x-ui.button :href="route('secretary.optical-owed')" wire:navigate>Owed to optical centres</x-ui.button><x-ui.button :href="route('secretary.optical-centres')" wire:navigate>Optical centres</x-ui.button>@endif
        </div>
    </div>

    <x-ui.flash />

    @unless($hasLinks || $counts->sum())
        <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
            Your clinic isn't linked to an optical centre yet. When an optical centre on Nexus invites you, {{ $canManageLinks ? 'enter its code under Optical centres' : 'a manager enters its code under Optical centres' }}.
        </div>
    @endunless

    <section class="ui-panel bg-white" data-tour="sent-to-optical">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-3">
            <div class="inline-flex overflow-hidden rounded-md border border-teal-700 text-sm font-semibold" role="group" aria-label="Filter requests">
                @foreach(['open' => 'Waiting', 'accepted' => 'Accepted', 'closed' => 'Turned down or cancelled'] as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}" class="px-3 py-1.5 {{ ! $loop->first ? 'border-l border-teal-700' : '' }} {{ $tab === $key ? 'bg-teal-700 text-white' : 'bg-white text-teal-800 hover:bg-teal-50' }}">{{ $label }} ({{ $counts[$key] }})</button>
                @endforeach
            </div>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search patient, folder no. or job no." class="ui-input text-sm !w-72" aria-label="Search sent orders">
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table w-full text-sm">
                <thead><tr><th>Sent</th><th>Patient</th><th>Prescription</th><th>Optical centre</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse($requests as $request)
                        <tr wire:key="sent-{{ $request->id }}">
                            <td>{{ $request->created_at->format('d M Y') }}<div class="text-xs text-slate-500">{{ $request->sender?->name }}</div></td>
                            <td><b>{{ $request->patient_name }}</b>@if($request->isRemake()) <span class="inline-flex rounded bg-amber-100 px-1.5 text-xs font-semibold text-amber-900">Remake</span>@endif<div class="text-xs text-slate-500">{{ $request->sender_reference }}</div></td>
                            <td class="tabular-nums text-xs">R {{ $request->eyeLine('od') }}<br>L {{ $request->eyeLine('os') }}</td>
                            <td>{{ $request->opticalClinic?->name }}@if($request->urgency === 'urgent')<div class="text-xs font-semibold text-red-700">Urgent</div>@endif</td>
                            <td>
                                <span class="inline-flex rounded px-2 py-0.5 text-xs font-semibold {{ match($request->status) { 'query', 'quoted' => 'bg-amber-100 text-amber-900', 'accepted', 'approved' => 'bg-green-100 text-green-800', 'sent' => 'bg-sky-100 text-sky-800', 'quote_declined', 'rejected' => 'bg-red-100 text-red-800', default => 'bg-slate-100 text-slate-700' } }}">{{ $request->progressLabel() }}</span>
                                @if(in_array($request->status, ['quoted', 'approved'], true))
                                    <div class="mt-1 rounded border border-slate-200 bg-slate-50 p-2 text-xs" data-tour="sent-quote">
                                        @foreach((array) $request->quote_lines as $line)<div class="flex justify-between gap-3"><span>{{ $line['label'] }}</span><span class="tabular-nums">{{ number_format((float) $line['amount'], 2) }}</span></div>@endforeach
                                        <div class="mt-1 flex justify-between gap-3 border-t border-slate-200 pt-1 font-semibold"><span>Trade price</span><span class="tabular-nums">{{ currency() }} {{ number_format((float) $request->quote_total, 2) }}</span></div>
                                        @if($request->quote_valid_until)<div class="text-slate-500">{{ $request->quoteExpired() ? 'Expired' : 'Valid until' }} {{ $request->quote_valid_until->format('d M Y') }}</div>@endif
                                    </div>
                                @endif
                                @if($request->status === 'quote_declined')<div class="mt-1 text-xs text-red-700">You declined: {{ $request->decline_reason }}</div>@endif
                                @if($request->status === 'accepted' && $request->balance() > 0)<div class="text-xs text-slate-600">Owed {{ currency() }} {{ number_format($request->balance(), 2) }} of {{ number_format((float) $request->order_total, 2) }}</div>@endif
                                @if($request->order_number)<div class="text-xs text-slate-500">Job {{ $request->order_number }} · updated {{ $request->status_updated_at?->diffForHumans() }}</div>@endif
                                @if($request->status === 'query')<div class="mt-1 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900"><b>Question:</b> {{ $request->query_message }}</div>@endif
                                @if($request->status === 'rejected')<div class="mt-1 text-xs text-red-700">{{ $request->reject_reason }}</div>@endif
                                @if($decliningId === $request->id)
                                    <div class="mt-2 space-y-2">
                                        <textarea wire:model="declineReason" rows="2" class="ui-input text-sm" aria-label="Why you decline the quote" placeholder="e.g. Too expensive for the patient; please quote a single vision lens."></textarea>
                                        @error('declineReason')<p class="ui-error">{{ $message }}</p>@enderror
                                        <div class="flex gap-2"><x-ui.button variant="danger" wire:click="declineQuote">Decline quote</x-ui.button><x-ui.button wire:click="startDecline">Back</x-ui.button></div>
                                    </div>
                                @endif
                                @if($remakeId === $request->id)
                                    <div class="mt-2 space-y-2">
                                        <select wire:model="remakeReason" class="ui-input text-sm" aria-label="Why a remake"><option value="">Why a remake?</option>@foreach($remakeReasons as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                                        @error('remakeReason')<p class="ui-error">{{ $message }}</p>@enderror
                                        <textarea wire:model="remakeNote" rows="2" class="ui-input text-sm" aria-label="Notes for the optical centre" placeholder="What is wrong with the glasses?"></textarea>
                                        <div class="flex gap-2"><x-ui.button variant="primary" wire:click="sendRemake">Ask for a remake</x-ui.button><x-ui.button wire:click="startRemake">Back</x-ui.button></div>
                                    </div>
                                @endif
                                @if($answeringId === $request->id)
                                    <div class="mt-2 space-y-2">
                                        <textarea wire:model="answer" rows="2" class="ui-input text-sm" aria-label="Your answer" placeholder="Your answer for the optical centre"></textarea>
                                        @error('answer')<p class="ui-error">{{ $message }}</p>@enderror
                                        <div class="flex gap-2"><x-ui.button variant="primary" wire:click="sendAnswer">Send answer</x-ui.button><x-ui.button wire:click="$set('answeringId', null)">Cancel</x-ui.button></div>
                                    </div>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                @if($request->status === 'query' && $answeringId !== $request->id)<x-ui.button variant="primary" wire:click="startAnswer({{ $request->id }})">Answer</x-ui.button>@endif
                                @if($request->status === 'quoted' && $decliningId !== $request->id)
                                    @if($request->quoteExpired())
                                        <x-ui.button variant="primary" wire:click="askNewQuote({{ $request->id }})">Ask for a new quote</x-ui.button>
                                    @else
                                        <x-ui.button wire:click="startDecline({{ $request->id }})">Decline</x-ui.button>
                                        <x-ui.button variant="primary" wire:click="approveQuote({{ $request->id }})" wire:confirm="Approve {{ currency() }} {{ number_format((float) $request->quote_total, 2) }}? The optical centre starts the job and bills your clinic.">Approve</x-ui.button>
                                    @endif
                                @endif
                                @if($request->canAskRemake() && $remakeId !== $request->id)<x-ui.button wire:click="startRemake({{ $request->id }})">Ask for a remake</x-ui.button>@endif
                                @if($request->isOpen())<x-ui.button variant="danger" wire:click="cancel({{ $request->id }})" wire:confirm="Cancel this request? The optical centre will see it as cancelled.">Cancel</x-ui.button>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-empty"><p class="ui-muted text-sm">Nothing here.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-200">{{ $requests->links() }}</div>
    </section>
</div>
