<?php

namespace App\Livewire\Admin;

use App\Models\ReportDelivery;
use App\Models\Setting;
use App\Services\LicenseService;
use App\Support\Feature;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

class ReportDeliveryComponent extends Component
{
    public bool $enabled = false;
    public string $frequency = 'daily';
    public int $day = 1;
    public string $time = '08:00';
    public array $recipients = [];
    public string $newRecipient = '';

    protected $rules = [
        'enabled' => 'boolean',
        'frequency' => 'required|in:daily,weekly',
        'day' => 'required|integer|between:0,6',
        'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
        'newRecipient' => 'nullable|email|max:255',
    ];

    protected $messages = [
        'time.regex' => 'Time must be in HH:MM format.',
        'newRecipient.email' => 'Enter a valid email address.',
    ];

    public function mount(): void
    {
        abort_if(!LicenseService::has(Feature::REPORT_DELIVERY), 403, 'Report delivery requires a Pro license.');

        $settings = Setting::getSettings();

        $this->enabled = (bool) ($settings->report_enabled ?? false);
        $this->frequency = $settings->report_frequency ?? 'daily';
        $this->day = (int) ($settings->report_day ?? 1);
        $this->time = $settings->report_time ?? '08:00';
        $this->recipients = (array) ($settings->report_recipients ?? []);
    }

    public function save(): void
    {
        $this->validate([
            'enabled' => 'boolean',
            'frequency' => 'required|in:daily,weekly',
            'day' => 'required|integer|between:0,6',
            'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        Setting::getSettings()->update([
            'report_enabled' => $this->enabled,
            'report_frequency' => $this->frequency,
            'report_day' => $this->day,
            'report_time' => $this->time,
        ]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Report schedule saved.']);
    }

    public function addRecipient(): void
    {
        $this->validateOnly('newRecipient', [
            'newRecipient' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($this->newRecipient));

        if (in_array($email, $this->recipients, true)) {
            $this->addError('newRecipient', 'This email is already in the list.');
            return;
        }

        $this->recipients[] = $email;
        $this->persistRecipients();
        $this->newRecipient = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Recipient added.']);
    }

    public function removeRecipient(int $index): void
    {
        unset($this->recipients[$index]);
        $this->recipients = array_values($this->recipients);
        $this->persistRecipients();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Recipient removed.']);
    }

    public function sendNow(): void
    {
        if (empty($this->recipients)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Add at least one recipient before sending a report.']);
            return;
        }

        try {
            Artisan::call('report:send-financial', [
                '--force' => true,
                '--today' => true,
                '--period' => $this->frequency,
            ]);

            $label = $this->frequency === 'weekly' ? 'this week\'s' : 'today\'s';
            $this->dispatch('notify', ...['type' => 'success', 'message' => "Current report queued with {$label} data. It will retry automatically if email is offline."]);
        } catch (\Throwable $e) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Send failed: ' . $e->getMessage()]);
        }
    }

    public function retryPending(): void
    {
        try {
            Artisan::call('report:retry-financial');
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Pending report deliveries checked.']);
        } catch (\Throwable $e) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Retry failed: ' . $e->getMessage()]);
        }
    }

    private function persistRecipients(): void
    {
        Setting::getSettings()->update([
            'report_recipients' => !empty($this->recipients) ? $this->recipients : null,
        ]);
    }

    public function render()
    {
        return view('livewire.admin.report-delivery-component', [
            'recentDeliveries' => ReportDelivery::latest()->limit(6)->get(),
            'pendingDeliveriesCount' => ReportDelivery::whereIn('status', [
                ReportDelivery::STATUS_PENDING,
                ReportDelivery::STATUS_FAILED,
            ])->count(),
        ])->layout('layouts.admin.admin-layout');
    }
}
