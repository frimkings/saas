<div class="clinic-ui ui-page space-y-5">
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Optical Centres</h1>
            <p class="ui-muted text-sm">Optical centres on Nexus that make spectacles for your patients. Once linked, staff send orders from the Spectacles page and follow them in Sent to Optical.</p>
        </div>
        <x-ui.button :href="route('secretary.optical-sent')" wire:navigate>Sent to Optical</x-ui.button>
    </div>

    <x-ui.flash />

    <section class="ui-panel bg-white p-4 space-y-3" data-tour="optical-link-code">
        <h2 class="text-base font-semibold text-slate-900 !mb-0">Link with an invite code</h2>
        <p class="text-sm text-slate-600 !mb-0">The optical centre creates the code under Partner Clinics and sends it to you.</p>
        <form wire:submit="check" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="link-code" class="mb-1 block text-sm font-medium text-slate-700">Invite code</label>
                <input id="link-code" type="text" wire:model="code" maxlength="12" autocomplete="off" class="ui-input !w-48 font-mono uppercase tracking-widest" placeholder="e.g. 7KQ4MZ2P">
            </div>
            <x-ui.button type="submit">Check code</x-ui.button>
        </form>
        @error('code')<p class="ui-error" role="alert">{{ $message }}</p>@enderror

        @if($invite)
            <div class="rounded-lg border border-teal-200 bg-teal-50 p-4 text-sm space-y-2">
                <p class="!mb-0"><b>{{ $invite->opticalClinic?->name }}</b> invites your clinic to send it spectacle orders online.</p>
                <ul class="!mb-0 list-disc pl-5 text-slate-700">
                    <li>What they receive with each order: the patient's name, phone number and the prescription. Nothing else from the patient's record.</li>
                    <li>Billing: {{ ($invitePartner?->billing_terms ?? '') === 'on_account' ? 'on account. They bill your clinic, and you settle their statement.' : 'pay on order. You pay them for each job.' }} You charge your patients at your own prices.</li>
                    <li>Either side can pause or unlink at any time. Orders already sent stay on both sides.</li>
                </ul>
                <div class="flex gap-2 pt-1">
                    <x-ui.button variant="primary" wire:click="accept" wire:loading.attr="disabled">Accept and link</x-ui.button>
                    <x-ui.button wire:click="$set('previewInviteId', null)">Not now</x-ui.button>
                </div>
            </div>
        @endif
    </section>

    <section class="ui-panel bg-white">
        <div class="border-b border-slate-200 p-4"><h2 class="text-base font-semibold text-slate-900 !mb-0">Linked optical centres</h2>@error('link')<p class="ui-error !mb-0 mt-1" role="alert">{{ $message }}</p>@enderror</div>
        <div class="ui-table-wrap">
            <table class="ui-table w-full text-sm">
                <thead><tr><th>Optical centre</th><th>Status</th><th>Since</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse($links as $link)
                        <tr wire:key="link-{{ $link->id }}">
                            <td><b>{{ $link->clinic?->name }}</b>@if($link->link_status === 'active' && $link->clinic && ! $service->opticalCanReceive($link->clinic))<div class="text-xs text-amber-700">Not taking orders right now (their subscription).</div>@endif</td>
                            <td><span class="inline-flex rounded px-2 py-0.5 text-xs font-semibold {{ $link->link_status === 'active' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700' }}">{{ \App\Models\OpticalPartnerClinic::LINK_STATUSES[$link->link_status] ?? $link->link_status }}</span></td>
                            <td>{{ $link->linked_at?->format('d M Y') }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if($link->link_status === 'active')
                                    <x-ui.button wire:click="setLink({{ $link->id }}, 'paused')">Pause</x-ui.button>
                                @elseif(! $link->link_paused_by_clinic_id || (int) $link->link_paused_by_clinic_id === (int) $link->linked_clinic_id)
                                    <x-ui.button wire:click="setLink({{ $link->id }}, 'active')">Resume</x-ui.button>
                                @else
                                    <span class="mr-2 text-xs text-slate-500">Paused by the optical centre</span>
                                @endif
                                <x-ui.button variant="danger" wire:click="setLink({{ $link->id }}, 'ended')" wire:confirm="Unlink {{ $link->clinic?->name }}? Staff can't send new orders to them. Orders already sent stay in Sent to Optical.">Unlink</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-empty"><p class="ui-muted text-sm">No optical centres linked yet.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
