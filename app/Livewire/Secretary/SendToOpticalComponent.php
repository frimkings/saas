<?php

namespace App\Livewire\Secretary;

use App\Models\OpticalOrderRequest;
use App\Models\Refractions;
use App\Services\OpticalLinks\OpticalLinkService;
use App\Support\Optical\ClinicSpectacleBilling;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** The "Send to Optical Centre" dialog on the Spectacles page. */
class SendToOpticalComponent extends Component
{
    /** Same rule as ordering here (Spectacle Orders): a part or full payment at POS first. */
    private const UNPAID = 'Record a part or full payment at POS before sending to an optical centre.';

    public bool $open = false;
    #[Locked]
    public ?int $refractionId = null;
    public ?int $partnerId = null;
    public string $frameChoice = 'patient_chooses';
    public string $frameNotes = '';
    public string $lensNotes = '';
    public string $urgency = 'normal';
    public string $notes = '';

    #[On('send-to-optical')]
    public function start(int $refractionId): void
    {
        $refraction = Refractions::with('consultation.patient')->findOrFail($refractionId);
        abort_unless($refraction->dispensing_required && $refraction->dispensing_authorized_at, 403);
        if (! ClinicSpectacleBilling::paidEnough(ClinicSpectacleBilling::summary($refraction))) {
            $this->dispatch('notify', type: 'error', message: self::UNPAID);
            return;
        }
        $this->reset(['frameNotes', 'lensNotes', 'notes']);
        $this->frameChoice = 'patient_chooses';
        $this->urgency = 'normal';
        $this->refractionId = $refraction->id;
        $targets = app(OpticalLinkService::class)->sendTargets();
        $this->partnerId = $targets->count() === 1 ? $targets->first()->id : null;
        $this->resetErrorBag();
        $this->open = true;
    }

    public function send(): void
    {
        $this->validate([
            'partnerId' => 'required|integer',
            'frameChoice' => 'required|in:'.implode(',', array_keys(OpticalOrderRequest::FRAME_CHOICES)),
            'frameNotes' => 'nullable|string|max:1000',
            'lensNotes' => 'nullable|string|max:1000',
            'urgency' => 'required|in:normal,urgent',
            'notes' => 'nullable|string|max:2000',
        ], ['partnerId.required' => 'Choose the optical centre.']);
        $refraction = Refractions::with(['consultation.patient', 'lensOrder'])->findOrFail((int) $this->refractionId);
        // Checked again on Send: the payment may have been refunded while the dialog was open.
        if (! ClinicSpectacleBilling::paidEnough(ClinicSpectacleBilling::summary($refraction))) {
            throw ValidationException::withMessages(['partnerId' => self::UNPAID]);
        }
        $request = app(OpticalLinkService::class)->send($refraction, (int) $this->partnerId, [
            'frame_choice' => $this->frameChoice, 'frame_notes' => $this->frameNotes, 'lens_notes' => $this->lensNotes,
            'urgency' => $this->urgency, 'notes' => $this->notes,
        ], auth()->user());
        $this->open = false;
        $this->dispatch('optical-request-sent');
        $this->dispatch('notify', type: 'success', message: 'Sent to '.($request->opticalClinic?->name ?? 'the optical centre').'. Follow it in Sent to Optical.');
    }

    public function render()
    {
        $refraction = $this->open && $this->refractionId ? Refractions::with('consultation.patient')->find($this->refractionId) : null;

        return view('livewire.secretary.send-to-optical-component', [
            'refraction' => $refraction,
            'targets' => $this->open ? app(OpticalLinkService::class)->sendTargets() : collect(),
            'rx' => $refraction ? OpticalLinkService::rxFromRefraction($refraction) : null,
        ]);
    }
}
