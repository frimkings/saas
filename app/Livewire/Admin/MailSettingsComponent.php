<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class MailSettingsComponent extends Component
{
    public string $smtpHost       = '';
    public int    $smtpPort       = 587;
    public string $smtpUsername   = '';
    public string $smtpPassword   = '';  // never pre-filled; blank = keep existing
    public string $smtpEncryption = 'tls';
    public string $fromAddress    = '';
    public string $fromName       = '';
    public string $testRecipient  = '';
    public bool   $showGmailGuide = false;

    protected $rules = [
        'smtpHost'       => 'required|string|max:255',
        'smtpPort'       => 'required|integer|between:1,65535',
        'smtpUsername'   => 'required|string|max:255',
        'smtpPassword'   => 'nullable|string|max:500',
        'smtpEncryption' => 'required|in:tls,ssl,none',
        'fromAddress'    => 'required|email|max:255',
        'fromName'       => 'required|string|max:255',
        'testRecipient'  => 'nullable|email|max:255',
    ];

    protected $messages = [
        'smtpHost.required'    => 'SMTP host is required.',
        'smtpUsername.required'=> 'Username / email is required.',
        'fromAddress.required' => 'From address is required.',
        'fromAddress.email'    => 'From address must be a valid email.',
        'fromName.required'    => 'From name is required.',
    ];

    public function mount(): void
    {
        $s = Setting::getSettings();

        $this->smtpHost       = $s->smtp_host         ?? '';
        $this->smtpPort       = (int) ($s->smtp_port  ?? 587);
        $this->smtpUsername   = $s->smtp_username      ?? '';
        $this->smtpEncryption = $s->smtp_encryption    ?? 'tls';
        $this->fromAddress    = $s->smtp_from_address  ?? ($s->clinic_email ?? '');
        $this->fromName       = $s->smtp_from_name     ?? ($s->clinic_name  ?? '');
        $this->testRecipient  = auth()->user()->email  ?? '';
        // password intentionally blank — user must re-enter to change
    }

    public function applyPreset(string $provider): void
    {
        match ($provider) {
            'gmail'   => [$this->smtpHost, $this->smtpPort, $this->smtpEncryption] = ['smtp.gmail.com',      587, 'tls'],
            'outlook' => [$this->smtpHost, $this->smtpPort, $this->smtpEncryption] = ['smtp.office365.com',  587, 'tls'],
            'yahoo'   => [$this->smtpHost, $this->smtpPort, $this->smtpEncryption] = ['smtp.mail.yahoo.com', 587, 'tls'],
            default   => null,
        };

        if ($provider === 'gmail') {
            $this->showGmailGuide = true;
        }
    }

    public function save(): void
    {
        $this->validate([
            'smtpHost'       => 'required|string|max:255',
            'smtpPort'       => 'required|integer|between:1,65535',
            'smtpUsername'   => 'required|string|max:255',
            'smtpEncryption' => 'required|in:tls,ssl,none',
            'fromAddress'    => 'required|email|max:255',
            'fromName'       => 'required|string|max:255',
        ]);

        $data = [
            'smtp_host'         => trim($this->smtpHost),
            'smtp_port'         => $this->smtpPort,
            'smtp_username'     => trim($this->smtpUsername),
            'smtp_encryption'   => $this->smtpEncryption === 'none' ? null : $this->smtpEncryption,
            'smtp_from_address' => trim($this->fromAddress),
            'smtp_from_name'    => trim($this->fromName),
        ];

        if (!empty($this->smtpPassword)) {
            $data['smtp_password'] = Crypt::encrypt(trim($this->smtpPassword));
        }

        Setting::getSettings()->update($data);
        $this->smtpPassword = '';

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Mail settings saved. Use Send Test to verify.']);
    }

    public function sendTest(): void
    {
        $this->validateOnly('testRecipient', ['testRecipient' => 'required|email|max:255']);

        if (empty($this->smtpHost) || empty($this->smtpUsername)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Fill in and save SMTP settings before sending a test.']);
            return;
        }

        // Resolve password: new entry takes priority, then saved encrypted value
        $password = trim($this->smtpPassword);
        if ($password === '') {
            $password = $this->getSavedPassword();
        }

        // Apply settings to config for this request, then flush the resolved
        // SMTP transport so it rebuilds with the new credentials.
        config([
            'mail.default'                 => 'smtp',
            'mail.mailers.smtp.host'       => $this->smtpHost,
            'mail.mailers.smtp.port'       => $this->smtpPort,
            'mail.mailers.smtp.username'   => $this->smtpUsername,
            'mail.mailers.smtp.password'   => $password,
            'mail.mailers.smtp.encryption' => $this->smtpEncryption === 'none' ? null : $this->smtpEncryption,
            'mail.from.address'            => $this->fromAddress,
            'mail.from.name'               => $this->fromName,
        ]);
        app('mail.manager')->purge('smtp');

        try {
            $fromName = $this->fromName ?: 'Eye Clinic';
            $to       = trim($this->testRecipient);

            Mail::raw(
                "Hello,\n\nThis is a test email from {$fromName}.\n\n"
                . "Your mail configuration is working correctly.\n\n"
                . "SMTP host: {$this->smtpHost}\nPort: {$this->smtpPort}\nEncryption: {$this->smtpEncryption}\n\n"
                . "— {$fromName}",
                fn ($m) => $m->to($to)->subject("Mail Test — {$fromName}")
            );

            $this->dispatch('notify', ...['type' => 'success', 'message' => "Test email sent to {$to}. Check the inbox."]);
        } catch (\Throwable $e) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Send failed: ' . $e->getMessage()]);
        }
    }

    private function getSavedPassword(): string
    {
        try {
            $encrypted = Setting::getSettings()->smtp_password;
            return $encrypted ? Crypt::decrypt($encrypted) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function render()
    {
        return view('livewire.admin.mail-settings-component')
            ->layout('layouts.admin.admin-layout');
    }
}
