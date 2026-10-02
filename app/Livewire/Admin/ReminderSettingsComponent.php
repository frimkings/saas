<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Services\Reminders\AttentionItems;
use Livewire\Component;

/**
 * Settings → Reminders (and Optical Settings): when "Needs attention" flags appointments and
 * spectacles, and when uncollected glasses reach the owner's morning email.
 */
class ReminderSettingsComponent extends Component
{
    public bool $optical = false;
    public int $appointment_hours = 2;
    public int $due_days = 1;
    public int $uncollected_days = 3;
    public int $owner_uncollected_days = 14;

    public function mount(bool $optical = false): void
    {
        $this->optical = $optical;
        $this->authorizeAccess();
        $this->load();
    }

    public function save(AttentionItems $items): void
    {
        $this->authorizeAccess();
        $data = $this->validate([
            'appointment_hours'      => 'required|integer|min:1|max:24',
            'due_days'               => 'required|integer|min:0|max:14',
            'uncollected_days'       => 'required|integer|min:1|max:90',
            'owner_uncollected_days' => 'required|integer|min:1|max:365',
        ], [], [
            'appointment_hours' => 'hours ahead', 'due_days' => 'days ahead',
            'uncollected_days' => 'days waiting', 'owner_uncollected_days' => 'days for the owner',
        ]);

        Setting::getSettings()->update([
            'reminder_appointment_hours'      => $data['appointment_hours'],
            'reminder_due_days'               => $data['due_days'],
            'reminder_uncollected_days'       => $data['uncollected_days'],
            'reminder_owner_uncollected_days' => $data['owner_uncollected_days'],
        ]);
        $items->forgetCounts();
        $this->load();

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Reminder settings saved.']);
    }

    private function load(): void
    {
        $t = AttentionItems::thresholds();
        $this->appointment_hours = $t['appointment_hours'];
        $this->due_days = $t['due_days'];
        $this->uncollected_days = $t['uncollected_days'];
        $this->owner_uncollected_days = $t['owner_uncollected_days'];
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless($user?->hasRole('Super Admin') || ($this->optical && $user?->hasRole('Manager')), 403);
    }

    public function render()
    {
        return view('livewire.admin.reminder-settings-component');
    }
}
