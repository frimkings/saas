<?php

namespace App\Livewire\Platform;

use App\Models\Clinic;
use App\Services\AttendanceSizing;
use App\Services\ClinicAddonService;
use App\Support\Pricing\AttendanceSuitePrices;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Platform → clinic panel → Plan: the clinic's attendance priced by staff size. Pick a package and
 * the number of staff; the price comes from the attendance price list unless one is typed (it must
 * be, from special pricing). Starting charges the rest of the period; changes work at once and are
 * charged from the next renewal; stopping keeps it to the end of the paid period.
 */
class ClinicAttendanceSizeComponent extends Component
{
    #[Locked]
    public int $clinicId;

    public string $package = 'basic';
    public string $staff = '';
    public string $price = '';
    public string $reason = '';

    public function mount(int $clinicId, AttendanceSizing $sizing): void
    {
        $this->authorizePlatform();
        $this->clinicId = $clinicId;
        if ($size = $sizing->current($clinicId)) {
            $this->package = $size->package;
            $this->staff = (string) $size->staff_limit;
        }
    }

    public function save(AttendanceSizing $sizing): void
    {
        $this->authorizePlatform();
        $this->resetErrorBag();
        $staff = (int) $this->staff;
        $price = trim($this->price) === '' ? null : (is_numeric($this->price) ? round((float) $this->price, 2) : -1.0);
        if ($staff < 1) { $this->addError('size', 'Enter a number of staff.'); return; }
        if ($price !== null && $price < 0) { $this->addError('size', 'Enter a price of 0 or more, or leave it empty for the list price.'); return; }
        $had = $sizing->current($this->clinicId);
        try {
            $size = $sizing->set($this->clinic(), $this->package, $staff, $price, trim($this->reason) ?: null, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('size', collect($e->errors())->flatten()->first());
            return;
        }
        $this->price = $this->reason = '';
        $this->staff = (string) $size->staff_limit;
        $this->dispatch('notify', type: 'success', message: $had
            ? "Attendance is now {$size->name()}. The new staff limit works now; the price follows from the next renewal."
            : "Attendance started: {$size->name()}, {$size->currency} " . number_format((float) $size->monthly_price, 2) . ' a month.');
    }

    public function reprice(AttendanceSizing $sizing): void
    {
        $this->authorizePlatform();
        $this->resetErrorBag();
        $size = $sizing->current($this->clinicId);
        if (! $size) return;
        try {
            $size = $sizing->reprice($size, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('size', collect($e->errors())->flatten()->first());
            return;
        }
        $this->dispatch('notify', type: 'success', message: "Repriced to the list: {$size->currency} " . number_format((float) $size->monthly_price, 2) . ' a month from the next renewal.');
    }

    public function stop(AttendanceSizing $sizing): void
    {
        $this->authorizePlatform();
        $size = $sizing->current($this->clinicId);
        if (! $size || $size->ends_at) return;
        $size = $sizing->stop($size, auth()->user(), trim($this->reason) ?: null);
        $this->reason = '';
        $this->dispatch('notify', type: 'success', message: 'Attendance stops ' . ($size->ends_at->isFuture() ? 'on ' . $size->ends_at->format('j M Y') . ', the end of the paid period.' : 'now.'));
    }

    public function render(AttendanceSizing $sizing, ClinicAddonService $addons)
    {
        $staff = max(1, (int) $this->staff ?: 1);
        $list = isset(AttendanceSuitePrices::PACKAGES[$this->package]) ? $sizing->listPrice($this->package, $staff) : null;
        $typed = is_numeric($this->price) ? (float) $this->price : null;
        $current = $sizing->current($this->clinicId);
        $monthly = $typed ?? ($list && ! $list['special'] ? (float) $list['monthly'] : null);

        return view('livewire.platform.clinic-attendance-size-component', [
            'current' => $current,
            'list' => $list,
            'packages' => AttendanceSuitePrices::packages(),
            'specialFrom' => AttendanceSuitePrices::specialFrom(),
            'currentList' => $current ? $sizing->listPrice($current->package, $current->staff_limit) : null,
            'monthly' => $monthly,
            'charge' => ! $current && $monthly !== null ? $addons->prorata($this->clinic(), $monthly) : null,
            'oldPacks' => $addons->extraStaff($this->clinicId),
        ]);
    }

    private function clinic(): Clinic
    {
        return Clinic::findOrFail($this->clinicId);
    }

    private function authorizePlatform(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
    }
}
