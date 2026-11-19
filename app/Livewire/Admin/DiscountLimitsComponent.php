<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Services\ClinicAccessService;
use App\Support\Access\Access;
use App\Support\Access\DiscountLimits;
use App\Support\Access\RoleNames;
use App\Support\OpticalMode;
use Livewire\Component;

/**
 * Settings → Discount limits (and Optical Settings): the Owner sets how much each role may take off
 * a sale at each till without asking for approval.
 */
class DiscountLimitsComponent extends Component
{
    public bool $optical = false;

    /** @var array<string, array<string, int|string>> role => line => percent */
    public array $limits = [];

    public function mount(bool $optical = false): void
    {
        $this->optical = $optical;
        $this->authorizeAccess();
        $this->limits = DiscountLimits::all();
    }

    public function save(): void
    {
        $this->authorizeAccess();
        $rules = [];
        foreach (DiscountLimits::ROLES as $role) {
            foreach (array_keys(DiscountLimits::LINES) as $line) {
                $rules["limits.{$role}.{$line}"] = 'required|integer|min:0|max:100';
            }
        }
        $this->validate($rules, [], collect($rules)->mapWithKeys(fn ($rule, $key) => [$key => 'limit'])->all());

        $before = DiscountLimits::all();
        DiscountLimits::save($this->limits);
        $this->limits = DiscountLimits::all();
        AuditTrail::record('settings.discount_limits', 'Discount limits changed', null, $before, $this->limits);
        $this->dispatch('notify', type: 'success', message: 'Discount limits saved.');
    }

    /** @return array<string, string> the tills this clinic's plan has */
    public function lines(): array
    {
        $access = app(ClinicAccessService::class);

        return array_filter(DiscountLimits::LINES, fn ($label, $line) => $line === DiscountLimits::OPTICAL
            ? $access->access('optical')['allowed']
            : ! OpticalMode::opticalOnly() && $access->access('clinical')['allowed'], ARRAY_FILTER_USE_BOTH) ?: DiscountLimits::LINES;
    }

    private function authorizeAccess(): void
    {
        abort_unless(Access::can('settings.manage'), 403);
    }

    public function render()
    {
        return view('livewire.admin.discount-limits-component', [
            'lines' => $this->lines(),
            'roles' => collect(DiscountLimits::ROLES)->mapWithKeys(fn ($role) => [$role => RoleNames::label($role)])->all(),
        ]);
    }
}
