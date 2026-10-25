<?php

namespace App\Services;

use App\Models\PlatformAddon;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Support\Feature;
use App\Support\PlanProduct;
use Illuminate\Support\Collection;

/**
 * The public pricing page: which plans it shows (by plan family, so a new version of a plan
 * replaces the old one there), the busiest clinic each is meant for, and the quote for a set of
 * ticked features. Prices always come from the plans and the add-on catalogue, never the page.
 *
 * A quote is the cheapest way to get the selection: a plan, plus extras the plan lacks bought as
 * add-ons (only those the platform offers), plus storage packs and extra branches. A plan whose
 * users are fewer than asked for, or that is meant for quieter clinics, doesn't count. The same
 * rules run in the page's script so prices move as boxes are ticked; the request form quotes again here.
 */
class PricingCatalogue
{
    public const SETTING = 'pricing_page';

    /** How the page groups the features, in order; anything unlisted goes under "More". */
    public const GROUPS = [
        'Front desk' => [Feature::APPOINTMENTS, Feature::NEEDS_ATTENTION, Feature::DISCOUNTS, Feature::OUTSTANDING_BALANCES, Feature::QUOTATIONS, Feature::SPECTACLE_ORDERS, Feature::SALES_EXPORT],
        'Clinical' => [Feature::REFERRALS, Feature::CLINICAL_DOCUMENTS, Feature::HISTORY_CHARTS, Feature::CLINICAL_PDFS],
        'Stock' => [Feature::INVENTORY, Feature::INVENTORY_ALERTS, Feature::PRODUCT_IMPORT, Feature::PURCHASING],
        'Money' => [Feature::SALES_REPORTS_MONTH, Feature::SALES_REPORTS, Feature::EXPENSE_TRACKING, Feature::CASH_BOOK, Feature::PATIENT_LEDGER, Feature::ADVANCED_REPORTS, Feature::INSURANCE, Feature::APPROVALS],
        'Messages' => [Feature::PATIENT_MESSAGES, Feature::SMS_CAMPAIGNS, Feature::STAFF_MESSAGES],
        'Owner' => [Feature::BUSINESS_OVERVIEW, Feature::DAILY_SUMMARY, Feature::WEEKLY_SUMMARY, Feature::MONTHLY_SUMMARY, Feature::MORNING_ALERTS, Feature::AUDIT_TRAIL, Feature::CUSTOM_ROLES],
        'Optical' => [Feature::SPECTACLES_PRO],
        'Support' => [Feature::PRIORITY_SUPPORT, Feature::GUIDED_ONBOARDING],
    ];

    /** Shorter names for the page than the platform's plan screen uses. */
    private const LABELS = [
        Feature::ADVANCED_REPORTS => 'Income statement',
        Feature::APPROVALS => 'Approvals (voids, discounts, refunds)', Feature::MORNING_ALERTS => 'Morning alerts email',
        Feature::SPECTACLES_PRO => 'Outstanding-lenses report', Feature::AUDIT_TRAIL => 'Audit trail & logins',
        Feature::DAILY_SUMMARY => 'Daily sales email', Feature::WEEKLY_SUMMARY => 'Weekly sales email', Feature::MONTHLY_SUMMARY => 'Monthly sales email',
        Feature::OUTSTANDING_BALANCES => 'Outstanding balances', Feature::SMS_CAMPAIGNS => 'Broadcast & patient recall',
        Feature::PATIENT_MESSAGES => 'SMS reminders & WhatsApp links', Feature::INVENTORY => 'Products & stock',
        Feature::SALES_REPORTS_MONTH => 'Sales reports (this month)', Feature::SALES_REPORTS => 'Sales reports (any dates, PDF)',
        Feature::INSURANCE => 'Insurance co-pay & claims', Feature::CLINICAL_PDFS => 'Visit summary & medical record PDFs',
        Feature::HISTORY_CHARTS => 'VA & IOP charts, risk flags', Feature::PURCHASING => 'Suppliers & purchase orders',
    ];

    /** ['plans' => [family => ['order' => int, 'max_visits' => ?int, 'tagline' => string, 'featured' => bool]]] */
    public static function settings(): array
    {
        $saved = json_decode((string) PlatformSetting::get(self::SETTING), true);

        return is_array($saved) ? $saved + ['plans' => []] : ['plans' => []];
    }

    public static function saveSettings(array $settings): void
    {
        PlatformSetting::put([self::SETTING => json_encode($settings)]);
    }

