<?php

namespace App\Livewire\Admin;

use App\Models\ClinicAddon;
use App\Models\ClinicAddonRequest;
use App\Services\ClinicAddonService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Subscription page: the clinic's add-ons, and the extras on offer it can ask the platform for.
 * The platform adds them (App\Services\ClinicAddonService); the owner is emailed either way.
 */
class ClinicAddonsComponent extends Component
{
    public ?string $requesting = null;
    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
    }

    public function ask(string $feature): void
    {
        $this->requesting = $feature;
        $this->message = '';
        $this->resetErrorBag();
    }

    public function send(ClinicAddonService $addons): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
        $this->validate(['message' => 'nullable|string|max:500']);
        try {
            $request = $addons->request(app(TenantContext::class)->requireClinic(), (string) $this->requesting, trim($this->message), auth()->user());
        } catch (ValidationException $e) {
            $this->addError('message', collect($e->errors())->flatten()->first());
            return;
        }
        $this->requesting = null;
        $this->dispatch('notify', type: 'success', message: "Request for {$request->name()} sent. We'll email you when it's added.");
    }

    public function render(ClinicAddonService $addons)
    {
        $clinic = app(TenantContext::class)->requireClinic();
        $active = ClinicAddon::where('clinic_id', $clinic->id)->activeAt()->orderBy('starts_at')->get();
        $pending = ClinicAddonRequest::where('clinic_id', $clinic->id)->where('status', 'pending')->pluck('feature')->all();
        $offers = $addons->catalogue($clinic)->filter(fn ($offer) => $offer['offered']
            && ! $addons->planIncludes($clinic, $offer['feature']) && ! $active->contains('feature', $offer['feature']));

        return view('livewire.admin.clinic-addons-component', compact('active', 'pending', 'offers'));
    }
}
