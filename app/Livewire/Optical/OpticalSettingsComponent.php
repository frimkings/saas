<?php

namespace App\Livewire\Optical;

use App\Models\OpticalSetting;
use App\Services\ClinicAccessService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class OpticalSettingsComponent extends Component
{
    public $min_deposit_percentage = 0;
    public $warranty_months = 6;
    public $optical_disclaimer = 'Custom spectacle orders are non-refundable once lab glazing has commenced.';
    public bool $ready_sms_auto = true;
    /** Days after "ready" to send pickup reminders, e.g. "3, 10, 30". Blank = off. */
    public string $reminder_schedule = '7, 14, 21';
    public $stuck_job_days = 7;
    public $quote_validity_days = 30;
    public $pos_max_discount_percent = 10;

    public function mount(): void
    {
        $settings = OpticalSetting::first();
        if ($settings) {
            $this->min_deposit_percentage = $settings->min_deposit_percentage;
            $this->warranty_months = $settings->warranty_months;
            $this->optical_disclaimer = $settings->optical_disclaimer;
            $this->ready_sms_auto = (bool) $settings->ready_sms_auto;
            $this->reminder_schedule = implode(', ', $settings->reminderSchedule());
            $this->stuck_job_days = $settings->stuck_job_days ?? 7;
            $this->quote_validity_days = $settings->quote_validity_days ?? 30;
            $this->pos_max_discount_percent = $settings->pos_max_discount_percent ?? 10;
        }
    }

    public function saveSettings()
    {
        $this->validate([
            'min_deposit_percentage' => 'required|integer|between:0,100',
            'warranty_months' => 'required|integer|between:0,60',
            'optical_disclaimer' => 'nullable|string|max:2000',
            'ready_sms_auto' => 'boolean',
            'reminder_schedule' => 'nullable|string|max:100',
            'stuck_job_days' => 'required|integer|between:1,365',
            'quote_validity_days' => 'required|integer|between:1,365',
            'pos_max_discount_percent' => 'required|integer|between:0,100',
        ]);
        $schedule = $this->parseSchedule($this->reminder_schedule);
        app(ClinicAccessService::class)->assertWritable('optical');
        $values = [
            'min_deposit_percentage' => $this->min_deposit_percentage,
            'warranty_months' => $this->warranty_months,
            'optical_disclaimer' => $this->optical_disclaimer,
            'ready_sms_auto' => $this->ready_sms_auto,
            'collection_reminder_schedule' => $schedule,
            'stuck_job_days' => (int) $this->stuck_job_days,
            'quote_validity_days' => (int) $this->quote_validity_days,
            'pos_max_discount_percent' => (int) $this->pos_max_discount_percent,
        ];
        $settings = OpticalSetting::firstOrNew();
        $settings->fill($values);
        \App\Models\AuditTrail::recordSave($settings, 'optical.settings', 'optical settings');
        $this->reminder_schedule = implode(', ', $schedule);
        session()->flash('success', 'Optical module settings saved successfully.');
    }

    /** "3, 10, 30" → [3, 10, 30]: up to 5 different whole days from 1 to 365. */
    private function parseSchedule(string $input): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $input) ?: []), fn ($part) => $part !== ''));
        $fail = fn (string $message) => throw ValidationException::withMessages(['reminder_schedule' => $message]);
        if (count($parts) > 5) $fail('Enter at most 5 reminder days.');
        $days = [];
        foreach ($parts as $part) {
            if (! ctype_digit($part) || (int) $part < 1 || (int) $part > 365) $fail('Reminder days must be whole numbers from 1 to 365, e.g. 3, 10, 30.');
            $days[] = (int) $part;
        }
        if (count(array_unique($days)) !== count($days)) $fail('Each reminder day can only be listed once.');
        sort($days);
        return $days;
    }

    public function render()
    {
        return view('livewire.optical.optical-settings-component')->layout('layouts.optical');
    }
}