    /** The plans on the page, cheapest first, each as the page and the quote need it. */
    public function tiers(): Collection
    {
        $chosen = self::settings()['plans'];
        if (! $chosen) return collect();

        return SubscriptionPlan::query()->where('is_active', true)->whereIn('family_code', array_keys($chosen))->get()
            ->groupBy('family_code')->map(fn ($versions) => $versions->sortByDesc('version')->first())
            ->map(function (SubscriptionPlan $plan) use ($chosen) {
                $page = $chosen[$plan->family_code] ?? [];
                $extras = PlanProduct::extrasOf($plan->features);

                return [
                    'id' => $plan->id, 'family' => $plan->family_code, 'name' => $plan->name,
                    'tagline' => (string) ($page['tagline'] ?? ''), 'featured' => (bool) ($page['featured'] ?? false),
                    'order' => (int) ($page['order'] ?? 99), 'max_visits' => isset($page['max_visits']) ? (int) $page['max_visits'] : null,
                    'monthly' => (float) $plan->base_price, 'yearly' => (float) $plan->annual_price, 'currency' => $plan->currency ?: 'GHS',
                    'yearly_sms' => \App\Support\Pricing\ClinicalSuitePlans::YEARLY_SMS[$plan->family_code] ?? 0,
                    'users' => $plan->included_users, 'storage_mb' => $plan->storage_limit_mb,
                    'branches' => $plan->included_branches, 'extra_branch' => (float) $plan->additional_branch_price,
                    'features' => array_values(array_diff($extras, [Feature::UNLIMITED_USERS])),
                ];
            })
            ->sortBy([['monthly', 'asc'], ['order', 'asc']])->values();
    }

    /**
     * The extras the page lists: those in every plan ("core", not tickable) and those only some
     * plans have, with their price on their own and whether they can be bought that way.
     */
    public function extras(?Collection $tiers = null): array
    {
        $tiers ??= $this->tiers();
        if ($tiers->isEmpty()) return ['core' => [], 'optional' => [], 'storage' => null];

        $all = array_values(array_unique($tiers->pluck('features')->flatten()->all()));
        $core = array_values(array_intersect(...$tiers->pluck('features')->all()));
        $prices = PlatformAddon::all()->keyBy('feature');
        $item = fn (string $feature) => [
            'key' => $feature, 'label' => self::label($feature), 'group' => self::group($feature),
            'price' => (float) ($prices[$feature]->monthly_price ?? 0),
            'offered' => (bool) ($prices[$feature]->is_offered ?? false) && (float) ($prices[$feature]->monthly_price ?? 0) > 0,
            'from' => $tiers->first(fn ($tier) => in_array($feature, $tier['features'], true))['name'] ?? null,
        ];
        $order = array_merge(...array_values(self::GROUPS));
        $sort = fn (array $keys) => collect($keys)->sortBy(fn ($k) => ($i = array_search($k, $order, true)) === false ? 999 : $i)->values()->all();
        $pack = $prices[Feature::SCAN_STORAGE_PACK] ?? null;

        return [
            'core' => array_map($item, $sort($core)),
            'optional' => array_map($item, $sort(array_diff($all, $core))),
            'storage' => ['gb' => (int) ($pack->pack_size ?? PlanProduct::STORAGE_PACK_GB), 'price' => (float) ($pack->monthly_price ?? 0),
                'offered' => (bool) ($pack->is_offered ?? false) && (float) ($pack->monthly_price ?? 0) > 0],
        ];
    }

