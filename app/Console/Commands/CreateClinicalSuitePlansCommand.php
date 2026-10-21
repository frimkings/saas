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
 * Creates the five Clinical Suite plans (Starter 100 → Pro 320), puts them on the public pricing
 * page, and prices the extras for buying one at a time. Safe to run again: a plan family or an
 * add-on price that already exists is left as the platform set it.
 */
class CreateClinicalSuitePlansCommand extends Command
{
    protected $signature = 'pricing:clinical-suite';
    protected $description = 'Create the five Clinical Suite plans, show them on the pricing page and price their extras as add-ons.';

    public function handle(PlanVersioningService $versions, PlatformAuditService $audit): int
    {
        $settings = PricingCatalogue::settings();
        $order = 0;
        foreach (ClinicalSuitePlans::plans() as $family => $plan) {
            $order++;
            if (SubscriptionPlan::where('family_code', $family)->orWhere('code', $family)->exists()) {
                $this->line("{$plan['name']}: already exists, left as it is.");
            } else {
                $created = $versions->save(null, [
                    'name' => $plan['name'], 'code' => $family, 'product' => PlanProduct::CLINIC, 'features' => ClinicalSuitePlans::features($plan),
                    'included_branches' => 1, 'included_users' => $plan['users'], 'storage_limit_mb' => $plan['storage_mb'],
                    'base_price' => $plan['price'], 'annual_price' => $plan['price'] * 10, 'additional_branch_price' => $plan['price'],
                    'currency' => 'GHS', 'billing_interval' => 'monthly', 'tax_rate' => 0, 'trial_days' => 14, 'grace_days' => 0, 'is_active' => true,
                ]);
                $audit->record('PLAN_CREATED', null, [], $created->toArray());
                $this->info("{$plan['name']}: created at GHS {$plan['price']} a month.");
            }
            $settings['plans'][$family] ??= ['order' => $order, 'max_visits' => $plan['max_visits'], 'tagline' => $plan['tagline'], 'featured' => $plan['featured'] ?? false];
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
}
