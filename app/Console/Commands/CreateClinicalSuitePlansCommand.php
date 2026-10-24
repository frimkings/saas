<?php

namespace App\Console\Commands;

use App\Models\PlatformAddon;
use App\Models\SubscriptionPlan;
use App\Services\PlanVersioningService;
use App\Services\PlatformAuditService;
use App\Services\PricingCatalogue;
use App\Support\Feature;
use App\Support\PlanProduct;
use App\Support\Pricing\ClinicalSuitePlans;
use Illuminate\Console\Command;

/**
 * Puts the Clinical Suite plans on the pricing page and prices their extras as add-ons. Safe to run
 * again: a plan already matching its definition is left alone, one that differs gets a new version
 * (clinics on the old version keep it), and plans no longer sold leave the page but stay for their
 * clinics. Add-ons already priced are never changed.
 */
class CreateClinicalSuitePlansCommand extends Command
{
    protected $signature = 'pricing:clinical-suite';
    protected $description = 'Create or update the Clinical Suite plans, show them on the pricing page and price their extras as add-ons.';

    public function handle(PlanVersioningService $versions, PlatformAuditService $audit): int
    {
        $settings = PricingCatalogue::settings();
        $order = 0;
        foreach (ClinicalSuitePlans::plans() as $family => $plan) {
            $order++;
            $attributes = [
                'name' => $plan['name'], 'code' => $family, 'product' => PlanProduct::CLINIC, 'features' => ClinicalSuitePlans::features($plan),
                'included_branches' => 1, 'included_users' => $plan['users'], 'storage_limit_mb' => $plan['storage_mb'],
                'base_price' => $plan['price'], 'annual_price' => $plan['price'] * 12, 'additional_branch_price' => $plan['price'],
                'currency' => 'GHS', 'billing_interval' => 'monthly', 'tax_rate' => 0, 'trial_days' => 14, 'grace_days' => 0, 'is_active' => true,
            ];
            $current = SubscriptionPlan::where('family_code', $family)->where('is_active', true)->orderByDesc('version')->first();
            if (! $current && SubscriptionPlan::where('code', $family)->exists()) {
                $this->line("{$plan['name']}: a plan with code {$family} exists but none of its versions is active; left as it is.");
            } elseif (! $current) {
                $created = $versions->save(null, $attributes);
                $audit->record('PLAN_CREATED', null, [], $created->toArray());
                $this->info("{$plan['name']}: created at GHS {$plan['price']} a month.");
            } elseif ($this->matches($current, $attributes)) {
                $this->line("{$plan['name']}: already up to date.");
            } else {
                $updated = $versions->save($current, $attributes);
                $audit->record('PLAN_VERSION_CREATED', null, $current->toArray(), $updated->toArray());
                $this->info("{$plan['name']}: now version {$updated->version} at GHS {$plan['price']} a month (clinics on version {$current->version} keep it).");
            }
            $settings['plans'][$family] = ['order' => $order, 'max_visits' => $plan['max_visits'], 'tagline' => $plan['tagline'], 'featured' => $plan['featured'] ?? false];
        }

        foreach (ClinicalSuitePlans::RETIRED as $family) {
            unset($settings['plans'][$family]);
            $retired = SubscriptionPlan::where('family_code', $family)->where('is_active', true)->update(['is_active' => false, 'retired_at' => now()]);
            if ($retired) $this->line("{$family}: no longer sold; clinics on it keep it.");
        }
        PricingCatalogue::saveSettings($settings);

        foreach (ClinicalSuitePlans::EXTRA_PRICES as $feature => $price) {
            $addon = PlatformAddon::firstOrNew(['feature' => $feature]);
            $name = PlanProduct::EXTRAS[$feature][0];
            if ($addon->exists && (float) $addon->monthly_price > 0) {
                $this->line("{$name}: already priced at {$addon->monthly_price}, left as it is.");
                continue;
            }
            $addon->fill(['monthly_price' => $price, 'is_offered' => true, 'currency' => $addon->currency ?: 'GHS']
                + ($feature === Feature::SCAN_STORAGE_PACK ? ['pack_size' => PlanProduct::STORAGE_PACK_GB] : []))->save();
            $this->info("{$name}: GHS {$price} a month.");
        }

        $this->newLine();
        $this->line('Done. Review them under Platform → Subscription Plans and Platform → Pricing Page.');

        return self::SUCCESS;
    }

    /** Whether a plan already has what its definition says (name, price, limits and features). */
    private function matches(SubscriptionPlan $plan, array $attributes): bool
    {
        $features = fn ($list) => collect($list ?? [])->sort()->values()->all();

        return $plan->name === $attributes['name'] && (float) $plan->base_price === (float) $attributes['base_price']
            && (float) $plan->annual_price === (float) $attributes['annual_price'] && (float) $plan->additional_branch_price === (float) $attributes['additional_branch_price']
            && $plan->included_users === $attributes['included_users'] && $plan->storage_limit_mb === $attributes['storage_limit_mb']
            && $features($plan->features) === $features($attributes['features']);
    }
}