    /**
     * Quote a selection: features (keys), users, storage_mb (or storage_gb), visits (a day), branches.
     * Returns the best option, every plan's option (or why it can't), and the yearly figures.
     */
    public function quote(array $selection, ?Collection $tiers = null): array
    {
        $tiers ??= $this->tiers();
        $extras = collect($this->extras($tiers)['optional'])->keyBy('key');
        $storage = $this->extras($tiers)['storage'];
        $wanted = array_values(array_intersect((array) ($selection['features'] ?? []), $extras->keys()->all()));
        $users = max(1, (int) ($selection['users'] ?? 1));
        $mb = max(0, (int) ($selection['storage_mb'] ?? ((int) ($selection['storage_gb'] ?? 0)) * 1024));
        $visits = max(0, (int) ($selection['visits'] ?? 0));
        $branches = max(1, (int) ($selection['branches'] ?? 1));

        $options = $tiers->map(function (array $tier) use ($extras, $storage, $wanted, $users, $mb, $visits, $branches) {
            $lines = [];
            $why = null;
            if ($tier['max_visits'] !== null && $visits > $tier['max_visits']) $why = "Meant for up to {$tier['max_visits']} patients a day";
            if (! $why && $tier['users'] !== null && $users > $tier['users']) $why = "Up to {$tier['users']} users";
            foreach (array_diff($wanted, $tier['features']) as $feature) {
                if ($why) break;
                $extra = $extras[$feature];
                $extra['offered'] ? $lines[] = [$extra['label'], $extra['price']] : $why = "{$extra['label']} isn't in this plan";
            }
            $planMb = $tier['storage_mb'] === null ? null : (int) $tier['storage_mb'];
            // Clinical documents bought on a plan with no scan storage need somewhere to keep them: one pack at least.
            $needMb = in_array(Feature::CLINICAL_DOCUMENTS, $wanted, true) && $planMb === 0 ? max($mb, 1) : $mb;
            if (! $why && $planMb !== null && $needMb > $planMb) {
                $packs = (int) ceil(($needMb - $planMb) / (max(1, $storage['gb']) * 1024));
                $storage['offered'] ? $lines[] = ["Scan storage: {$packs} × {$storage['gb']} GB", $packs * $storage['price']] : $why = 'Not enough scan storage';
            }
            if (! $why && $tier['branches'] !== null && $branches > $tier['branches']) {
                $more = $branches - $tier['branches'];
                // A branch is billed like the plan, so yearly it gets the plan's yearly discount too.
                $yearly = $tier['monthly'] > 0 ? $tier['extra_branch'] * $tier['yearly'] / $tier['monthly'] : 12 * $tier['extra_branch'];
                $tier['extra_branch'] > 0 ? $lines[] = ["{$more} extra " . ($more === 1 ? 'branch' : 'branches'), $more * $tier['extra_branch'], round($more * $yearly, 2)] : $why = 'One branch only';
            }
            $addons = array_sum(array_column($lines, 1));
            // Add-ons are twelve months a year; a line with its own yearly figure (branches) uses that.
            $addonsYearly = array_sum(array_map(fn ($line) => $line[2] ?? 12 * $line[1], $lines));

            return ['family' => $tier['family'], 'id' => $tier['id'], 'name' => $tier['name'], 'ok' => $why === null, 'why' => $why,
                'plan' => $tier['monthly'], 'lines' => $lines, 'monthly' => round($tier['monthly'] + $addons, 2),
                'yearly' => round($tier['yearly'] + $addonsYearly, 2), 'currency' => $tier['currency']];
        })->values();

        // Cheapest wins; at the same price the bigger plan, which gives more for the money.
        $best = $options->where('ok', true)->sortBy(fn ($o, $i) => [$o['monthly'], -$i])->first();
        $starter = $options->first();

        return ['best' => $best, 'options' => $options->all(),
            'saving' => $best && $starter && $starter['ok'] && $starter['family'] !== $best['family'] ? round($starter['monthly'] - $best['monthly'], 2) : 0.0,
            'selection' => ['features' => $wanted, 'users' => $users, 'storage_mb' => $mb, 'visits' => $visits, 'branches' => $branches]];
    }

    public static function label(string $feature): string
    {
        return self::LABELS[$feature] ?? PlanProduct::EXTRAS[$feature][0] ?? ucwords(str_replace('_', ' ', $feature));
    }

    /** Labels as one run of text: "SMS reminders, daily sales email, clinic inventory" (acronyms kept). */
    /** Scan storage as people read it: "400 MB", "2 GB". */
    public static function storageLabel(int $mb): string
    {
        return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' GB' : $mb . ' MB';
    }

    /** The optional extras by page group, in the page's order: [group => [extra, ...]]. */
    public static function grouped(array $items): array
    {
        $order = array_keys(self::GROUPS);
        return collect($items)->groupBy('group')->sortBy(fn ($list, $group) => ($i = array_search($group, $order, true)) === false ? 99 : $i)
            ->map(fn ($list) => $list->values()->all())->all();
    }

    public static function sentence(array $features, string $glue = ', ', bool $lowerFirst = false): string
    {
        return collect(array_values($features))->map(function ($feature, $i) use ($lowerFirst) {
            $label = self::label($feature);

            return ($i > 0 || $lowerFirst) && ! ctype_upper(substr($label, 1, 1)) ? lcfirst($label) : $label;
        })->implode($glue);
    }

    private static function group(string $feature): string
    {
        foreach (self::GROUPS as $group => $features) if (in_array($feature, $features, true)) return $group;

        return 'More';
    }
}
