<?php

namespace App\Support\Pricing;

use App\Support\Feature;
use App\Support\PlanProduct;

/**
 * The five Clinical Suite plans sold on the public pricing page, and the monthly prices of the
 * extras when bought one at a time (the add-on catalogue). `pricing:clinical-suite` creates them;
 * after that the platform edits plans and prices on its own screens.
 */
class ClinicalSuitePlans
{
    /** Every plan has these: the day-to-day running of a small clinic. */
    public const CORE = [Feature::APPOINTMENTS, Feature::OUTSTANDING_BALANCES, Feature::INVENTORY, Feature::SMS_CAMPAIGNS,
        Feature::DAILY_SUMMARY, Feature::UNLIMITED_USERS];

    /**
     * family code => plan. `users` and `storage_mb` null = unlimited; storage 0 = no scans (storage packs still add some).
     * `max_visits` is the busiest clinic each plan is meant for (average patients a day); null = no upper limit.
     * Every plan can add branches; each extra branch costs the same as the plan.
     */
    public static function plans(): array
    {
        $essential = [...self::CORE, Feature::REFERRALS, Feature::WEEKLY_SUMMARY];
        $standard = [...$essential, Feature::EXPENSE_TRACKING, Feature::AUDIT_TRAIL, Feature::MONTHLY_SUMMARY];
        $plus = [...$standard, Feature::MORNING_ALERTS, Feature::SPECTACLES_PRO, Feature::ADVANCED_REPORTS];
        $pro = [...$plus, Feature::APPROVALS];

        return [
            'clinic-starter' => ['name' => 'Clinic Starter', 'price' => 100, 'users' => 2, 'storage_mb' => 0, 'extras' => self::CORE,
                'max_visits' => 8, 'tagline' => 'Everything a small clinic needs to run the day.'],
            'clinic-essential' => ['name' => 'Clinic Essential', 'price' => 150, 'users' => 3, 'storage_mb' => 0, 'extras' => $essential,
                'max_visits' => 15, 'tagline' => 'Referral letters and a weekly sales email.'],
            'clinic-standard' => ['name' => 'Clinic Standard', 'price' => 180, 'users' => 4, 'storage_mb' => 2048, 'extras' => $standard,
                'max_visits' => 25, 'tagline' => 'Know where the money goes and who did what.', 'featured' => true],
            'clinic-plus' => ['name' => 'Clinic Plus', 'price' => 250, 'users' => null, 'storage_mb' => 5120, 'extras' => $plus,
                'max_visits' => 50, 'tagline' => 'Spectacle orders, morning alerts and the income statement.'],
            'clinic-pro' => ['name' => 'Clinic Pro', 'price' => 320, 'users' => null, 'storage_mb' => 10240, 'extras' => $pro,
                'max_visits' => null, 'tagline' => 'Approvals for voids and discounts, for busy clinics.'],
        ];
    }

    /** Monthly price of each extra bought on its own; storage is per 1 GB pack. */
    public const EXTRA_PRICES = [
        Feature::REFERRALS => 15, Feature::WEEKLY_SUMMARY => 10, Feature::MONTHLY_SUMMARY => 10,
        Feature::EXPENSE_TRACKING => 25, Feature::AUDIT_TRAIL => 25, Feature::MORNING_ALERTS => 15,
        Feature::SPECTACLES_PRO => 30, Feature::ADVANCED_REPORTS => 30, Feature::APPROVALS => 30,
        Feature::SCAN_STORAGE_PACK => 20,
    ];

    /** The stored feature list for one of the plans. */
    public static function features(array $plan): array
    {
        return PlanProduct::features(PlanProduct::CLINIC, $plan['extras']);
    }
}
