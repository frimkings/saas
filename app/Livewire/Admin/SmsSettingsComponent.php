<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Services\ClinicAccessService;
use App\Services\LicenseService;
use App\Services\SmsService;
use App\Models\{PlatformInvoice, SmsBundle, SmsCreditTransaction};
use App\Services\Messaging\SmsCreditService;
use App\Support\Tenancy\TenantContext;
use App\Support\Feature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;

class SmsSettingsComponent extends Component
{
    public string $smsApiUrl    = '';
    public string $smsApiKey    = '';   // never pre-filled; blank = keep existing
    public string $smsSenderId  = '';
    public string $testPhone    = '';
    public bool   $smsEnabled   = true;
    public ?array $balanceResult = null;

    // Hosted clinics send through the platform gateway and only request a sender ID.
    public bool    $platformManaged     = false;
    public string  $senderIdRequest     = '';
    public string  $senderIdStatus      = 'none';
    public ?string $senderIdNote        = null;
    public ?string $approvedSenderId    = null;

    public bool $spectacleRenewalEnabled      = true;
    public int  $spectacleRenewalReminderDays = 30;

    protected $rules = [
        'smsApiUrl'   => 'required|url|max:500',
        'smsApiKey'   => 'nullable|string|max:500',
        'smsSenderId' => 'nullable|string|max:50',
        'testPhone'   => 'nullable|string|max:20',
    ];

    protected $messages = [
        'smsApiUrl.required' => 'API endpoint URL is required.',
        'smsApiUrl.url'      => 'Please enter a valid URL.',
    ];

    public function mount(): void
    {
        abort_if(!LicenseService::has(Feature::SMS_CAMPAIGNS), 403, 'SMS campaigns require a Pro license.');
        if (!Auth::user()->hasRole('Super Admin')) {
            return;
        }

        $s = Setting::getSettings();
        $this->platformManaged              = app(ClinicAccessService::class)->hosted();
        $this->senderIdStatus               = $s->sms_sender_id_status ?? 'none';
        $this->senderIdRequest              = $s->sms_sender_id_requested ?? '';
        $this->senderIdNote                 = $s->sms_sender_id_note;
        $this->approvedSenderId             = $this->platformManaged ? $s->sms_sender_id : null;
        $this->smsApiUrl                    = $s->sms_api_url   ?? '';
        $this->smsSenderId                  = $s->sms_sender_id ?? '';
        $this->smsEnabled                   = (bool) ($s->sms_enabled ?? true);
        $this->spectacleRenewalEnabled      = (bool) ($s->spectacle_renewal_enabled ?? true);
        $this->spectacleRenewalReminderDays = (int) ($s->spectacle_renewal_reminder_days ?? 30);
        $this->testPhone                    = '';
        // API key intentionally blank — user must re-enter to change
    }

