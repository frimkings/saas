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
    /** Initials patient numbers start with (VS-2611-042); fixed once a number is given out. */
    public string $patient_prefix   = '';

    protected function rules()
    {
        return [
            'state.clinic_name'    => 'required|string|min:2|max:150',
            'state.clinic_address' => 'nullable|string|max:255',
            'state.clinic_contact' => 'nullable|string|max:50',
            'state.clinic_email'   => 'nullable|email|max:100',
            // Big enough to print sharp, small enough to load fast and fit in a PDF (see LOGO_RULES).
            'newLogo'              => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:' . self::LOGO_MAX_KB,
                'dimensions:min_width=' . self::LOGO_MIN_PX . ',min_height=' . self::LOGO_MIN_PX . ',max_width=' . self::LOGO_MAX_PX . ',max_height=' . self::LOGO_MAX_PX],
            'patient_prefix'       => [$this->clinic() ? 'required' : 'nullable', 'regex:/^[A-Za-z]{2,3}$/'],
            'currency_symbol'      => ['required', 'string', 'max:10', \Illuminate\Validation\Rule::in(array_keys(\App\Models\Setting::CURRENCIES))],
        ];
    }

    public const LOGO_MAX_KB = 1024;
    public const LOGO_MIN_PX = 100;
    public const LOGO_MAX_PX = 2000;
    /** Shown under the upload field and repeated in the error messages. */
    public const LOGO_RULES = 'PNG, JPG or WebP, up to 1 MB, between 100 and 2000 pixels on each side. Best: a PNG with a transparent background, about 600 pixels wide.';

    protected function messages()
    {
        return [
            'patient_prefix.required' => 'Give the clinic 2 or 3 letters for its patient numbers, e.g. VS.',
            'patient_prefix.regex' => 'Use 2 or 3 letters only, e.g. VS.',
            'newLogo.image' => 'The logo must be an image: ' . self::LOGO_RULES,
            'newLogo.mimes' => 'The logo must be a PNG, JPG or WebP file.',
            'newLogo.max' => 'The logo is over 1 MB. Save a smaller copy (about 600 pixels wide is plenty) and try again.',
            'newLogo.dimensions' => 'The logo must be between 100 and 2000 pixels on each side. Resize it (about 600 pixels wide is plenty) and try again.',
        ];
    }

    /** Say what is wrong with a logo as soon as it is chosen, not on save. */
    public function updatedNewLogo(): void
    {
        try {
            $this->validateOnly('newLogo');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Drop it, so the preview and Save don't use a file that was refused.
            $this->newLogo = null;
            $this->uploadInputKey++;
            throw $e;
        }
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

        $this->renameClinic($setting, trim((string) $this->state['clinic_name']));
        $this->savePatientPrefix();

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
        $this->patient_prefix = $this->clinic()?->patientPrefix() ?? '';
    }

    private function clinic(): ?\App\Models\Clinic
    {
        return app(\App\Support\Tenancy\TenantContext::class)->clinic();
    }

    /** Saved only until the first number in the new format is given out; after that it stays. */
    private function savePatientPrefix(): void
    {
        $clinic = $this->clinic();
        $prefix = strtoupper(trim($this->patient_prefix));
        if (! $clinic || $clinic->patientPrefixLocked() || $prefix === $clinic->patient_prefix) {
            return;
        }

        $old = $clinic->patient_prefix;
        $clinic->update(['patient_prefix' => $prefix]);
        \App\Models\AuditTrail::record('clinic.patient_prefix', "Patient number initials set to {$prefix}",
            $clinic, ['patient_prefix' => $old], ['patient_prefix' => $prefix], null, true);
    }

    /**
     * The name lives on the clinic (the settings row falls back to its own copy for legacy
     * installs), so both are kept in step. The web address (slug) is left as it is.
     */
    private function renameClinic(Setting $setting, string $name): void
    {
        $old = (string) $setting->clinic_name;
        if ($name === '' || $name === $old) {
            return;
        }

        $clinic = app(\App\Support\Tenancy\TenantContext::class)->clinic();
        $clinic?->update(['name' => $name]);
        $setting->forceFill(['clinic_name' => $name]);

        \App\Models\AuditTrail::record('clinic.renamed', "Clinic renamed from \"{$old}\" to \"{$name}\"",
            $clinic, ['name' => $old], ['name' => $name], null, true);
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
            'prefixLocked' => (bool) $this->clinic()?->patientPrefixLocked(),
            'nextPatientNumber' => strtoupper($this->patient_prefix ?: 'PX') . '-' . now()->format('ym') . '-001',
        ])->layout('layouts.admin.admin-layout');
    }
}
