<?php

namespace App\Livewire\Platform;

use App\Models\Clinic;
use App\Services\PlatformAuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Attributes\Locked;

class OfflineLicenseComponent extends Component
{
    public ?int $clinicId = null;
    public string $installationId = '', $planName = 'Offline Standard', $expires = '';
    public array $features = [];
    /** What the installation can use: clinic, optical shop, or both (see PlanProduct). */
    public string $product = 'both';
    public string $overviewSearch = '';
    #[Locked]
    public string $generatedKey = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
    }

    public function updated($name = null): void
    {
        $this->generatedKey = '';
        // Switching to an optical shop unticks clinic-only premium features.
        if ($name === 'product') $this->features = array_values(array_intersect($this->features, $this->premiumFeaturesFor($this->product)));
    }

    public function selectAll(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $this->features = $this->premiumFeaturesFor($this->product);
        $this->generatedKey = '';
    }

    /** Premium features that make sense for a product: optical shops don't get clinic-only ones. */
    private function premiumFeaturesFor(string $product): array
    {
        return array_values(array_filter(config('license.pro_features', []),
            fn ($f) => ! ($product === \App\Support\PlanProduct::OPTICAL && (\App\Support\PlanProduct::EXTRAS[$f][1] ?? null) === \App\Support\PlanProduct::CLINIC)));
    }

    public function issue(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $this->generatedKey = '';
        $data = $this->validate([
            'clinicId' => ['required', 'integer', Rule::exists('clinics', 'id')->where('deployment_mode', 'local')->whereNull('deleted_at')],
            'installationId' => 'required|uuid', 'planName' => 'required|string|max:100',
            'expires' => 'required|date_format:Y-m-d|after_or_equal:today',
            'product' => ['required', Rule::in(array_keys(\App\Support\PlanProduct::PRODUCTS))],
            'features' => 'array',
            'features.*' => ['required', 'string', 'distinct', Rule::in(config('license.pro_features', []))],
        ]);
        // The key lists the product (clinical/optical) followed by the chosen premium features.
        $data['features'] = array_values(array_merge(\App\Support\PlanProduct::keys($data['product']),
            array_intersect($data['features'] ?? [], $this->premiumFeaturesFor($data['product']))));
        $path = config('license.signing_key_path');
        if (!$path || !is_file($path) || !is_readable($path)) {
            $this->addError('issuance', 'License signing is not configured. Configure the developer signing key on this server.');
            return;
        }
        $status = Artisan::call('license:issue', [
            'installation' => $data['installationId'], 'expires' => $data['expires'],
            '--plan' => $data['planName'], '--feature' => $data['features'], '--signing-key' => $path,
        ]);
        if ($status !== 0) {
            $this->addError('issuance', 'The license could not be signed. Check that the configured signing key matches the clinic public key.');
            return;
        }
        $key = trim(Artisan::output());
        app(PlatformAuditService::class)->record('OFFLINE_LICENSE_ISSUED', $data['clinicId'], [], [
            'installation_id' => $data['installationId'], 'plan' => $data['planName'],
            'expires' => $data['expires'], 'product' => $data['product'], 'features' => $data['features'], 'key_fingerprint' => hash('sha256', $key),
        ]);
        $this->generatedKey = $key;
    }

    public function recordActivationReport(int $issuanceId): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $issuance = \App\Models\PlatformAuditLog::where('action', 'OFFLINE_LICENSE_ISSUED')->findOrFail($issuanceId);
        app(PlatformAuditService::class)->record('OFFLINE_ACTIVATION_REPORTED', $issuance->clinic_id, [], [
            'issuance_id' => $issuanceId, 'key_fingerprint' => data_get($issuance->new_values, 'key_fingerprint'),
            'source' => 'Clinic reported activation to developer; not remotely verified',
        ]);
    }

    public function render()
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $overview = Clinic::where('name', 'like', '%'.$this->overviewSearch.'%')->orderBy('name')->limit(100)->get()->map(function ($clinic) {
            if ($clinic->deployment_mode === 'hosted') {
                $service = app(\App\Services\SubscriptionService::class);
                $subscription = $service->current($clinic);
                $access = $service->access($clinic);
                return ['clinic' => $clinic, 'status' => ucfirst($access['status']).($access['read_only'] ? ' / writes blocked' : ' / writable'),
                    'plan' => $subscription?->plan?->name ?? 'Not assigned', 'features' => $subscription?->feature_snapshot ?? [],
                    'expiry' => ($subscription?->status === 'trial' ? $subscription->trial_ends_at : $subscription?->current_period_ends_at)?->format('d M Y H:i T'),
                    'issuance' => null, 'reported' => null];
            }
            $issuance = \App\Models\PlatformAuditLog::where('clinic_id', $clinic->id)->where('action', 'OFFLINE_LICENSE_ISSUED')->latest('id')->first();
            $reported = $issuance ? \App\Models\PlatformAuditLog::where('clinic_id', $clinic->id)->where('action', 'OFFLINE_ACTIVATION_REPORTED')
                ->where('new_values->issuance_id', $issuance->id)->latest('id')->first() : null;
            $expiry = data_get($issuance?->new_values, 'expires');
            return ['clinic' => $clinic, 'status' => !$issuance ? 'No issuance recorded' : ($expiry < now()->toDateString() ? 'Latest issued key expired' : 'Latest issued key within validity'),
                'plan' => data_get($issuance?->new_values, 'plan', 'Unknown'), 'features' => data_get($issuance?->new_values, 'features', []),
                'expiry' => $expiry, 'issuance' => $issuance, 'reported' => $reported];
        });
        return view('livewire.platform.offline-license-component', [
            'clinics' => Clinic::where('deployment_mode', 'local')->orderBy('name')->get(),
            'availableFeatures' => $this->premiumFeaturesFor($this->product),
            'overview' => $overview,
            'history' => \App\Models\PlatformAuditLog::whereIn('action', ['OFFLINE_LICENSE_ISSUED', 'OFFLINE_ACTIVATION_REPORTED'])->with(['clinic', 'user'])->latest('id')->limit(50)->get(),
        ]);
    }
}
