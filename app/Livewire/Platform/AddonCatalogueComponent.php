<?php

namespace App\Livewire\Platform;

use App\Models\PlatformAddon;
use App\Services\PlatformAuditService;
use App\Support\PlanProduct;
use Livewire\Component;

/**
 * Platform → Plans: the add-on price list. Each extra feature has a monthly price and can be
 * offered to clinics, who then see it on their Subscription page and can ask for it.
 */
class AddonCatalogueComponent extends Component
{
    /** feature => ['price' => string, 'offered' => bool] as edited. */
    public array $rows = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $saved = PlatformAddon::all()->keyBy('feature');
        foreach (PlanProduct::EXTRAS as $feature => $extra) {
            $this->rows[$feature] = [
                'price' => number_format((float) ($saved[$feature]->monthly_price ?? 0), 2, '.', ''),
                'offered' => (bool) ($saved[$feature]->is_offered ?? false),
            ];
        }
    }

    public function save(string $feature, PlatformAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        abort_unless(isset(PlanProduct::EXTRAS[$feature]), 422);
        $this->validate(["rows.$feature.price" => 'required|numeric|min:0|max:100000', "rows.$feature.offered" => 'boolean'],
            ["rows.$feature.price.*" => 'Enter a price of 0 or more.']);

        $addon = PlatformAddon::firstOrNew(['feature' => $feature]);
        $old = $addon->exists ? $addon->only(['monthly_price', 'is_offered']) : [];
        $addon->fill(['monthly_price' => round((float) $this->rows[$feature]['price'], 2), 'is_offered' => (bool) $this->rows[$feature]['offered']])->save();
        $audit->record('ADDON_PRICE_SAVED', null, $old, ['feature' => $feature] + $addon->only(['monthly_price', 'is_offered']));
        $this->dispatch('notify', type: 'success', message: PlanProduct::EXTRAS[$feature][0] . ' saved. New prices apply to add-ons added from now on.');
    }

    public function render()
    {
        return view('livewire.platform.addon-catalogue-component', ['extras' => PlanProduct::EXTRAS]);
    }
}