    public function save(): void
    {
        // The platform owns gateway credentials and sender IDs for hosted clinics.
        abort_if(app(ClinicAccessService::class)->hosted(), 403, 'SMS gateway settings are managed by the platform.');

        $this->validate([
            'smsApiUrl'   => 'required|url|max:500',
            'smsSenderId' => 'nullable|string|max:50',
        ]);

        $data = [
            'sms_api_url'   => trim($this->smsApiUrl),
            'sms_sender_id' => trim($this->smsSenderId) ?: null,
        ];

        if (!empty(trim($this->smsApiKey))) {
            $data['sms_api_key'] = Crypt::encryptString(trim($this->smsApiKey));
        }

        Setting::getSettings()->update($data);

        $this->smsApiKey = '';

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'SMS settings saved.',
        ]);
    }

    public function requestSenderId(): void
    {
        abort_unless(app(ClinicAccessService::class)->hosted(), 403);
        // Kept exactly as typed: SMS networks register sender IDs with their capitals (VisionSpace ≠ VISIONSPACE).
        $this->senderIdRequest = trim($this->senderIdRequest);
        $this->validate(['senderIdRequest' => ['required', 'regex:/^(?=.*[A-Za-z])[A-Za-z0-9 ]{3,11}$/']], [
            'senderIdRequest.regex' => 'Use 3–11 letters, digits or spaces, including at least one letter.',
        ]);

        Setting::getSettings()->update([
            'sms_sender_id_requested'    => $this->senderIdRequest,
            'sms_sender_id_status'       => 'pending',
            'sms_sender_id_note'         => null,
            'sms_sender_id_requested_at' => now(),
        ]);

        $this->senderIdStatus = 'pending';
        $this->senderIdNote   = null;
        app(\App\Services\PlatformRequestAlerts::class)->senderIdRequested(app(TenantContext::class)->requireClinic(), $this->senderIdRequest);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Sender ID submitted for approval. Messages use the platform sender until it is approved.',
        ]);
    }

    public function toggleSms(): void
    {
        $this->smsEnabled = !$this->smsEnabled;
        Setting::getSettings()->update(['sms_enabled' => $this->smsEnabled]);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => $this->smsEnabled ? 'SMS notifications resumed.' : 'SMS notifications paused.',
        ]);
    }

    public function saveRenewalSettings(): void
    {
        $this->validate([
            'spectacleRenewalReminderDays' => 'required|integer|min:1|max:90',
        ]);

        Setting::getSettings()->update([
            'spectacle_renewal_enabled'       => $this->spectacleRenewalEnabled,
            'spectacle_renewal_reminder_days' => $this->spectacleRenewalReminderDays,
        ]);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Spectacle renewal settings saved.',
        ]);
    }

    public function sendTest(): void
    {
        $this->validateOnly('testPhone', ['testPhone' => 'required|string|max:20']);

        $s = Setting::getSettings();
        $clinicName = $s->clinic_name ?? 'The Clinic';

        $result = (new SmsService)->sendNow(
            $this->testPhone,
            "Test SMS from {$clinicName}. Your SMS settings are working correctly."
        );

        if ($result['success']) {
            $this->dispatch('notify', ...[
                'type'    => 'success',
                'message' => 'Test SMS sent successfully.',
            ]);
        } else {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Test failed: ' . ($result['error'] ?? 'Unknown error'),
            ]);
        }
    }

    public function checkBalance(): void
    {
        $result = (new SmsService)->checkBalance();
        $this->balanceResult = $result;

        if (!$result['success']) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Balance check failed: ' . ($result['error'] ?? 'Unknown error'),
            ]);
        }
    }

    public function buyBundle(int $bundleId): void
    {
        abort_unless(app(ClinicAccessService::class)->hosted() && Auth::user()->hasRole('Super Admin'), 403);
        $invoice = app(SmsCreditService::class)->requestBundle(
            app(TenantContext::class)->requireClinic(),
            SmsBundle::active()->findOrFail($bundleId)
        );

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => "Invoice {$invoice->number} issued. Credits are added as soon as payment is confirmed.",
        ]);
    }

    public function cancelBundleRequest(int $invoiceId): void
    {
        abort_unless(app(ClinicAccessService::class)->hosted() && Auth::user()->hasRole('Super Admin'), 403);
        $clinicId = app(TenantContext::class)->requireClinic()->id;
        app(SmsCreditService::class)->cancelRequest(PlatformInvoice::where('clinic_id', $clinicId)->findOrFail($invoiceId), $clinicId);

        $this->dispatch('notify', ...['type' => 'info', 'message' => 'SMS credit request cancelled.']);
    }

    public function render()
    {
        $credits = null;
        if ($this->platformManaged && ($clinic = app(TenantContext::class)->clinic())) {
            $credits = [
                'balance' => app(SmsCreditService::class)->balance($clinic->id),
                'bundles' => SmsBundle::active()->get(),
                'pending' => PlatformInvoice::where('clinic_id', $clinic->id)->where('source', 'sms_bundle')
                    ->whereIn('status', ['unpaid', 'partial'])->latest()->get(),
                'history' => SmsCreditTransaction::where('clinic_id', $clinic->id)->where('type', '!=', 'usage')
                    ->where('type', '!=', 'refund')->latest('id')->limit(8)->get(),
            ];
        }

        return view('livewire.admin.sms-settings-component', ['smsCredits' => $credits])
            ->layout('layouts.admin.admin-layout');
    }
}
