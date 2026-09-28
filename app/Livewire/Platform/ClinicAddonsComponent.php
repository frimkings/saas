<?php

namespace App\Livewire\Platform;

use App\Models\Clinic;
use App\Models\ClinicAddon;
use App\Models\ClinicAddonRequest;
use App\Services\ClinicAddonService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Platform → clinic panel → Plan: the clinic's add-ons on top of its plan. Add one (charged pro
 * rata now), change its price, cancel it, and answer the clinic's requests.
 */
class ClinicAddonsComponent extends Component
{
    #[Locked]
    public int $clinicId;

    /** feature => monthly price typed for adding; add-on id => new price; request id => price / note. */
    public array $price = [];
    public array $newPrice = [];
    public array $approvePrice = [];
    public array $rejectNote = [];
    public string $reason = '';

    public function mount(int $clinicId): void
    {
        $this->authorizePlatform();
        $this->clinicId = $clinicId;
    }

    public function add(string $feature, ClinicAddonService $addons): void
    {
        $this->authorizePlatform();
        $offer = $addons->catalogue($this->clinic())->get($feature);
        $price = $this->money($this->price[$feature] ?? $offer['price'] ?? 0, "price.$feature");
        if ($price === null) return;
        $this->run(fn () => $addons->add($this->clinic(), $feature, $price, trim($this->reason) ?: null, auth()->user()), "price.$feature",
            fn ($addon) => "{$addon->name()} added.");
        unset($this->price[$feature]);
        $this->reason = '';
    }

    public function changePrice(int $addonId, ClinicAddonService $addons): void
    {
        $this->authorizePlatform();
        $price = $this->money($this->newPrice[$addonId] ?? null, "newPrice.$addonId");
        if ($price === null) return;
        $this->run(fn () => $addons->changePrice($this->addon($addonId), $price), "newPrice.$addonId",
            fn ($addon) => "{$addon->name()} now costs " . number_format($price, 2) . ' a month from the next renewal.');
        unset($this->newPrice[$addonId]);
    }

    public function cancel(int $addonId, ClinicAddonService $addons): void
    {
        $this->authorizePlatform();
        $this->run(fn () => $addons->cancel($this->addon($addonId), auth()->user()), "addon.$addonId",
            fn ($addon) => "{$addon->name()} cancelled" . ($addon->ends_at?->isFuture() ? '; it works until ' . $addon->ends_at->format('j M Y') . '.' : '.'));
    }

    public function approve(int $requestId, ClinicAddonService $addons): void
    {
        $this->authorizePlatform();
        $typed = $this->approvePrice[$requestId] ?? null;
        $price = $typed === null || $typed === '' ? null : $this->money($typed, "approvePrice.$requestId");
        if ($typed !== null && $typed !== '' && $price === null) return;
        $this->run(fn () => $addons->approve($this->request($requestId), $price, auth()->user()), "approvePrice.$requestId",
            fn ($addon) => "Approved: {$addon->name()} added.");
    }

    public function reject(int $requestId, ClinicAddonService $addons): void
    {
        $this->authorizePlatform();
        $addons->reject($this->request($requestId), trim($this->rejectNote[$requestId] ?? '') ?: null, auth()->user());
        $this->dispatch('notify', type: 'success', message: 'Request declined. The clinic owner has been told.');
    }

    public function render(ClinicAddonService $addons)
    {
        $clinic = $this->clinic();
        $active = ClinicAddon::where('clinic_id', $clinic->id)->activeAt()->orderBy('starts_at')->get();

        return view('livewire.platform.clinic-addons-component', [
            'clinic' => $clinic,
            'catalogue' => $addons->catalogue($clinic),
            'included' => $addons->catalogue($clinic)->filter(fn ($offer) => $addons->planIncludes($clinic, $offer['feature'])),
            'active' => $active,
            'requests' => ClinicAddonRequest::with('requester')->where('clinic_id', $clinic->id)->where('status', 'pending')->oldest()->get(),
            'prorata' => fn (float $price) => $addons->prorata($clinic, $price),
        ]);
    }

    private function run(callable $change, string $errorKey, callable $message): void
    {
        $this->resetErrorBag();
        try {
            $result = $change();
        } catch (ValidationException $e) {
            $this->addError($errorKey, collect($e->errors())->flatten()->first());
            return;
        }
        $this->dispatch('notify', type: 'success', message: $message($result));
    }

    private function money($value, string $errorKey): ?float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            $this->addError($errorKey, 'Enter a price of 0 or more.');
            return null;
        }

        return round((float) $value, 2);
    }

    private function clinic(): Clinic
    {
        return Clinic::findOrFail($this->clinicId);
    }

    private function addon(int $id): ClinicAddon
    {
        return ClinicAddon::where('clinic_id', $this->clinicId)->findOrFail($id);
    }

    private function request(int $id): ClinicAddonRequest
    {
        return ClinicAddonRequest::where('clinic_id', $this->clinicId)->findOrFail($id);
    }

    private function authorizePlatform(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
    }
}
