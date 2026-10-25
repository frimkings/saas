<?php

namespace App\Support\Pricing;

use App\Support\Feature;
use App\Support\PlanProduct;

/**
 * The four Clinical Suite plans sold on the public pricing page (Starter 100, Standard 180, Plus 250,
 * Pro 320, decided 2026-10-24), and the monthly prices of the extras when bought one at a time (the
 * add-on catalogue). `pricing:clinical-suite` creates them, or moves the plans on the page to these
 * definitions; after that the platform edits plans and prices on its own screens.
 *
 * Every plan has, without any feature key: patient registry and clearance, the whole consultation,
 * refraction and prescriptions (with previous medications), the diagnoses list, patient timeline,
 * the till, receipts, refunds and sales records, products and stock receiving, the daily cash
 * summary, users, settings, guided tours and imports from previous software.
 */
class ClinicalSuitePlans
{
    public const STARTER = [Feature::INVENTORY];

    public const STANDARD = [...self::STARTER,
        Feature::APPOINTMENTS, Feature::REFERRALS, Feature::CLINICAL_DOCUMENTS, Feature::SALES_EXPORT, Feature::DISCOUNTS,
        Feature::SALES_REPORTS_MONTH, Feature::INVENTORY_ALERTS, Feature::PRODUCT_IMPORT, Feature::PATIENT_MESSAGES,
        Feature::STAFF_MESSAGES, Feature::SMS_CAMPAIGNS, Feature::NEEDS_ATTENTION, Feature::DAILY_SUMMARY, Feature::CUSTOM_ROLES];

    public const PLUS = [...self::STANDARD,
        Feature::HISTORY_CHARTS, Feature::CLINICAL_PDFS, Feature::OUTSTANDING_BALANCES, Feature::QUOTATIONS, Feature::SPECTACLE_ORDERS,
        Feature::SALES_REPORTS, Feature::EXPENSE_TRACKING, Feature::CASH_BOOK, Feature::PURCHASING, Feature::BUSINESS_OVERVIEW,
        Feature::WEEKLY_SUMMARY, Feature::MONTHLY_SUMMARY, Feature::MORNING_ALERTS, Feature::ADVANCED_REPORTS];

    public const PRO = [...self::PLUS,
        Feature::SPECTACLES_PRO, Feature::PATIENT_LEDGER, Feature::APPROVALS, Feature::INSURANCE,
        Feature::AUDIT_TRAIL, Feature::UNLIMITED_USERS, Feature::PRIORITY_SUPPORT, Feature::GUIDED_ONBOARDING];

    /** Paying yearly costs twelve months (no free months) and adds these SMS credits to the clinic's wallet, by plan family. */
    public const YEARLY_SMS = ['clinic-starter' => 30, 'clinic-standard' => 300, 'clinic-plus' => 1000, 'clinic-pro' => 2000];

    /** Plans once sold on the page and no longer: retired by `pricing:clinical-suite`, kept for their clinics. */
    public const RETIRED = ['clinic-essential'];

    /**
     * family code => plan. `users` and `storage_mb` null = unlimited; storage 0 = no scans (storage packs still add some).
     * `max_visits` is the busiest clinic each plan is meant for (average patients a day), as guidance; null = no upper limit.
     * Every plan can add branches; each extra branch costs the same as the plan.
     */
    public static function plans(): array
    {
        return [
            'clinic-starter' => ['name' => 'Clinic Starter', 'price' => 100, 'users' => 2, 'storage_mb' => 20, 'extras' => self::STARTER,
                'max_visits' => 8, 'tagline' => 'Consultations, prescriptions and the till for a one-doctor clinic.'],
            'clinic-standard' => ['name' => 'Clinic Standard', 'price' => 180, 'users' => 4, 'storage_mb' => 150, 'extras' => self::STANDARD,
                'max_visits' => 25, 'tagline' => 'Appointments, SMS reminders, discounts and this month\'s sales.'],
            'clinic-plus' => ['name' => 'Clinic Plus', 'price' => 250, 'users' => 10, 'storage_mb' => 300, 'extras' => self::PLUS,
                'max_visits' => 50, 'tagline' => 'Full sales reports, expenses, cash book, day close and the income statement.', 'featured' => true],
            'clinic-pro' => ['name' => 'Clinic Pro', 'price' => 320, 'users' => null, 'storage_mb' => 2048, 'extras' => self::PRO,
                'max_visits' => null, 'tagline' => 'Insurance, approvals, audit trail and priority support with guided set-up.'],
        ];
    }

    /**
     * Monthly price of each extra bought on its own; storage is per 1 GB pack. One or two missing extras cost less
     * than the next plan (each step up is GHS 70-80); needing several makes the upgrade the better deal.
     */
    public const EXTRA_PRICES = [
        // Standard's extras
        Feature::APPOINTMENTS => 25, Feature::REFERRALS => 15, Feature::CLINICAL_DOCUMENTS => 15, Feature::SALES_EXPORT => 5,
        Feature::DISCOUNTS => 10, Feature::SALES_REPORTS_MONTH => 15, Feature::INVENTORY_ALERTS => 10, Feature::PRODUCT_IMPORT => 10,
        Feature::PATIENT_MESSAGES => 20, Feature::SMS_CAMPAIGNS => 20, Feature::STAFF_MESSAGES => 5, Feature::NEEDS_ATTENTION => 15,
        Feature::DAILY_SUMMARY => 10, Feature::CUSTOM_ROLES => 10,
        // Plus's extras
        Feature::HISTORY_CHARTS => 15, Feature::CLINICAL_PDFS => 10, Feature::OUTSTANDING_BALANCES => 15, Feature::QUOTATIONS => 10,
        Feature::SPECTACLE_ORDERS => 25, Feature::SALES_REPORTS => 25, Feature::EXPENSE_TRACKING => 25, Feature::CASH_BOOK => 30,
        Feature::PURCHASING => 25, Feature::BUSINESS_OVERVIEW => 15, Feature::WEEKLY_SUMMARY => 10, Feature::MONTHLY_SUMMARY => 10,
        Feature::MORNING_ALERTS => 15, Feature::ADVANCED_REPORTS => 30,
        // Pro's extras (priority support and onboarding come only with Pro)
        Feature::SPECTACLES_PRO => 30, Feature::PATIENT_LEDGER => 15, Feature::APPROVALS => 30,
        Feature::INSURANCE => 50, Feature::AUDIT_TRAIL => 25,
        Feature::SCAN_STORAGE_PACK => 20,
    ];

    /** The stored feature list for one of the plans. */
    public static function features(array $plan): array
    {
        return PlanProduct::features(PlanProduct::CLINIC, $plan['extras']);
    }
}
