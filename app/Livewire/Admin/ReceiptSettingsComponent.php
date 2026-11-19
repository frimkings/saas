<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Services\Visits\VisitReceiptSms;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Settings → Receipts & Payments: one receipt per visit, the clinic's closing time (when
 * the visit SMS go out) and, beside it, the clinic's payment methods.
 */
class ReceiptSettingsComponent extends Component
{
    public bool $visit_receipts_enabled = false;
    public string $closing_time = VisitReceiptSms::DEFAULT_CLOSING_TIME;
    /** Spectacle and drug prescription slips: A5, or two copies on one A4 sheet. */
    public string $prescription_paper = \App\Support\Clinical\Slip::A5;

    public function mount(): void
    {
        abort_unless(\App\Support\Access\Access::can('settings.manage'), 403);
        $this->loadFrom(Setting::getSettings());
    }

    public function save(): void
    {
        abort_unless(\App\Support\Access\Access::can('settings.manage'), 403);
        $this->validate([
            'visit_receipts_enabled' => 'boolean',
            'closing_time'           => 'required|date_format:H:i',
            'prescription_paper'     => 'required|in:'.implode(',', array_keys(\App\Support\Clinical\Slip::PAPERS)),
        ], [], ['closing_time' => 'closing time', 'prescription_paper' => 'prescription paper']);

        $setting = Setting::getSettings();
        $setting->update([
            'visit_receipts_enabled' => $this->visit_receipts_enabled,
            'closing_time'           => VisitReceiptSms::normalizeClosingTime($this->closing_time),
            'prescription_paper'     => $this->prescription_paper,
        ]);
        $this->loadFrom($setting->fresh());

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Receipt settings saved.']);
    }

    private function loadFrom(Setting $setting): void
    {
        $this->visit_receipts_enabled = (bool) $setting->visit_receipts_enabled;
        $this->closing_time = VisitReceiptSms::normalizeClosingTime($setting->closing_time);
        $this->prescription_paper = \App\Support\Clinical\Slip::paper($setting);
    }

    public function render()
    {
        return view('livewire.admin.receipt-settings-component');
    }
}
