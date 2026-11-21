<?php

namespace App\Livewire\Secretary;

use App\Models\OpticalLinkInvite;
use App\Models\OpticalPartnerClinic;
use App\Services\OpticalLinks\OpticalLinkService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The clinic's links to optical centres: accept an invite code, pause or unlink. Owners and managers. */
class OpticalCentresComponent extends Component
{
    public string $code = '';
    #[Locked]
    public ?int $previewInviteId = null;
    /** Per link: approve quotes up to this amount automatically (blank: approve each yourself). */
    public array $autoApprove = [];

    public function mount(): void
    {
        abort_unless(app(OpticalLinkService::class)->canManageLinks(auth()->user()), 403);
        foreach (app(OpticalLinkService::class)->linksForSender() as $link) {
            $this->autoApprove[$link->id] = $link->auto_approve_limit === null ? '' : number_format((float) $link->auto_approve_limit, 2, '.', '');
        }
        if (request()->filled('code')) {
            $this->code = strtoupper(trim((string) request('code')));
            $this->check();
        }
    }

    public function check(): void
    {
        $this->previewInviteId = null;
        $this->resetErrorBag();
        $this->code = strtoupper(preg_replace('/\s+/', '', $this->code));
        if ($this->code === '') {
            $this->addError('code', 'Enter the code the optical centre gave you.');
            return;
        }
        try {
            $this->previewInviteId = app(OpticalLinkService::class)->findInvite($this->code)->id;
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        }
    }

    public function accept(): void
    {
        $partner = app(OpticalLinkService::class)->acceptInvite($this->code, auth()->user());
        $this->reset(['code', 'previewInviteId']);
        session()->flash('success', 'Linked to '.($partner->clinic?->name ?? 'the optical centre').'. Staff can now send orders from the Spectacles page.');
    }

    public function setLink(int $partnerId, string $status): void
    {
        $partner = app(OpticalLinkService::class)->linksForSender()->firstWhere('id', $partnerId);
        abort_unless($partner, 404);
        app(OpticalLinkService::class)->setLinkStatus($partner, $status, auth()->user());
        session()->flash('success', $status === 'ended' ? 'Unlinked. Orders already sent stay in Sent to Optical.' : ($status === 'paused' ? 'Link paused.' : 'Link resumed.'));
    }

    public function saveAutoApprove(int $partnerId): void
    {
        $this->resetErrorBag();
        app(\App\Services\OpticalLinks\OpticalLinkTermsService::class)->setAutoApprove($partnerId, (string) ($this->autoApprove[$partnerId] ?? ''), auth()->user());
        $value = trim((string) ($this->autoApprove[$partnerId] ?? ''));
        session()->flash('success', $value === '' || (float) $value <= 0
            ? 'You will approve every quote from this optical centre yourself.'
            : 'Quotes up to '.currency().' '.number_format((float) $value, 2).' are now approved automatically, and the job starts at once.');
    }

    /** Owner only: let a linked optical centre look up our patients by phone or patient number, or stop it. */
    public function setPatientLookup(int $partnerId, bool $on): void
    {
        $partner = app(OpticalLinkService::class)->setPatientLookup($partnerId, $on, auth()->user());
        session()->flash('success', $on
            ? $partner->clinic?->name.' can now look up your patients by full phone or patient number. Every lookup is in your audit trail.'
            : $partner->clinic?->name.' can no longer look up your patients.');
    }

    public function render()
    {
        $invite = $this->previewInviteId ? OpticalLinkInvite::with('opticalClinic')->find($this->previewInviteId) : null;

        return view('livewire.secretary.optical-centres-component', [
            'links' => app(OpticalLinkService::class)->linksForSender(),
            'invite' => $invite,
            'invitePartner' => $invite ? OpticalPartnerClinic::withoutGlobalScopes()->find($invite->optical_partner_clinic_id) : null,
            'service' => app(OpticalLinkService::class),
            'isOwner' => OpticalLinkService::isOwner(auth()->user()),
        ])->layout('layouts.clinic', ['menu' => 'reception']);
    }
}
