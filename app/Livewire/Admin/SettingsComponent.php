<?php
namespace App\Livewire\Admin;

use App\Models\Setting;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SettingsComponent extends Component
{
    use WithFileUploads;

    public $state = [
        'clinic_name' => '',
        'clinic_address' => '',
        'clinic_contact' => '',
        'clinic_email' => '',
    ];

    public $newLogo;
    public $currentLogo;
    public $uploadInputKey = 0;
    public $missingSetupFields = [];
    public string $va_notation      = '6m';
    public string $currency_symbol  = 'GH₵';

    protected function rules()
    {
        return [
            'state.clinic_address' => 'nullable|string|max:255',
            'state.clinic_contact' => 'nullable|string|max:50',
            'state.clinic_email'   => 'nullable|email|max:100',
            'newLogo'              => 'nullable|image|max:2048',
            'currency_symbol'      => ['required', 'string', 'max:10', \Illuminate\Validation\Rule::in(array_keys(\App\Models\Setting::CURRENCIES))],
        ];
    }

    public function mount()
    {
        abort_if(!Auth::user()->hasRole('Super Admin'), 403);

        $setting = Setting::getSettings();
        $this->fillFromSetting($setting);
        $this->missingSetupFields = $setting->missingSetupFields();
        $this->va_notation     = $setting->va_notation     ?? '6m';
        $this->currency_symbol = $setting->currency_symbol ?? \App\Models\Setting::DEFAULT_CURRENCY;
    }

    public function updateSettings()
    {
        $this->validate();

        $setting = Setting::getSettings();

        $data = [
            'clinic_address' => $this->nullableValue($this->state['clinic_address']),
            'clinic_contact' => $this->nullableValue($this->state['clinic_contact']),
            'clinic_email' => $this->nullableValue($this->state['clinic_email']),
            'va_notation'      => in_array($this->va_notation, ['6m', '20ft']) ? $this->va_notation : '6m',
            'currency_symbol'  => array_key_exists($this->currency_symbol, \App\Models\Setting::CURRENCIES) ? $this->currency_symbol : \App\Models\Setting::DEFAULT_CURRENCY,
        ];

        if ($this->newLogo) {
            // Delete old logo file if it exists to save server space
            if ($setting->clinic_logo) {
                Storage::disk('public')->delete($setting->clinic_logo);
            }

            // Store new logo
            $data['clinic_logo'] = $this->newLogo->store(\App\Support\Tenancy\TenantStorage::branding(), 'public');
        }

        $setting->update($data);
        Cache::forget('pos.settings');
        \App\Models\Setting::clearCurrencyCache();

        // Reset the file input after saving
        $this->newLogo = null;
        $this->uploadInputKey++;
        $setting = $setting->fresh();
        $this->fillFromSetting($setting);
        $this->missingSetupFields  = $setting->missingSetupFields();
        $this->currency_symbol     = $setting->currency_symbol ?? \App\Models\Setting::DEFAULT_CURRENCY;

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => $setting->needsSetup()
                ? 'Settings saved. Please complete the highlighted clinic details.'
                : 'Branding and contact details updated!',
        ]);
    }

    public function removeLogo()
    {
        $setting = Setting::getSettings();

        if ($setting->clinic_logo) {
            Storage::disk('public')->delete($setting->clinic_logo);
            $setting->update(['clinic_logo' => null]);
            Cache::forget('pos.settings');
        }

        $this->newLogo = null;
        $this->uploadInputKey++;
        $setting = $setting->fresh();
        $this->fillFromSetting($setting);
        $this->missingSetupFields = $setting->missingSetupFields();

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Clinic logo removed.'
        ]);
    }

    public function discardChanges()
    {
        $setting = Setting::getSettings();

        $this->newLogo = null;
        $this->uploadInputKey++;
        $setting = $setting->fresh();
        $this->fillFromSetting($setting);
        $this->missingSetupFields = $setting->missingSetupFields();
        $this->va_notation = $setting->va_notation ?? Setting::DEFAULT_VA_NOTATION;
        $this->currency_symbol = $setting->currency_symbol ?? Setting::DEFAULT_CURRENCY;
        $this->resetValidation();

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Unsaved changes discarded.'
        ]);
    }

    private function fillFromSetting(Setting $setting): void
    {
        $this->state = [
            'clinic_name' => $setting->clinic_name ?? '',
            'clinic_address' => $setting->clinic_address ?? '',
            'clinic_contact' => $setting->clinic_contact ?? '',
            'clinic_email' => strtolower((string) $setting->clinic_email) === 'n/a' ? '' : ($setting->clinic_email ?? ''),
        ];
        foreach ([
            'clinic_address' => Setting::DEFAULT_CLINIC_ADDRESS,
            'clinic_contact' => Setting::DEFAULT_CLINIC_CONTACT,
            'clinic_email' => Setting::DEFAULT_CLINIC_EMAIL,
        ] as $field => $placeholder) {
            if (strcasecmp(trim($this->state[$field]), $placeholder) === 0) {
                $this->state[$field] = '';
            }
        }
        $this->currentLogo = $setting->clinic_logo;
    }

    private function nullableValue($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function render()
    {
        return view('livewire.admin.settings-component', [
            'setting' => Setting::getSettings(),
            'activeClinic' => app(\App\Support\Tenancy\TenantContext::class)->clinic(),
        ])->layout('layouts.admin.admin-layout');
    }
}
